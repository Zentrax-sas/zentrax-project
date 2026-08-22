#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

const CAPACIDAD_CONTENEDOR_IDM = 1100;
const BATCH_SIZE = 500;

function mostrarUso(): void
{
    global $argv;
    fwrite(STDERR, "Uso: php importar_contenedores_idm.php /ruta/Contenedores_domiciliarios.csv [--ejecutar|--generar-sql]\n");
    fwrite(STDERR, "Si no se especifica modo, se genera un archivo SQL de revisión en base-datos/database/sql/seed_contenedores_idm.sql\n");
    exit(1);
}

function normalizarTexto(?string $valor): string
{
    if ($valor === null) {
        return '';
    }
    return trim((string) $valor);
}

function normalizarParaComparacion(string $valor): string
{
    $valor = trim($valor);
    $valor = strtolower($valor);

    if (function_exists('iconv')) {
        $valorOriginal = $valor;
        $valorConvertido = iconv('UTF-8', 'ASCII//TRANSLIT', $valorOriginal);
        if ($valorConvertido === false) {
            $valor = mb_convert_encoding($valorOriginal, 'UTF-8', 'UTF-8');
        } else {
            $valor = $valorConvertido;
        }
    }

    $valor = preg_replace('/[^a-z0-9]/i', '', $valor ?? '');
    return (string) ($valor ?? '');
}

function decodeCsvAsUtf8(string $path): string
{
    $contenido = file_get_contents($path);
    if ($contenido === false) {
        throw new RuntimeException("No se pudo leer el archivo CSV: {$path}");
    }

    $encodings = ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'latin1'];
    foreach ($encodings as $encoding) {
        $test = @mb_convert_encoding($contenido, 'UTF-8', $encoding);
        if ($test !== false && mb_detect_encoding($test, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'latin1'], true) !== false) {
            return $test;
        }
    }

    return mb_convert_encoding($contenido, 'UTF-8', 'ISO-8859-1');
}

function inferirZonaDesdeCircuito(string $codigoCircuito): string
{
    $codigo = trim($codigoCircuito);
    if ($codigo === '') {
        return 'Zona Desconocida';
    }

    $prefijo = strtoupper((string) strtok($codigo, '_'));
    $mapa = [
        'A' => 'Zona A',
        'B' => 'Zona B',
        'C' => 'Zona C',
        'CH' => 'Zona CH',
        'D' => 'Zona D',
        'E' => 'Zona E',
        'F' => 'Zona F',
        'G' => 'Zona G',
    ];

    return $mapa[$prefijo] ?? 'Zona Desconocida';
}

function utmToLatLon(float $x, float $y): array
{
    $zone = 21;
    $southernHemisphere = true;

    $a = 6378137.0;
    $f = 1 / 298.257223563;
    $e2 = $f * (2 - $f);
    $ePrime2 = $e2 / (1 - $e2);
    $k0 = 0.9996;

    $xAdjusted = $x - 500000.0;
    $yAdjusted = $southernHemisphere ? $y - 10000000.0 : $y;

    $m = $yAdjusted / $k0;
    $mu = $m / ($a * (1 - $e2 / 4 - 3 * ($e2 ** 2) / 64 - 5 * ($e2 ** 3) / 256));
    $e1 = (1 - sqrt(1 - $e2)) / (1 + sqrt(1 - $e2));

    $phi1 = $mu
        + (3 * $e1 / 2 - 27 * ($e1 ** 3) / 32) * sin(2 * $mu)
        + (21 * ($e1 ** 2) / 16 - 55 * ($e1 ** 4) / 32) * sin(4 * $mu)
        + (151 * ($e1 ** 3) / 96) * sin(6 * $mu)
        + (1097 * ($e1 ** 4) / 512) * sin(8 * $mu);

    $c1 = $ePrime2 * cos($phi1) ** 2;
    $t1 = tan($phi1) ** 2;
    $n1 = $a / sqrt(1 - $e2 * sin($phi1) ** 2);
    $r1 = $a * (1 - $e2) / ((1 - $e2 * sin($phi1) ** 2) ** 1.5);
    $d = $xAdjusted / ($n1 * $k0);

    $latRad = $phi1 - (($n1 * tan($phi1) / $r1) * (
        ($d ** 2) / 2
        - (5 + 3 * $t1 + 10 * $c1 - 4 * $c1 ** 2 - 9 * $ePrime2) * ($d ** 4) / 24
        + (61 + 90 * $t1 + 298 * $c1 + 45 * $t1 ** 2 - 252 * $ePrime2 - 3 * $c1 ** 2) * ($d ** 6) / 720
    ));

    $lonRad = (
        $d
        - (1 + 2 * $t1 + $c1) * ($d ** 3) / 6
        + (5 - 2 * $c1 + 28 * $t1 - 3 * $c1 ** 2 + 8 * $ePrime2 + 24 * $t1 ** 2) * ($d ** 5) / 120
    ) / cos($phi1);

    $lonDeg = rad2deg($lonRad) + (($zone * 6) - 183);
    $latDeg = rad2deg($latRad);

    return ['lat' => $latDeg, 'lon' => $lonDeg];
}

function validarReferenciaUTM(): void
{
    $casos = [
        ['X' => 569167.409189918, 'Y' => 6144930.92890772, 'lat' => -34.8355663, 'lon' => -56.2435326],
        ['X' => 566459.492523661, 'Y' => 6143405.71838873, 'lat' => -34.8494996, 'lon' => -56.2730254],
    ];

    foreach ($casos as $caso) {
        $resultado = utmToLatLon((float) $caso['X'], (float) $caso['Y']);
        $diffLat = abs($resultado['lat'] - (float) $caso['lat']);
        $diffLon = abs($resultado['lon'] - (float) $caso['lon']);

        if ($diffLat > 0.0001 || $diffLon > 0.0001) {
            throw new RuntimeException(
                "La conversión UTM->lat/lon no coincide con el caso de referencia esperado. " .
                json_encode(['esperado' => ['lat' => $caso['lat'], 'lon' => $caso['lon']], 'obtenido' => $resultado, 'diferencia' => ['lat' => $diffLat, 'lon' => $diffLon]])
            );
        }
    }
}

function getTipoResiduoOrganicoId(PDO $db): int
{
    $query = 'SELECT id_tipo_residuo, nombre FROM tipo_residuo ORDER BY id_tipo_residuo ASC';
    $stmt = $db->query($query);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $candidatos = [];
    foreach ($rows as $fila) {
        $nombre = (string) ($fila['nombre'] ?? '');
        $candidatos[] = trim($nombre);
    }

    foreach ($rows as $fila) {
        $nombre = (string) ($fila['nombre'] ?? '');
        $normalizado = normalizarParaComparacion($nombre);

        if (
            $normalizado === 'organico'
            || $normalizado === 'organico1'
            || str_contains(strtolower(trim($nombre)), 'organico')
            || str_contains(strtolower(trim($nombre)), 'orgánico')
            || (str_contains($normalizado, 'org') && str_contains($normalizado, 'nico'))
        ) {
            return (int) $fila['id_tipo_residuo'];
        }
    }

    $lista = implode(', ', array_map(static fn ($valor) => trim((string) $valor), $candidatos));
    throw new RuntimeException(
        "No se encontró el tipo de residuo 'Orgánico' en la tabla tipo_residuo — verificá que init.sql se haya ejecutado y revisá los valores actuales: {$lista}."
    );
}

function crearRutaMap(PDO $db, array $rutas): array
{
    if ($rutas === []) {
        return [];
    }

    $placeholders = [];
    $params = [];
    foreach ($rutas as $index => $ruta) {
        $placeholders[] = '(?, ?)';
        $params[] = $ruta['nombre'];
        $params[] = $ruta['zona'];
    }

    $sql = 'INSERT INTO ruta (nombre, zona) VALUES ' . implode(', ', $placeholders);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $nombres = array_map(static fn (array $ruta): string => $ruta['nombre'], $rutas);
    $in = implode(',', array_fill(0, count($nombres), '?'));
    $query = 'SELECT id_ruta, nombre FROM ruta WHERE nombre IN (' . $in . ') ORDER BY id_ruta ASC';
    $stmt = $db->prepare($query);
    $stmt->execute($nombres);

    $mapa = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $mapa[(string) $fila['nombre']] = (int) $fila['id_ruta'];
    }

    return $mapa;
}

function crearInserSQLContenedores(array $contenedores): string
{
    $rows = [];
    foreach ($contenedores as $contenedor) {
        $rows[] = sprintf(
            "(%s, %s, '%s', %s, %s, '%s', %d, %d)",
            $contenedor['codigo_sql'],
            $contenedor['capacidad_sql'],
            str_replace("'", "\\'", $contenedor['direccion']),
            $contenedor['lat_sql'],
            $contenedor['lon_sql'],
            $contenedor['estado'],
            (int) $contenedor['id_tipo_residuo'],
            (int) $contenedor['id_ruta']
        );
    }

    return 'INSERT INTO contenedor (codigo, capacidad, direccion, latitud, longitud, estado, id_tipo_residuo, id_ruta) VALUES ' . implode(', ', $rows) . ';';
}

function cargarCSV(string $csvPath): array
{
    $contenido = decodeCsvAsUtf8($csvPath);
    $tempPath = tempnam(sys_get_temp_dir(), 'idm_');
    if ($tempPath === false) {
        throw new RuntimeException('No se pudo crear un archivo temporal para procesar el CSV.');
    }

    file_put_contents($tempPath, $contenido);
    $handle = fopen($tempPath, 'rb');
    if ($handle === false) {
        unlink($tempPath);
        throw new RuntimeException('No se pudo abrir el CSV temporal para lectura.');
    }

    $header = fgetcsv($handle, 0, ';');
    if ($header === false || $header === []) {
        fclose($handle);
        unlink($tempPath);
        throw new RuntimeException('El CSV no tiene cabecera válida.');
    }

    $header = array_map(static fn ($col) => trim((string) $col), $header);
    $filas = [];
    $linea = 0;

    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        $linea++;
        if ($row === [null] || count($row) === 0) {
            continue;
        }

        $map = [];
        foreach ($header as $index => $name) {
            $map[$name] = $row[$index] ?? '';
        }
        $filas[] = $map;
    }

    fclose($handle);
    unlink($tempPath);

    return $filas;
}

function evaluarMotivo(string $motivo): string
{
    $m = normalizarTexto($motivo);
    if ($m === '') {
        return 'Disponible';
    }

    $norm = normalizarParaComparacion($m);
    if ($norm === 'mantenimiento' || $norm === 'sininstalar') {
        return 'Fuera de Servicio';
    }

    if ($norm !== '') {
        echo "[AVISO] MOTIVO no previsto: '{$motivo}' -> se toma como 'Disponible' por defecto.\n";
    }

    return 'Disponible';
}

function generarSqlSalida(array $rutas, array $contenedores): string
{
    $lineas = [
        '-- Fuente: Intendencia de Montevideo / Contenedores domiciliarios',
        '-- Fecha de importación: ' . date('Y-m-d H:i:s'),
        '-- Supuestos documentados: capacidad fija 1100L para todos, tipo de residuo Orgánico, dirección placeholder, mapeo MOTIVO -> estado según normativa del proyecto.',
        'START TRANSACTION;',
    ];

    if ($rutas !== []) {
        $lineas[] = 'INSERT INTO ruta (nombre, zona) VALUES';
        $routeSql = [];
        foreach ($rutas as $ruta) {
            $routeSql[] = sprintf("('%s', '%s')", str_replace("'", "\\'", $ruta['nombre']), str_replace("'", "\\'", $ruta['zona']));
        }
        $lineas[] = implode(', ', $routeSql) . ';';
    }

    $contenedorSql = [];
    foreach ($contenedores as $contenedor) {
        $contenedorSql[] = sprintf(
            "('%s', %s, '%s', %s, %s, '%s', %d, %d)",
            str_replace("'", "\\'", $contenedor['codigo']),
            $contenedor['capacidad'],
            str_replace("'", "\\'", $contenedor['direccion']),
            $contenedor['latitud'],
            $contenedor['longitud'],
            $contenedor['estado'],
            (int) $contenedor['id_tipo_residuo'],
            (int) $contenedor['id_ruta']
        );
    }

    if ($contenedorSql !== []) {
        $lineas[] = 'INSERT INTO contenedor (codigo, capacidad, direccion, latitud, longitud, estado, id_tipo_residuo, id_ruta) VALUES';
        $lineas[] = implode(', ', array_chunk($contenedorSql, 500)[0] ?? $contenedorSql) . ';';
    }

    $lineas[] = 'COMMIT;';

    return implode("\n", $lineas) . "\n";
}

function main(): void
{
    $args = $_SERVER['argv'] ?? [];
    if (count($args) < 2) {
        mostrarUso();
    }

    $csvPath = $args[1];
    $modo = 'sql';
    for ($i = 2; $i < count($args); $i++) {
        if ($args[$i] === '--ejecutar') {
            $modo = 'ejecutar';
        } elseif ($args[$i] === '--generar-sql') {
            $modo = 'sql';
        }
    }

    if (!is_file($csvPath)) {
        fwrite(STDERR, "Archivo CSV no encontrado: {$csvPath}\n");
        exit(1);
    }

    try {
        validarReferenciaUTM();

        $filas = cargarCSV($csvPath);
        $rutasUnicas = [];
        $rutaOrden = [];
        foreach ($filas as $fila) {
            $codigoCircuito = normalizarTexto($fila['COD_CIRCUITO'] ?? '');
            if ($codigoCircuito === '') {
                continue;
            }
            if (!isset($rutasUnicas[$codigoCircuito])) {
                $rutasUnicas[$codigoCircuito] = [
                    'nombre' => $codigoCircuito,
                    'zona' => inferirZonaDesdeCircuito($codigoCircuito),
                ];
                $rutaOrden[] = $codigoCircuito;
            }
        }

        $db = (new Database())->getConnection();
        if ($db === null) {
            throw new RuntimeException('No se pudo conectar a la base de datos. Verificá la configuración de .env / database.php.');
        }

        $idTipoResiduo = getTipoResiduoOrganicoId($db);

        $totalRutas = count($rutasUnicas);
        $totalContenedores = count($filas);
        $fueraServicio = 0;

        if ($modo === 'ejecutar') {
            $mapaRutas = crearRutaMap($db, array_values($rutasUnicas));
            $db->beginTransaction();
            $batch = [];
            $contador = 0;

            foreach ($filas as $index => $fila) {
                $gId = normalizarTexto($fila['GID'] ?? '');
                $codigoCircuito = normalizarTexto($fila['COD_CIRCUITO'] ?? '');
                $x = (float) str_replace(',', '.', normalizarTexto($fila['X'] ?? '0'));
                $y = (float) str_replace(',', '.', normalizarTexto($fila['Y'] ?? '0'));
                $motivo = normalizarTexto($fila['MOTIVO'] ?? '');
                $coordenadas = utmToLatLon($x, $y);
                $estado = evaluarMotivo($motivo);
                if ($estado !== 'Disponible') {
                    $fueraServicio++;
                }

                $batch[] = [
                    'codigo' => 'IDM-' . $gId,
                    'capacidad' => CAPACIDAD_CONTENEDOR_IDM,
                    'direccion' => sprintf('Ubicación importada IdM (sin dirección catastral) — X: %s, Y: %s', $x, $y),
                    'latitud' => $coordenadas['lat'],
                    'longitud' => $coordenadas['lon'],
                    'estado' => $estado,
                    'id_tipo_residuo' => $idTipoResiduo,
                    'id_ruta' => $mapaRutas[$codigoCircuito] ?? 0,
                ];

                if (count($batch) >= BATCH_SIZE) {
                    $contador += count($batch);
                    $sql = 'INSERT INTO contenedor (codigo, capacidad, direccion, latitud, longitud, estado, id_tipo_residuo, id_ruta) VALUES ';
                    $values = [];
                    $params = [];
                    foreach ($batch as $registro) {
                        $values[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                        $params = array_merge($params, [
                            $registro['codigo'],
                            $registro['capacidad'],
                            $registro['direccion'],
                            $registro['latitud'],
                            $registro['longitud'],
                            $registro['estado'],
                            $registro['id_tipo_residuo'],
                            $registro['id_ruta'],
                        ]);
                    }
                    $db->prepare($sql . implode(', ', $values))->execute($params);
                    echo "[PROGRESO] Insertados {$contador} contenedores.\n";
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $contador += count($batch);
                $sql = 'INSERT INTO contenedor (codigo, capacidad, direccion, latitud, longitud, estado, id_tipo_residuo, id_ruta) VALUES ';
                $values = [];
                $params = [];
                foreach ($batch as $registro) {
                    $values[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                    $params = array_merge($params, [
                        $registro['codigo'],
                        $registro['capacidad'],
                        $registro['direccion'],
                        $registro['latitud'],
                        $registro['longitud'],
                        $registro['estado'],
                        $registro['id_tipo_residuo'],
                        $registro['id_ruta'],
                    ]);
                }
                $db->prepare($sql . implode(', ', $values))->execute($params);
                echo "[PROGRESO] Insertados {$contador} contenedores.\n";
            }

            $db->commit();
            echo "[RESUMEN] Rutas: {$totalRutas}; Contenedores procesados: {$totalContenedores}; Estado != Disponible: {$fueraServicio}.\n";
            return;
        }

        $outputPath = __DIR__ . '/../../base-datos/database/sql/seed_contenedores_idm.sql';
        $contenedorSqlRows = [];
        foreach ($filas as $fila) {
            $codigoCircuito = normalizarTexto($fila['COD_CIRCUITO'] ?? '');
            $gId = normalizarTexto($fila['GID'] ?? '');
            $x = (float) str_replace(',', '.', normalizarTexto($fila['X'] ?? '0'));
            $y = (float) str_replace(',', '.', normalizarTexto($fila['Y'] ?? '0'));
            $motivo = normalizarTexto($fila['MOTIVO'] ?? '');
            $coord = utmToLatLon($x, $y);
            $estado = evaluarMotivo($motivo);
            if ($estado !== 'Disponible') {
                $fueraServicio++;
            }

            $contenedorSqlRows[] = [
                'codigo' => 'IDM-' . $gId,
                'capacidad' => CAPACIDAD_CONTENEDOR_IDM,
                'direccion' => sprintf('Ubicación importada IdM (sin dirección catastral) — X: %s, Y: %s', $x, $y),
                'latitud' => $coord['lat'],
                'longitud' => $coord['lon'],
                'estado' => $estado,
                'id_tipo_residuo' => $idTipoResiduo,
                'id_ruta' => 0,
                'codigo_circuito' => $codigoCircuito,
            ];
        }

        $rutaMapId = [];
        $rutaSql = [];
        $indiceRuta = 1;
        foreach ($rutasUnicas as $codigo => $ruta) {
            $rutaMapId[$codigo] = $indiceRuta;
            $rutaSql[] = [
                'nombre' => $ruta['nombre'],
                'zona' => $ruta['zona'],
            ];
            $indiceRuta++;
        }

        foreach ($contenedorSqlRows as &$fila) {
            $fila['id_ruta'] = $rutaMapId[$fila['codigo_circuito']] ?? 0;
        }
        unset($fila);

        $sqlContenido = "-- Fuente: Intendencia de Montevideo / Contenedores domiciliarios\n";
        $sqlContenido .= "-- Fecha de importación: " . date('Y-m-d H:i:s') . "\n";
        $sqlContenido .= "-- Supuestos documentados: capacidad fija 1100L para todos, tipo de residuo Orgánico, dirección placeholder, mapeo MOTIVO -> estado según normativa del proyecto.\n\n";
        $sqlContenido .= "START TRANSACTION;\n\n";

        $routeInserts = [];
        foreach ($rutaSql as $ruta) {
            $routeInserts[] = sprintf("INSERT INTO ruta (nombre, zona) VALUES ('%s', '%s');",
                str_replace("'", "\\'", $ruta['nombre']),
                str_replace("'", "\\'", $ruta['zona'])
            );
        }
        $sqlContenido .= implode("\n", $routeInserts) . "\n\n";

        $chunked = array_chunk($contenedorSqlRows, 500);
        foreach ($chunked as $chunk) {
            $values = [];
            foreach ($chunk as $registro) {
                $values[] = sprintf(
                    "('%s', %s, '%s', %s, %s, '%s', %d, %d)",
                    str_replace("'", "\\'", $registro['codigo']),
                    $registro['capacidad'],
                    str_replace("'", "\\'", $registro['direccion']),
                    $registro['latitud'],
                    $registro['longitud'],
                    $registro['estado'],
                    (int) $registro['id_tipo_residuo'],
                    (int) $registro['id_ruta']
                );
            }
            $sqlContenido .= "INSERT INTO contenedor (codigo, capacidad, direccion, latitud, longitud, estado, id_tipo_residuo, id_ruta) VALUES\n";
            $sqlContenido .= implode(",\n", $values) . ";\n\n";
        }
        $sqlContenido .= "COMMIT;\n";

        file_put_contents($outputPath, $sqlContenido);
        echo "[ARCHIVO] SQL generado en {$outputPath}\n";
        echo "[RESUMEN] Rutas: {$totalRutas}; Contenedores procesados: {$totalContenedores}; Estado != Disponible: {$fueraServicio}.\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
        exit(1);
    }
}

main();
