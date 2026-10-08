<?php
require_once __DIR__ . '/RecoleccionOperativa.php';

/** Administración F6.2. No utiliza el CRUD legacy ni modifica recursos V19. */
final class SolicitudOperativa
{
    public const STATES = ['Pendiente', 'Programada', 'En atención', 'Finalizada', 'Cancelada'];
    public function __construct(private PDO $db) {}
    private function lock(bool $locking): string { return $locking && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''; }
    private function rows(string $sql, array $args = [], bool $locking = false): array {
        $s = $this->db->prepare($sql . $this->lock($locking)); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    private function write(string $sql, array $args): void { $s = $this->db->prepare($sql); $s->execute($args); }
    public static function now(): string { return (new DateTimeImmutable('now', new DateTimeZone('America/Montevideo')))->format('Y-m-d H:i:s'); }
    private function fail(string $message, int $code = 409): never { throw new DomainException($message, $code); }
    public function authorize(int $actor, bool $modify = false, bool $locking = false): void {
        $u = $this->rows('SELECT activo FROM usuario WHERE id_usuario=?', [$actor], $locking)[0] ?? null;
        if (!$u || $u['activo'] !== 'Activo') $this->fail('Sesión inválida o cuenta inactiva.', 401);
        $permissions = $this->permissions($actor, $locking);
        if (!in_array('solicitud.consultar', $permissions, true) || ($modify && !in_array('solicitud.modificar', $permissions, true))) $this->fail('No tenés permisos vigentes en Operaciones.', 403);
    }
    private function permissions(int $user, bool $locking): array {
        $date = substr(self::now(), 0, 10);
        return array_column($this->rows("SELECT p.nombre FROM usuario_rol ur JOIN rol_permiso rp ON rp.id_rol=ur.id_rol JOIN permiso p ON p.id_permiso=rp.id_permiso
            WHERE ur.id_usuario=? AND ur.sector='OPERACIONES' AND ur.fecha_desde<=? AND (ur.fecha_hasta IS NULL OR ur.fecha_hasta>=?)", [$user, $date, $date], $locking), 'nombre');
    }
    public function list(int $actor, array $filters): array {
        $this->authorize($actor); $where = []; $args = [];
        foreach (['estado', 'tipo_solicitud'] as $field) if (isset($filters[$field])) {
            if ($filters[$field] === 'Cerradas') $where[] = "estado IN ('Finalizada','Cancelada')";
            else { $where[] = "$field=?"; $args[] = $filters[$field]; }
        }
        if (isset($filters['desde'])) { $where[] = 'fecha>=?'; $args[] = $filters['desde'] . ' 00:00:00'; }
        if (isset($filters['hasta'])) { $where[] = 'fecha<?'; $args[] = (new DateTimeImmutable($filters['hasta']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00'; }
        $page = $filters['page']; $limit = $filters['limit']; $offset = ($page - 1) * $limit;
        $rows = $this->rows('SELECT id_solicitud,tracking_number,fecha,estado,tipo_solicitud,id_tipo_residuo,id_cuadrilla,fecha_confirmacion_residuo FROM solicitud' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY fecha DESC,id_solicitud DESC LIMIT " . ($limit + 1) . " OFFSET $offset", $args);
        foreach ($rows as &$r) { $r['historica_programada_sin_intento'] = $this->historical($r); $r['requiere_confirmacion'] = $r['fecha_confirmacion_residuo'] === null; } unset($r);
        return ['items' => array_slice($rows, 0, $limit), 'page' => $page, 'limit' => $limit, 'has_more' => count($rows) > $limit];
    }
    private function historical(array $s): bool {
        return $s['estado'] === 'Programada' && !$this->rows('SELECT id_atencion_solicitud FROM atencion_solicitud WHERE id_solicitud=? LIMIT 1', [$s['id_solicitud']]);
    }
    private function snapshot(int $id, bool $locking = false): array {
        $s = $this->rows('SELECT * FROM solicitud WHERE id_solicitud=?', [$id], $locking)[0] ?? null;
        if (!$s) $this->fail('Solicitud no encontrada.', 404);
        $attempts = $this->rows('SELECT * FROM atencion_solicitud WHERE id_solicitud=? ORDER BY id_atencion_solicitud DESC', [$id], $locking);
        $open = array_values(array_filter($attempts, fn($a) => $a['fecha_cierre'] === null));
        if (count($open) > 1) $this->fail('Historial operativo inconsistente.');
        $a = $open[0] ?? null;
        $version = hash('sha256', json_encode([$s, $attempts], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return [$s, $a, $attempts, $version];
    }
    private function coherent(array $s, ?array $a, array $attempts): bool {
        if ($a) return (int)$s['id_cuadrilla'] === (int)$a['id_cuadrilla'] && (($s['estado'] === 'Programada' && in_array($a['estado'], ['Asignada','Aceptada'], true)) || ($s['estado'] === 'En atención' && $a['estado'] === 'En atención'));
        return $s['id_cuadrilla'] === null && ($s['estado'] === 'Pendiente' || ($s['estado'] === 'Programada' && !$attempts));
    }
    public function detail(int $actor, int $id, int $page = 1): array {
        $this->authorize($actor); [$s, $a, $attempts, $version] = $this->snapshot($id);
        $modify = true; try { $this->authorize($actor, true); } catch (DomainException $e) { if ($e->getCode() !== 403) throw $e; $modify = false; }
        $editable = $modify && $this->coherent($s, $a, $attempts) && in_array($s['estado'], ['Pendiente','Programada'], true);
        $s['tipo_residuo'] = $this->rows('SELECT nombre FROM tipo_residuo WHERE id_tipo_residuo=?', [$s['id_tipo_residuo']])[0]['nombre'] ?? null;
        return ['solicitud' => $s, 'intento_actual' => $a, 'historial' => array_slice($attempts, ($page-1)*20, 20), 'historial_page' => $page, 'historial_has_more' => count($attempts) > $page*20,
            'historica_programada_sin_intento' => $s['estado'] === 'Programada' && !$attempts,
            'estado_esperado' => $s['estado'], 'id_atencion_esperada' => $a ? (int)$a['id_atencion_solicitud'] : null, 'version_esperada' => $version,
            'capacidades' => ['consultar'=>true, 'modificar'=>$modify, 'confirmar_residuo'=>$editable && !$a, 'asignar'=>$editable && !$a && $s['fecha_confirmacion_residuo'] !== null,
                'reasignar'=>$editable && $a !== null, 'desasignar'=>$editable && $s['estado'] === 'Programada', 'cancelar'=>$editable],
            'motivo_bloqueo' => !$modify ? 'Acceso de solo lectura.' : (!$editable ? 'Estado terminal, atención iniciada o contexto operativo inconsistente.' : null)];
    }
    private function candidate(array $s, int $squad, int $assignment, int $use, bool $locking): array {
        if ($s['fecha_confirmacion_residuo'] === null) $this->fail('Confirmá el residuo antes de asignar.');
        $op = new RecoleccionOperativa($this->db);
        try { $option = $op->validarOpcionF3($squad, $assignment, null, $use, $locking); }
        catch (DomainException $e) { if ($e->getCode() === 400) $this->fail('La opción no corresponde a una utilización APOYO.'); throw $e; }
        if ($option['funcion_operativa'] !== 'APOYO') $this->fail('La solicitud requiere un vehículo APOYO.');
        $v19 = $this->rows('SELECT * FROM asignacion_vehiculo_operativa WHERE id_asignacion_vehiculo=?', [$assignment], $locking)[0] ?? null;
        $v = $this->rows('SELECT id_tipo_residuo FROM vehiculo WHERE id_vehiculo=?', [$option['id_vehiculo']], $locking)[0] ?? null;
        if (!$v19 || $v19['fecha_fin'] !== null || $v19['fecha_inicio'] > self::now() || (int)$v['id_tipo_residuo'] !== (int)$s['id_tipo_residuo']) $this->fail('Utilización no vigente o residuo incompatible.');
        $members = $this->rows('SELECT id_usuario FROM usuario_cuadrilla WHERE id_cuadrilla=? AND fecha_fin IS NULL AND fecha_inicio<=? ORDER BY id_usuario', [$squad, self::now()], $locking);
        $eligible = false;
        foreach ($members as $m) if ($op->elegibilidad((int)$m['id_usuario'], $locking)['elegible'] && in_array('solicitud.operar', $this->permissions((int)$m['id_usuario'], $locking), true)) $eligible = true;
        if (!$eligible) $this->fail('Sin integrantes vigentes habilitados para operar solicitudes.');
        return $option;
    }
    public function options(int $actor, int $id): array {
        $this->authorize($actor, true); [$s, $a, $attempts] = $this->snapshot($id);
        if (!$this->coherent($s, $a, $attempts) || !in_array($s['estado'], ['Pendiente','Programada'], true)) $this->fail('La solicitud no admite asignación.');
        if ($s['fecha_confirmacion_residuo'] === null) return ['items'=>[], 'motivo'=>'Confirmá el residuo antes de asignar.'];
        $items = [];
        foreach ((new RecoleccionOperativa($this->db))->opcionesIncidencia() as $o) {
            if ($o['funcion_operativa'] !== 'APOYO') continue;
            try { $items[] = $this->candidate($s, (int)$o['id_cuadrilla'], (int)$o['id_asignacion_vehiculo'], (int)$o['id_usa'], false); }
            catch (DomainException $e) { if (!in_array($e->getCode(), [404,409], true)) throw $e; }
        }
        return ['items'=>$items, 'motivo'=>$items ? null : 'No hay utilizaciones APOYO vigentes y compatibles con integrantes habilitados.'];
    }
    public function mutate(int $actor, array $b): array {
        $this->db->beginTransaction();
        try {
            [$s, $a, $attempts, $version] = $this->snapshot((int)$b['id_solicitud'], true);
            $this->authorize($actor, true, true);
            if ($b['estado_esperado'] !== $s['estado'] || $b['version_esperada'] !== $version || $b['id_atencion_esperada'] !== ($a ? (int)$a['id_atencion_solicitud'] : null)) $this->fail('La solicitud cambió. Volvé a consultar.');
            if (!in_array($s['estado'], ['Pendiente','Programada'], true) || !$this->coherent($s, $a, $attempts)) $this->fail('La solicitud no admite esta operación.');
            $action = $b['accion']; $id = (int)$s['id_solicitud'];
            if ($action === 'confirmar_residuo') {
                if ($a) $this->fail('No se puede clasificar con un intento abierto.');
                if (!$this->rows('SELECT id_tipo_residuo FROM tipo_residuo WHERE id_tipo_residuo=?', [$b['id_tipo_residuo']], true)) $this->fail('Residuo inexistente.', 400);
            } elseif ($action === 'asignar' || $action === 'reasignar') {
                if (($action === 'asignar' && $a) || ($action === 'reasignar' && !$a)) $this->fail('La acción no corresponde al intento actual.');
                if ($a && (int)$a['id_cuadrilla'] === $b['id_cuadrilla'] && (int)$a['id_asignacion_vehiculo'] === $b['id_asignacion_vehiculo']) $this->fail('Seleccioná un destino diferente.');
                $this->candidate($s, $b['id_cuadrilla'], $b['id_asignacion_vehiculo'], $b['id_usa'], true);
            } elseif ($action === 'desasignar' && $s['estado'] !== 'Programada') $this->fail('Solo se puede desasignar una Programada.');
            $now = self::now();
            if ($now < $s['fecha'] || ($a && $now < ($a['fecha_inicio'] ?? $a['fecha_aceptacion'] ?? $a['fecha_asignacion']))) $this->fail('Cronología incompatible con el reloj del servidor.');
            if ($action === 'confirmar_residuo') $this->write('UPDATE solicitud SET id_tipo_residuo=?,fecha_confirmacion_residuo=?,id_usuario_confirma_residuo=? WHERE id_solicitud=?', [$b['id_tipo_residuo'],$now,$actor,$id]);
            else {
                if ($a) $this->write("UPDATE atencion_solicitud SET estado='Interrumpida',fecha_cierre=?,id_usuario_cierra=?,motivo_cierre=? WHERE id_atencion_solicitud=?", [$now,$actor,$b['motivo'], $a['id_atencion_solicitud']]);
                if ($action === 'asignar' || $action === 'reasignar') {
                    $this->write("INSERT INTO atencion_solicitud(id_solicitud,id_cuadrilla,id_asignacion_vehiculo,estado,fecha_asignacion,id_usuario_asigna) VALUES(?,?,?,'Asignada',?,?)", [$id,$b['id_cuadrilla'],$b['id_asignacion_vehiculo'],$now,$actor]);
                    $this->write("UPDATE solicitud SET estado='Programada',id_cuadrilla=? WHERE id_solicitud=?", [$b['id_cuadrilla'],$id]);
                } elseif ($action === 'desasignar') $this->write("UPDATE solicitud SET estado='Pendiente',id_cuadrilla=NULL WHERE id_solicitud=?", [$id]);
                else $this->write("UPDATE solicitud SET estado='Cancelada',id_cuadrilla=NULL,fecha_cancelacion=?,id_usuario_cancela=?,motivo_cancelacion=? WHERE id_solicitud=?", [$now,$actor,$b['motivo'],$id]);
            }
            // Construir respuesta dentro de la misma transacción; nunca fallar después del commit.
            $result = $this->detail($actor, $id); $this->db->commit(); return $result;
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
}
