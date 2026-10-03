<?php
require_once __DIR__ . '/RecoleccionOperativa.php';

/** Utilizacion temporal. Nunca crea usa ni modifica participa o atenciones. */
class AsignacionVehiculo
{
    public function __construct(private PDO $db) {}
    private function lock(): string { return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''; }
    private function rows(string $sql, array $args = []): array {
        $s = $this->db->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    private function write(string $sql, array $args): void { $this->db->prepare($sql)->execute($args); }
    private function fail(string $message, int $status = 409): void { throw new DomainException($message, $status); }
    public function authorize(int $actor, bool $write, bool $locking = false): void {
        $lock = $locking ? $this->lock() : '';
        $u = $this->rows('SELECT activo FROM usuario WHERE id_usuario=?' . $lock, [$actor])[0] ?? null;
        if (!$u || $u['activo'] !== 'Activo') $this->fail('Usuario de sesion inactivo.', 401);
        $date = (new DateTimeImmutable('now', new DateTimeZone('America/Montevideo')))->format('Y-m-d');
        $permissions = array_column($this->rows("SELECT p.nombre FROM usuario_rol ur JOIN rol_permiso rp ON rp.id_rol=ur.id_rol
            JOIN permiso p ON p.id_permiso=rp.id_permiso WHERE ur.id_usuario=? AND ur.sector='OPERACIONES'
            AND ur.fecha_desde<=? AND (ur.fecha_hasta IS NULL OR ur.fecha_hasta>=?)" . $lock, [$actor,$date,$date]), 'nombre');
        if (!in_array('asignacion_vehiculo.consultar', $permissions, true) || ($write && !in_array('asignacion_vehiculo.modificar', $permissions, true))) {
            $this->fail('No tenes permisos vigentes para gestionar utilizacion en Operaciones.', 403);
        }
    }
    public function catalog(int $actor): array {
        $this->authorize($actor, false);
        $modify = true;
        try { $this->authorize($actor, true); } catch (DomainException $e) { if ($e->getCode() !== 403) throw $e; $modify = false; }
        return ['cuadrillas'=>$this->rows('SELECT id_cuadrilla,nombre FROM cuadrilla ORDER BY nombre,id_cuadrilla'), 'puede_modificar'=>$modify];
    }
    public function detail(int $actor, int $squad, int $page = 1): array {
        $this->authorize($actor, false);
        $group = $this->rows('SELECT id_cuadrilla,nombre FROM cuadrilla WHERE id_cuadrilla=?', [$squad])[0] ?? null;
        if (!$group) $this->fail('Cuadrilla no encontrada.', 404);
        $select = 'SELECT a.id_asignacion_vehiculo,a.id_cuadrilla,a.id_vehiculo,a.fecha_inicio,a.fecha_fin,
            a.id_usuario_asigna,a.id_usuario_finaliza,a.motivo_cierre,v.matricula,v.funcion_operativa,v.estado,v.activo
            FROM asignacion_vehiculo_operativa a JOIN vehiculo v ON v.id_vehiculo=a.id_vehiculo WHERE a.id_cuadrilla=?';
        $current = $this->rows($select . ' AND a.fecha_fin IS NULL', [$squad]);
        if (count($current)>1) $this->fail('Utilizacion ambigua. Requiere revision administrativa.');
        $offset = ($page-1)*20;
        $history = $this->rows($select . " ORDER BY a.id_asignacion_vehiculo DESC LIMIT 21 OFFSET $offset", [$squad]);
        return ['cuadrilla'=>$group,'actual'=>$current[0] ?? null,'historial'=>array_slice($history,0,20),
            'page'=>$page,'has_more'=>count($history)>20,
            'vehiculos'=>$this->rows('SELECT v.id_vehiculo,v.matricula,v.funcion_operativa,v.estado,v.activo,COUNT(*) autorizaciones
                FROM usa u JOIN vehiculo v ON v.id_vehiculo=u.id_vehiculo WHERE u.id_cuadrilla=?
                GROUP BY v.id_vehiculo,v.matricula,v.funcion_operativa,v.estado,v.activo ORDER BY v.matricula', [$squad])];
    }
    /** Usar dentro de la transaccion de recorridos, despues de bloquear el vehiculo. */
    public function assertPair(int $squad, int $vehicle): void {
        $rows = $this->rows('SELECT id_cuadrilla,id_vehiculo FROM asignacion_vehiculo_operativa
            WHERE fecha_fin IS NULL AND (id_cuadrilla=? OR id_vehiculo=?)' . $this->lock(), [$squad,$vehicle]);
        foreach ($rows as $row) if ((int)$row['id_cuadrilla']!==$squad || (int)$row['id_vehiculo']!==$vehicle) {
            $this->fail('El recorrido contradice una utilizacion operativa abierta.');
        }
    }
    /** El CRUD bloquea primero vehiculo, igual que apertura/cambio. */
    public function protectVehicle(int $vehicle, ?string $function, ?string $state, bool $deactivate): void {
        $v = $this->rows('SELECT funcion_operativa FROM vehiculo WHERE id_vehiculo=?' . $this->lock(), [$vehicle])[0] ?? null;
        if (!$v) return;
        $open = $this->rows('SELECT id_asignacion_vehiculo FROM asignacion_vehiculo_operativa WHERE id_vehiculo=? AND fecha_fin IS NULL' . $this->lock(), [$vehicle]);
        if ($open && ($deactivate || $state==='En Mantenimiento' || $function!==$v['funcion_operativa'])) {
            $this->fail('Cerra o cambia primero la utilizacion operativa del vehiculo.');
        }
    }
    public function mutate(int $actor, string $action, int $squad, ?int $vehicle, ?int $expected, ?string $reason): array {
        $this->db->beginTransaction();
        try {
            // Usuarios (actor e integrantes), cuadrilla, version vigente y recursos.
            $members = $this->rows('SELECT id_usuario FROM usuario_cuadrilla WHERE id_cuadrilla=? AND fecha_fin IS NULL', [$squad]);
            $ids = array_unique(array_merge([$actor], array_map('intval', array_column($members,'id_usuario')))); sort($ids);
            foreach ($ids as $id) $this->rows('SELECT id_usuario FROM usuario WHERE id_usuario=?' . $this->lock(), [$id]);
            $this->authorize($actor, true, true);
            $eligible = false;
            $op = new RecoleccionOperativa($this->db);
            foreach ($members as $member) if ($op->elegibilidad((int)$member['id_usuario'], true)['elegible']) {
                if ($this->rows('SELECT id_usuario FROM usuario_cuadrilla WHERE id_usuario=? AND id_cuadrilla=? AND fecha_fin IS NULL' . $this->lock(), [$member['id_usuario'],$squad])) $eligible=true;
            }
            if (!$this->rows('SELECT id_cuadrilla FROM cuadrilla WHERE id_cuadrilla=?' . $this->lock(), [$squad])) $this->fail('Cuadrilla no encontrada.',404);
            // Lectura bloqueante: no usar una instantanea anterior a la espera.
            $current = $this->rows('SELECT * FROM asignacion_vehiculo_operativa WHERE id_cuadrilla=? AND fecha_fin IS NULL' . $this->lock(), [$squad])[0] ?? null;
            if ($action==='abrir' && $current) $this->fail('La cuadrilla ya tiene una utilizacion abierta.');
            if ($action!=='abrir' && (!$current || (int)$current['id_asignacion_vehiculo']!==$expected)) $this->fail('La utilizacion cambio o ya se cerro.');
            if ($action==='cambiar' && (int)$current['id_vehiculo']===$vehicle) $this->fail('Selecciona un vehiculo diferente.');
            if ($action!=='cerrar') {
                if (!$eligible) $this->fail('La cuadrilla no tiene integrantes operativamente elegibles.');
                $trips = $this->rows("SELECT re.id_recorrido,u.id_cuadrilla,u.id_vehiculo,p.hora_fin FROM recorrido re
                    JOIN participa p ON p.id_recorrido=re.id_recorrido JOIN usa u ON u.id_usa=p.id_usa
                    WHERE re.estado IN ('Pendiente','En Proceso') AND (u.id_cuadrilla=? OR u.id_vehiculo=?)" . $this->lock(), [$squad,$vehicle]);
                if (count($trips)>1) $this->fail('Hay recorridos operativos ambiguos o multiples.');
                foreach ($trips as $trip) {
                    if ((int)$trip['id_cuadrilla']!==$squad || (int)$trip['id_vehiculo']!==$vehicle || $trip['hora_fin']!==null) $this->fail('La utilizacion contradice un recorrido operativo.');
                    $links = $this->rows('SELECT id_participa FROM participa WHERE id_recorrido=?' . $this->lock(), [$trip['id_recorrido']]);
                    if (count($links)!==1) $this->fail('El recorrido tiene una participacion ambigua.');
                }
                $v = $this->rows('SELECT activo,estado,funcion_operativa FROM vehiculo WHERE id_vehiculo=?' . $this->lock(), [$vehicle])[0] ?? null;
                if (!$v) $this->fail('Vehiculo no encontrado.',404);
                // Una asignacion de recorrido pudo terminar mientras esperabamos el vehiculo.
                // Releer con bloqueo, no confiar en la consulta anterior a esa espera.
                $latest = $this->rows("SELECT re.id_recorrido,u.id_cuadrilla,u.id_vehiculo,p.hora_fin FROM recorrido re
                    JOIN participa p ON p.id_recorrido=re.id_recorrido JOIN usa u ON u.id_usa=p.id_usa
                    WHERE re.estado IN ('Pendiente','En Proceso') AND (u.id_cuadrilla=? OR u.id_vehiculo=?)" . $this->lock(), [$squad,$vehicle]);
                if (count($latest)>1) $this->fail('Hay recorridos operativos ambiguos o multiples.');
                foreach ($latest as $trip) {
                    if ((int)$trip['id_cuadrilla']!==$squad || (int)$trip['id_vehiculo']!==$vehicle || $trip['hora_fin']!==null) $this->fail('La utilizacion contradice un recorrido operativo.');
                    if (count($this->rows('SELECT id_participa FROM participa WHERE id_recorrido=?' . $this->lock(),[$trip['id_recorrido']]))!==1) $this->fail('El recorrido tiene una participacion ambigua.');
                }
                if (!(int)$v['activo'] || $v['estado']==='En Mantenimiento') $this->fail('Vehiculo inactivo o en mantenimiento.');
                if (!in_array($v['funcion_operativa'],['REGULAR','APOYO'],true)) $this->fail('El vehiculo tiene clasificacion pendiente.');
                $uses = $this->rows('SELECT id_usa FROM usa WHERE id_cuadrilla=? AND id_vehiculo=?' . $this->lock(), [$squad,$vehicle]);
                if (count($uses)!==1) $this->fail('La pareja cuadrilla/vehiculo no esta autorizada o es ambigua.');
                if ($this->rows('SELECT id_asignacion_vehiculo FROM asignacion_vehiculo_operativa WHERE id_vehiculo=? AND fecha_fin IS NULL' . $this->lock(),[$vehicle])) $this->fail('El vehiculo ya tiene una utilizacion abierta.');
            }
            $now = (new DateTimeImmutable('now',new DateTimeZone('America/Montevideo')))->format('Y-m-d H:i:s');
            if ($current) {
                if ($now<$current['fecha_inicio']) $this->fail('El inicio registrado es posterior al reloj del servidor.');
                $this->write('UPDATE asignacion_vehiculo_operativa SET fecha_fin=?,id_usuario_finaliza=?,motivo_cierre=? WHERE id_asignacion_vehiculo=? AND fecha_fin IS NULL',[$now,$actor,$reason,$expected]);
            }
            $id = $expected;
            if ($action!=='cerrar') {
                $this->write('INSERT INTO asignacion_vehiculo_operativa(id_cuadrilla,id_vehiculo,fecha_inicio,id_usuario_asigna) VALUES(?,?,?,?)',[$squad,$vehicle,$now,$actor]);
                $id=(int)$this->db->lastInsertId();
            }
            $this->db->commit(); return ['id_asignacion_vehiculo'=>$id];
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
}
