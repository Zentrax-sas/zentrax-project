<?php
declare(strict_types=1);

// CLI solamente. --dry-run solo lee: no consulta Nominatim ni escribe cache o contenedor.
// Nominatim: https://operations.osmfoundation.org/policies/nominatim/
// Una maquina, proceso secuencial, uso puntual; no programar lotes recurrentes a 1 req/s.
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/models/Geocodificacion.php';

function opcionesEnriquecimiento(array $args): array {
    $options = ['limit' => 20, 'start-id' => 1, 'dry-run' => false];
    for ($i = 1; $i < count($args); $i++) {
        $arg = $args[$i];
        if ($arg === '--dry-run') { $options['dry-run'] = true; continue; }
        if (!preg_match('/^--(limit|start-id)(?:=(.*))?$/', $arg, $m)) {
            throw new InvalidArgumentException('Uso: php enriquecer_direcciones_contenedores.php [--limit=20] [--start-id=1] [--dry-run]');
        }
        $value = $m[2] ?? ($args[++$i] ?? '');
        if (!ctype_digit($value) || (float) $value < 1 || (float) $value > PHP_INT_MAX) {
            throw new InvalidArgumentException('limit y start-id deben ser enteros positivos.');
        }
        $options[$m[1]] = (int) $value;
    }
    return $options;
}

function enriquecerDirecciones(PDO $db, Geocodificacion $geo, array $options, string $backupDir): array {
    // Solo el prefijo importado IdM; conservar direccion original completa.
    $placeholder = 'Ubicación importada IdM (sin dirección catastral)%';
    $select = $db->prepare('SELECT id_contenedor,codigo,direccion,latitud,longitud FROM contenedor
        WHERE direccion LIKE :placeholder AND latitud IS NOT NULL AND longitud IS NOT NULL
                    AND latitud <> 0 AND longitud <> 0
                    AND latitud BETWEEN -90 AND 90 AND longitud BETWEEN -180 AND 180
          AND id_contenedor >= :start ORDER BY id_contenedor ASC LIMIT :limit');
    $select->bindValue(':placeholder', $placeholder, PDO::PARAM_STR);
    $select->bindValue(':start', $options['start-id'], PDO::PARAM_INT);
    $select->bindValue(':limit', $options['limit'], PDO::PARAM_INT);
    $select->execute();
    $rows = $select->fetchAll(PDO::FETCH_ASSOC);
    $summary = ['dry_run' => $options['dry-run'], 'selected' => count($rows), 'processed' => 0,
        'cache' => 0, 'external_rows' => 0, 'external_requests' => 0, 'valid_street' => 0,
        'without_street' => 0, 'requires_api' => 0, 'updated' => 0, 'conflicts' => 0, 'skipped_changed' => 0, 'backup' => null,
        'next_start_id' => $options['start-id'], 'error' => null, 'rows' => []];
    $plan = [];
    foreach ($rows as $row) {
        $direccionOriginal = $row['direccion'];
        if ($options['dry-run']) {
            $cache = $geo->findCache((int) $row['id_contenedor']);
            $data = $cache ? Geocodificacion::normalizarCache($cache) : ['direccion' => null];
            $result = ['source' => $data['direccion'] !== null ? 'cache' : 'not_queried',
                'data' => $data, 'requests' => 0, 'error' => null];
        } else {
            $result = $geo->resolver($row);
        }
        $summary['processed']++;
        $summary['cache'] += $result['source'] === 'cache' ? 1 : 0;
        $summary['external_rows'] += $result['requests'] > 0 ? 1 : 0;
        $summary['external_requests'] += $result['requests'];
        $address = $result['data']['direccion'];
        if (!is_string($address) || trim($address) === ''
            || str_starts_with(mb_strtolower(trim($address), 'UTF-8'), 'ubicación importada idm (sin dirección catastral)')
            || mb_strtolower(trim($address), 'UTF-8') === 'sin dirección catastral') $address = null;
        $summary['valid_street'] += $address !== null ? 1 : 0;
        $requiresApi = $options['dry-run'] && $address === null;
        $summary['requires_api'] += $requiresApi ? 1 : 0;
        $summary['without_street'] += $address === null && !$requiresApi ? 1 : 0;
        $summary['rows'][] = ['id_contenedor' => $row['id_contenedor'], 'codigo' => $row['codigo'],
            'original' => $direccionOriginal, 'lat' => $row['latitud'], 'lon' => $row['longitud'],
            'direccion' => $address, 'source' => $result['source'] === 'external' ? 'api' : $result['source'],
            'action' => $requiresApi ? 'would_query_api' : ($address === null ? 'skipped_no_street' : ($options['dry-run'] ? 'would_update' : 'pending_update')),
            'error' => $result['error']];
        $summary['next_start_id'] = (int) $row['id_contenedor'] + 1;
        if ($address !== null) $plan[] = ['original' => $row, 'direccion' => $address, 'index' => count($summary['rows']) - 1];
        // Un error del proveedor/cache detiene el lote; no insistir contra un bloqueo.
        if ($result['error'] !== null) {
            $summary['error'] = $result['error'];
            $summary['next_start_id'] = (int) $row['id_contenedor'];
            break;
        }
    }
    if ($options['dry-run'] || !$plan) return $summary;

    $db->beginTransaction();
    try {
        $locked = $db->prepare('SELECT id_contenedor,codigo,direccion,latitud,longitud FROM contenedor WHERE id_contenedor=:id FOR UPDATE');
        $eligible = [];
        foreach ($plan as $change) {
            $locked->execute([':id' => $change['original']['id_contenedor']]);
            // No actualizar si cambiaron direccion, codigo o coordenadas mientras se consultaba.
            if ($locked->fetch(PDO::FETCH_ASSOC) !== $change['original']) {
                $summary['conflicts']++; $summary['skipped_changed']++;
                $summary['rows'][$change['index']]['action'] = 'skipped_changed';
                continue;
            }
            $eligible[] = $change;
        }
        if (!$eligible) { $db->rollBack(); return $summary; }
        if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true)) throw new RuntimeException('No se pudo crear directorio de backup');
        $backup = $backupDir . '/contenedores_direccion_reverse_backup.sql';
        // Nunca sobrescribir backups anteriores al continuar con otro lote.
        if (file_exists($backup)) $backup = $backupDir . '/contenedores_direccion_reverse_backup_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.sql';
        $sql = "-- Restore puntual. Datos de geocodificacion: OpenStreetMap contributors (ODbL).\nSET NAMES utf8mb4;\nSTART TRANSACTION;\n";
        foreach ($eligible as $change) {
            $row = $change['original']; $id = (int) $row['id_contenedor'];
            $code = bin2hex($row['codigo']); $old = bin2hex($row['direccion']); $new = bin2hex($change['direccion']);
            $sql .= '-- ' . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            $sql .= "UPDATE contenedor SET direccion=CONVERT(0x$old USING utf8mb4) WHERE id_contenedor=$id AND BINARY codigo=0x$code AND BINARY direccion=0x$new;\n";
        }
        $sql .= "COMMIT;\n";
        $file = fopen($backup, 'x');
        if (!$file) throw new RuntimeException('No se pudo abrir backup exclusivo');
        try {
            if (fwrite($file, $sql) !== strlen($sql) || !fflush($file) || !fsync($file)) throw new RuntimeException('Backup incompleto');
        } finally { fclose($file); }
        if (file_get_contents($backup) !== $sql) throw new RuntimeException('No se pudo verificar backup');
        $summary['backup'] = $backup;
        $update = $db->prepare('UPDATE contenedor SET direccion=:direccion_nueva
            WHERE id_contenedor=:id AND BINARY direccion=:direccion_original');
        foreach ($eligible as $change) {
            $update->execute([':direccion_nueva' => $change['direccion'], ':id' => $change['original']['id_contenedor'],
                ':direccion_original' => $change['original']['direccion']]);
            if ($update->rowCount() === 0) {
                $summary['conflicts']++; $summary['skipped_changed']++;
                $summary['rows'][$change['index']]['action'] = 'skipped_changed';
                continue;
            }
            if ($update->rowCount() !== 1) throw new RuntimeException('Cantidad de filas inesperada');
            $summary['updated']++;
            $summary['rows'][$change['index']]['action'] = 'updated';
        }
        $db->commit();
        return $summary;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $options = opcionesEnriquecimiento($argv);
        $db = (new Database())->getConnection();
        if (!$db) throw new RuntimeException('No se pudo conectar a la base');
        if ((int) $db->query("SELECT GET_LOCK('zemyna_enriquecimiento_lote', 0)")->fetchColumn() !== 1) {
            throw new RuntimeException('Ya hay otro lote en ejecucion');
        }
        try {
            $summary = enriquecerDirecciones($db, new Geocodificacion($db), $options, __DIR__ . '/../backups');
        } finally { $db->query("SELECT RELEASE_LOCK('zemyna_enriquecimiento_lote')"); }
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit($summary['error'] === null ? 0 : 2);
    } catch (Throwable $error) {
        fwrite(STDERR, 'Error: ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
