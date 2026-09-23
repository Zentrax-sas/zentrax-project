<?php
require_once __DIR__ . '/Recoleccion.php';

/** Cambios atómicos: usuario -> cuadrilla -> recorrido. Nunca usa identidad del cuerpo. */
class RecoleccionOperativa extends Recoleccion
{
    private function now(): string { return (new DateTimeImmutable('now', new DateTimeZone('America/Montevideo')))->format('Y-m-d H:i:s'); }
    private function lock(): string { return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''; }
    private function execute(string $sql, array $params): void { $this->db->prepare($sql)->execute($params); }
    private function fail(int $status, string $message): void { throw new DomainException($message, $status); }
    private function transaction(callable $fn): array
    {
        $this->db->beginTransaction();
        try { $result = $fn(); $this->db->commit(); return $result; }
        catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    private function lockUser(int $id): void
    {
        $user = $this->rows('SELECT activo FROM usuario WHERE id_usuario = ?' . $this->lock(), [$id])[0] ?? null;
        if (!$user || $user['activo'] !== 'Activo') $this->fail(404, 'Usuario activo no encontrado.');
    }
    public function elegibilidad(int $id): array
    {
        $user = $this->rows('SELECT activo FROM usuario WHERE id_usuario = ?', [$id])[0] ?? null;
        if (!$user || $user['activo'] !== 'Activo') return ['elegible' => false, 'motivo' => 'Cuenta inactiva'];
        $date = substr($this->now(), 0, 10);
        $assignments = $this->rows('SELECT sector, fecha_desde, fecha_hasta FROM usuario_rol WHERE id_usuario = ?', [$id]);
        $current = array_values(array_filter($assignments, fn($a) => $a['fecha_desde'] <= $date && ($a['fecha_hasta'] === null || $a['fecha_hasta'] >= $date)));
        if (!$current) return ['elegible' => false, 'motivo' => $assignments ? 'Asignación vencida o aún no vigente' : 'Sin asignación de rol'];
        if (!in_array('OPERACIONES', array_column($current, 'sector'), true)) {
            $sectors = array_map(fn($s) => ucfirst(strtolower(str_replace('_', ' ', $s))), array_unique(array_column($current, 'sector')));
            return ['elegible' => false, 'motivo' => 'Sector ' . implode(', ', $sectors)];
        }
        $permissions = array_column($this->rows("SELECT DISTINCT p.nombre FROM usuario_rol ur
            JOIN rol_permiso rp ON rp.id_rol = ur.id_rol JOIN permiso p ON p.id_permiso = rp.id_permiso
            WHERE ur.id_usuario = ? AND ur.sector = 'OPERACIONES'
            AND ur.fecha_desde <= ? AND (ur.fecha_hasta IS NULL OR ur.fecha_hasta >= ?)
            AND p.nombre IN ('recorrido.consultar', 'recorrido.operar', 'recorrido.modificar')", [$id, $date, $date]), 'nombre');
        if (!in_array('recorrido.consultar', $permissions, true)) return ['elegible' => false, 'motivo' => 'Sin permiso para consultar recorridos'];
        if (!array_intersect(['recorrido.operar', 'recorrido.modificar'], $permissions)) return ['elegible' => false, 'motivo' => 'Sin permiso para operar recorridos'];
        return ['elegible' => true, 'motivo' => 'Permisos operativos vigentes en Operaciones'];
    }
    public function elegible(int $id): bool { return $this->elegibilidad($id)['elegible']; }
    public function pertenencia(int $id): ?array
    {
        return $this->rows('SELECT uc.id_usuario_cuadrilla, uc.id_cuadrilla, uc.fecha_inicio, c.nombre, c.turno
            FROM usuario_cuadrilla uc JOIN cuadrilla c ON c.id_cuadrilla = uc.id_cuadrilla
            WHERE uc.id_usuario = ? AND uc.fecha_fin IS NULL', [$id])[0] ?? null;
    }
    public function integrantes(int $squad): array
    {
        if (!$this->rows('SELECT id_cuadrilla FROM cuadrilla WHERE id_cuadrilla = ?', [$squad])) $this->fail(404, 'Cuadrilla no encontrada.');
        $history = $this->rows('SELECT uc.id_usuario_cuadrilla, uc.id_usuario, u.nombre, u.apellido, uc.fecha_inicio, uc.fecha_fin,
            uc.id_usuario_asigna, uc.id_usuario_finaliza, asignador.nombre AS asignador_nombre, asignador.apellido AS asignador_apellido,
            finalizador.nombre AS finalizador_nombre, finalizador.apellido AS finalizador_apellido
            FROM usuario_cuadrilla uc JOIN usuario u ON u.id_usuario = uc.id_usuario
            LEFT JOIN usuario asignador ON asignador.id_usuario = uc.id_usuario_asigna
            LEFT JOIN usuario finalizador ON finalizador.id_usuario = uc.id_usuario_finaliza
            WHERE uc.id_cuadrilla = ? ORDER BY uc.fecha_inicio DESC, uc.id_usuario_cuadrilla DESC', [$squad]);
        $eligible = []; $excluded = [];
        foreach ($this->rows("SELECT id_usuario, nombre, apellido FROM usuario ORDER BY nombre, id_usuario", []) as $user) {
            $eligibility = $this->elegibilidad((int) $user['id_usuario']);
            if ($eligibility['elegible']) { $user['pertenencia'] = $this->pertenencia((int) $user['id_usuario']); $eligible[] = $user; }
            else $excluded[] = ['nombre' => $user['nombre'], 'apellido' => $user['apellido'], 'motivo' => $eligibility['motivo']];
        }
        return ['historial' => $history, 'elegibles' => $eligible, 'no_elegibles' => $excluded];
    }
    public function asignar(int $actor, int $target, ?int $squad, string $action, ?int $membership): array
    {
        return $this->transaction(function () use ($actor, $target, $squad, $action, $membership) {
            $ids = array_unique([$actor, $target]); sort($ids);
            foreach ($ids as $id) $this->rows('SELECT id_usuario FROM usuario WHERE id_usuario = ?' . $this->lock(), [$id]);
            $this->lockUser($actor);
            $current = $this->pertenencia($target);
            if ($action === 'finalizar_pertenencia') {
                if (!$current || (int) $current['id_usuario_cuadrilla'] !== $membership) $this->fail(409, 'La pertenencia ya cambió o finalizó.');
            } else {
                $this->lockUser($target);
                if (!$this->elegible($target)) $this->fail(400, 'El usuario no tiene permisos operativos vigentes en OPERACIONES.');
                if (!$this->rows('SELECT id_cuadrilla FROM cuadrilla WHERE id_cuadrilla = ?' . $this->lock(), [$squad])) $this->fail(404, 'Cuadrilla no encontrada.');
                if ($action === 'asignar' && $current) $this->fail(409, 'Ya existe una pertenencia vigente. Usá trasladar.');
                if ($action === 'trasladar' && (!$current || (int) $current['id_usuario_cuadrilla'] !== $membership)) $this->fail(409, 'La pertenencia cambió. Volvé a consultar.');
                if ($current && (int) $current['id_cuadrilla'] === $squad) $this->fail(409, 'La persona ya pertenece a esa cuadrilla.');
            }
            $now = $this->now();
            if ($current) $this->execute('UPDATE usuario_cuadrilla SET fecha_fin = ?, id_usuario_finaliza = ? WHERE id_usuario_cuadrilla = ?', [$now, $actor, $current['id_usuario_cuadrilla']]);
            if ($action !== 'finalizar_pertenencia') $this->execute('INSERT INTO usuario_cuadrilla (id_usuario, id_cuadrilla, fecha_inicio, id_usuario_asigna) VALUES (?, ?, ?, ?)', [$target, $squad, $now, $actor]);
            return ['pertenencia' => $this->pertenencia($target)];
        });
    }
    /** Solo ejecuciones pendientes sin participación ni actividad previa. No reinterpreta historial. */
    public function asignables(int $squad, int $page): array
    {
        if (!$this->rows('SELECT id_cuadrilla FROM cuadrilla WHERE id_cuadrilla = ?', [$squad])) $this->fail(404, 'Cuadrilla no encontrada.');
        $where = "re.estado = 'Pendiente' AND re.fecha_fin IS NULL AND re.id_usuario_inicio IS NULL AND re.id_usuario_fin IS NULL
            AND NOT EXISTS (SELECT 1 FROM participa p WHERE p.id_recorrido = re.id_recorrido)
            AND NOT EXISTS (SELECT 1 FROM atencion_contenedor a WHERE a.id_recorrido = re.id_recorrido)";
        $total = (int) $this->rows('SELECT COUNT(*) AS total FROM recorrido re WHERE ' . $where, [])[0]['total'];
        $stmt = $this->db->prepare('SELECT re.id_recorrido, re.estado, re.fecha_inicio, re.fecha_fin, r.nombre AS ruta_nombre, r.zona
            FROM recorrido re JOIN ruta r ON r.id_ruta = re.id_ruta WHERE ' . $where . ' ORDER BY re.fecha_inicio, re.id_recorrido LIMIT ? OFFSET ?');
        $stmt->bindValue(1, 25, PDO::PARAM_INT); $stmt->bindValue(2, ($page - 1) * 25, PDO::PARAM_INT); $stmt->execute();
        $vehicles = $this->rows("SELECT u.id_usa, v.id_vehiculo, v.matricula, v.estado FROM usa u JOIN vehiculo v ON v.id_vehiculo = u.id_vehiculo
            WHERE u.id_cuadrilla = ? AND v.activo = 1 AND v.estado <> 'En Mantenimiento' ORDER BY v.matricula, u.id_usa", [$squad]);
        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'page_size' => 25,
            'vehiculos' => $vehicles, 'conflicto' => $this->proximo($squad) !== null ? 'La cuadrilla ya tiene un recorrido Pendiente o En Proceso.' : null];
    }
    public function asignarRecorrido(int $actor, int $squad, int $trip, int $use): array
    {
        return $this->transaction(function () use ($actor, $squad, $trip, $use) {
            $this->lockUser($actor);
            if (!$this->rows('SELECT id_cuadrilla FROM cuadrilla WHERE id_cuadrilla = ?' . $this->lock(), [$squad])) $this->fail(404, 'Cuadrilla no encontrada.');
            $row = $this->rows('SELECT * FROM recorrido WHERE id_recorrido = ?' . $this->lock(), [$trip])[0] ?? null;
            if (!$row) $this->fail(404, 'Recorrido no encontrado.');
            if ($row['estado'] !== 'Pendiente' || $row['fecha_fin'] !== null || $row['id_usuario_inicio'] !== null || $row['id_usuario_fin'] !== null) $this->fail(409, 'Solo se pueden asignar recorridos Pendientes sin actividad previa.');
            if ($this->rows('SELECT id_participa FROM participa WHERE id_recorrido = ?' . $this->lock(), [$trip]) || $this->rows('SELECT id_atencion_contenedor FROM atencion_contenedor WHERE id_recorrido = ?', [$trip])) $this->fail(409, 'El recorrido ya tiene una asignación o historial. No se puede duplicar ni compartir.');
            if ($this->proximo($squad) !== null) $this->fail(409, 'La cuadrilla ya tiene un recorrido Pendiente o En Proceso.');
            $vehicle = $this->rows('SELECT v.id_vehiculo, v.activo, v.estado FROM usa u JOIN vehiculo v ON v.id_vehiculo = u.id_vehiculo
                WHERE u.id_usa = ? AND u.id_cuadrilla = ?' . $this->lock(), [$use, $squad])[0] ?? null;
            if (!$vehicle) $this->fail(404, 'Vehículo no relacionado con la cuadrilla seleccionada.');
            if (!(int) $vehicle['activo'] || $vehicle['estado'] === 'En Mantenimiento') $this->fail(409, 'El vehículo no está disponible para operar.');
            if ($this->rows("SELECT re.id_recorrido FROM recorrido re JOIN participa p ON p.id_recorrido = re.id_recorrido
                JOIN usa u ON u.id_usa = p.id_usa WHERE u.id_vehiculo = ? AND re.estado IN ('Pendiente','En Proceso')" . $this->lock(), [$vehicle['id_vehiculo']])) $this->fail(409, 'El vehículo ya participa en un recorrido operativo.');
            // En una ejecución Pendiente, hora_inicio es la hora prevista de fecha_inicio.
            // La hora real de operación se registra en recorrido al iniciar, sin reescribir participa.
            $this->execute('INSERT INTO participa (id_usa, id_recorrido, hora_inicio) VALUES (?, ?, ?)', [$use, $trip, substr($row['fecha_inicio'], 11, 8)]);
            return ['recorrido' => $this->detalle($squad, $trip)];
        });
    }
    private function scoped(int $squad, int $trip, bool $locking = false): array
    {
        $row = $this->rows('SELECT re.*, r.nombre AS ruta_nombre, r.zona FROM recorrido re JOIN ruta r ON r.id_ruta = re.id_ruta
            WHERE re.id_recorrido = ? AND EXISTS (SELECT 1 FROM participa p JOIN usa u ON u.id_usa = p.id_usa
            WHERE p.id_recorrido = re.id_recorrido AND u.id_cuadrilla = ?)' . ($locking ? $this->lock() : ''), [$trip, $squad])[0] ?? null;
        if (!$row) $this->fail(404, 'Recorrido no accesible para esta cuadrilla.');
        return $row;
    }
    public function detalle(int $squad, int $trip): array
    {
        $row = $this->scoped($squad, $trip);
        $row['vehiculos'] = $this->rows('SELECT DISTINCT v.id_vehiculo, v.matricula, v.estado, v.activo FROM vehiculo v
            JOIN usa u ON u.id_vehiculo = v.id_vehiculo JOIN participa p ON p.id_usa = u.id_usa
            WHERE p.id_recorrido = ? AND u.id_cuadrilla = ?', [$trip, $squad]);
        $row['contenedores'] = $this->rows('SELECT c.id_contenedor, c.codigo, c.direccion, c.latitud, c.longitud, c.activo,
            a.fecha_atencion, a.id_usuario AS id_usuario_atencion, au.nombre AS autor_nombre, au.apellido AS autor_apellido
            FROM contenedor c LEFT JOIN atencion_contenedor a ON a.id_contenedor = c.id_contenedor AND a.id_recorrido = ?
            LEFT JOIN usuario au ON au.id_usuario = a.id_usuario
            WHERE (c.id_ruta = ? AND c.activo = 1) OR a.id_atencion_contenedor IS NOT NULL ORDER BY c.id_contenedor', [$trip, $row['id_ruta']]);
        $attended = count(array_filter($row['contenedores'], fn($c) => $c['fecha_atencion'] !== null));
        $row['progreso'] = ['total' => count($row['contenedores']), 'atendidos' => $attended, 'pendientes' => count($row['contenedores']) - $attended];
        $row['autores'] = $this->rows('SELECT id_usuario, nombre, apellido FROM usuario WHERE id_usuario IN (?, ?)', [$row['id_usuario_inicio'], $row['id_usuario_fin']]);
        return $row;
    }
    public function compartido(int $trip): bool
    {
        return count($this->rows('SELECT DISTINCT u.id_cuadrilla FROM participa p JOIN usa u ON u.id_usa = p.id_usa WHERE p.id_recorrido = ?', [$trip])) > 1;
    }
    public function proximo(int $squad): ?int
    {
        $candidate = $this->rows("SELECT re.id_recorrido FROM recorrido re WHERE re.estado IN ('Pendiente','En Proceso')
                AND EXISTS (SELECT 1 FROM participa p JOIN usa u ON u.id_usa = p.id_usa WHERE p.id_recorrido = re.id_recorrido AND u.id_cuadrilla = ?)
                ORDER BY CASE WHEN re.estado = 'En Proceso' THEN 0 ELSE 1 END, re.fecha_inicio, re.id_recorrido LIMIT 1", [$squad])[0] ?? null;
        return $candidate ? (int) $candidate['id_recorrido'] : null;
    }
    public function resumen(int $squad): array
    {
        $count = $this->rows('SELECT COUNT(*) AS total FROM usuario_cuadrilla WHERE id_cuadrilla = ? AND fecha_fin IS NULL', [$squad])[0]['total'];
        $trip = $this->proximo($squad);
        return ['integrantes_activos' => (int) $count, 'vehiculos' => $this->vehiculosCuadrilla($squad), 'recorrido_actual' => $trip ? $this->detalle($squad, $trip) : null];
    }
    public function propia(int $user, ?int $trip): array
    {
        $member = $this->pertenencia($user);
        if (!$member) $this->fail(409, 'No tenés pertenencia vigente a una cuadrilla.');
        if ($trip === null) {
            $trip = $this->proximo((int) $member['id_cuadrilla']);
        }
        return ['pertenencia' => $member, 'recorrido' => $trip ? $this->detalle((int) $member['id_cuadrilla'], $trip) : null];
    }
    public function operar(int $user, int $trip, string $action, ?int $container): array
    {
        return $this->transaction(function () use ($user, $trip, $action, $container) {
            $this->lockUser($user);
            if (!$this->elegible($user)) $this->fail(403, 'No tenés permisos operativos vigentes en OPERACIONES.');
            $member = $this->pertenencia($user);
            if (!$member) $this->fail(409, 'No tenés pertenencia vigente a una cuadrilla.');
            $squad = (int) $member['id_cuadrilla'];
            $this->rows('SELECT id_cuadrilla FROM cuadrilla WHERE id_cuadrilla = ?' . $this->lock(), [$squad]);
            $row = $this->scoped($squad, $trip, true);
            $squads = $this->rows('SELECT DISTINCT u.id_cuadrilla FROM participa p JOIN usa u ON u.id_usa = p.id_usa WHERE p.id_recorrido = ?', [$trip]);
            if (count($squads) !== 1) $this->fail(409, 'El recorrido tiene varias cuadrillas: requiere una asignación inequívoca para operar.');
            $now = $this->now();
            if ($action === 'iniciar') {
                if ($row['estado'] !== 'Pendiente') $this->fail(409, 'Solo se puede iniciar un recorrido Pendiente.');
                if ($this->rows("SELECT re.id_recorrido FROM recorrido re JOIN participa p ON p.id_recorrido = re.id_recorrido
                    JOIN usa u ON u.id_usa = p.id_usa WHERE u.id_cuadrilla = ? AND re.estado = 'En Proceso'", [$squad])) $this->fail(409, 'La cuadrilla ya tiene un recorrido En Proceso.');
                $vehicles = $this->detalle($squad, $trip)['vehiculos'];
                foreach ($vehicles as $v) if (!(int) $v['activo'] || $v['estado'] === 'En Mantenimiento') $this->fail(409, 'El vehículo relacionado no está disponible para operar.');
                $this->execute("UPDATE recorrido SET estado = 'En Proceso', fecha_inicio = ?, fecha_fin = NULL, id_usuario_inicio = ? WHERE id_recorrido = ?", [$now, $user, $trip]);
            } else {
                if ($row['estado'] !== 'En Proceso') $this->fail(409, 'El recorrido debe estar En Proceso.');
                if ($action === 'atender') {
                    if (!$this->rows('SELECT id_contenedor FROM contenedor WHERE id_contenedor = ? AND id_ruta = ? AND activo = 1' . $this->lock(), [$container, $row['id_ruta']])) $this->fail(404, 'Contenedor activo no accesible en la ruta.');
                    if ($this->rows('SELECT id_atencion_contenedor FROM atencion_contenedor WHERE id_recorrido = ? AND id_contenedor = ?', [$trip, $container])) $this->fail(409, 'El contenedor ya fue atendido en este recorrido.');
                    $this->execute('INSERT INTO atencion_contenedor (id_recorrido, id_contenedor, id_usuario, fecha_atencion) VALUES (?, ?, ?, ?)', [$trip, $container, $user, $now]);
                } else {
                    if ($now < $row['fecha_inicio']) $this->fail(409, 'La fecha de inicio registrada es futura.');
                    $this->execute("UPDATE recorrido SET estado = 'Finalizado', fecha_fin = ?, id_usuario_fin = ? WHERE id_recorrido = ?", [$now, $user, $trip]);
                }
            }
            return $this->propia($user, $trip);
        });
    }
}
