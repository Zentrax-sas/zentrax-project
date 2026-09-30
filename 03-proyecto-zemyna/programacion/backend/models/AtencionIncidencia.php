<?php
require_once __DIR__ . '/RecoleccionOperativa.php';

/** Un intento por asignación. Los escritores bloquean primero incidencia. */
class AtencionIncidencia
{
    public function __construct(private PDO $db) {}
    public static function now(): string { return (new DateTimeImmutable('now', new DateTimeZone('America/Montevideo')))->format('Y-m-d H:i:s'); }
    private function lock(): string { return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''; }
    private function query(string $sql, array $params = []): PDOStatement {
        $stmt = $this->db->prepare($sql);
        if (!$stmt->execute($params)) throw new PDOException('No se pudo persistir la atención.');
        return $stmt;
    }
    public function transaction(callable $fn): mixed {
        $this->db->beginTransaction();
        try { $result = $fn(); $this->db->commit(); return $result; }
        catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    public function incident(int $id): ?array {
        return $this->query('SELECT id_incidencia, id_cuadrilla, estado, fecha_resolucion FROM incidencia WHERE id_incidencia = ?' . $this->lock(), [$id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public function current(int $id): ?array {
        return $this->query('SELECT * FROM atencion_incidencia WHERE id_incidencia = ? AND fecha_cierre IS NULL' . $this->lock(), [$id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public function history(int $id): array {
        return $this->query('SELECT * FROM atencion_incidencia WHERE id_incidencia = ? ORDER BY id_atencion_incidencia DESC', [$id])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function register(int $id, int $squad, int $actor, string $origin, string $now): void {
        if ($actor <= 0) throw new DomainException('No autenticado.', 401);
        $this->query("INSERT INTO atencion_incidencia (id_incidencia,id_cuadrilla,estado,origen,fecha_registro,id_usuario_registra)
            VALUES (?,?,'Asignada',?,?,?)", [$id, $squad, $origin, $now, $actor]);
    }
    public function interrupt(int $id, int $actor, string $reason, string $now): void {
        if (!$this->current($id)) return;
        if ($actor <= 0) throw new DomainException('No autenticado.', 401);
        $this->query("UPDATE atencion_incidencia SET estado='Interrumpida', fecha_cierre=?, id_usuario_cierra=?, motivo_cierre=?
            WHERE id_incidencia=? AND fecha_cierre IS NULL", [$now, $actor, $reason, $id]);
    }
    /** Incluye actualización parcial y legacy completa; el callback nunca cambia cuadrilla. */
    public function administrative(int $id, ?string $state, int $actor, callable $write): bool {
        return $this->transaction(function () use ($id, $state, $actor, $write) {
            $incident = $this->incident($id);
            if (!$incident) throw new DomainException('Incidencia no encontrada.', 404);
            $now = self::now();
            if ($state !== null && $state !== $incident['estado']) {
                $attempt = $this->current($id);
                if ($state === 'Resuelta') $this->interrupt($id, $actor, 'Resolución administrativa', $now);
                elseif ($incident['estado'] === 'Resuelta' && $incident['id_cuadrilla'] !== null) {
                    $this->register($id, (int)$incident['id_cuadrilla'], $actor, 'Reapertura', $now);
                } elseif ($state === 'Pendiente' && ($attempt['estado'] ?? null) === 'En atención') {
                    $this->interrupt($id, $actor, 'Reinicio administrativo', $now);
                    $this->register($id, (int)$incident['id_cuadrilla'], $actor, 'Reinicio administrativo', $now);
                }
            }
            if (!$write()) throw new PDOException('No se pudo actualizar la incidencia.');
            return true;
        });
    }
    public function delete(int $id, callable $write): bool {
        return $this->transaction(function () use ($id, $write) {
            if (!$this->incident($id)) throw new DomainException('Incidencia no encontrada.', 404);
            if ($this->query('SELECT id_atencion_incidencia FROM atencion_incidencia WHERE id_incidencia=? LIMIT 1', [$id])->fetchColumn() !== false) {
                throw new DomainException('La incidencia tiene historial de atención y no puede eliminarse.', 409);
            }
            if (!$write()) throw new PDOException('No se pudo eliminar.');
            return true;
        });
    }
    public function operate(int $actor, int $id, int $attemptId, string $expected, string $action, ?string $reason): array {
        return $this->transaction(function () use ($actor, $id, $attemptId, $expected, $action, $reason) {
            // No copiar el orden usuario->recorrido: F3 ya bloquea incidencia primero.
            $incident = $this->incident($id);
            $user = $this->query('SELECT activo FROM usuario WHERE id_usuario=?' . $this->lock(), [$actor])->fetchColumn();
            if ($user !== 'Activo') throw new DomainException('Usuario de sesión inactivo.', 401);
            $operational = new RecoleccionOperativa($this->db);
            if (!$operational->elegibilidad($actor, true)['elegible']) throw new DomainException('No tenés permisos operativos vigentes en OPERACIONES.', 403);
            $date = substr(self::now(), 0, 10);
            $permission = $this->query("SELECT p.id_permiso FROM usuario_rol ur JOIN rol_permiso rp ON rp.id_rol=ur.id_rol
                JOIN permiso p ON p.id_permiso=rp.id_permiso WHERE ur.id_usuario=? AND ur.sector='OPERACIONES'
                AND ur.fecha_desde<=? AND (ur.fecha_hasta IS NULL OR ur.fecha_hasta>=?) AND p.nombre='incidencia.operar'" . $this->lock(), [$actor,$date,$date])->fetchColumn();
            if ($permission === false) throw new DomainException('No tenés permiso para operar incidencias.', 403);
            $member = $this->query('SELECT id_cuadrilla FROM usuario_cuadrilla WHERE id_usuario=? AND fecha_fin IS NULL' . $this->lock(), [$actor])->fetchColumn();
            if ($member === false) throw new DomainException('No tenés pertenencia vigente a una cuadrilla.', 409);
            if (!$incident || $incident['id_cuadrilla'] === null || (int)$incident['id_cuadrilla'] !== (int)$member) {
                throw new DomainException('Incidencia no accesible para esta cuadrilla.', 404);
            }
            $attempt = $this->current($id);
            $origins = ['aceptar_incidencia'=>'Asignada','rechazar_incidencia'=>'Asignada',
                'iniciar_atencion_incidencia'=>'Aceptada','finalizar_atencion_incidencia'=>'En atención'];
            if ($incident['estado'] === 'Resuelta' || !$attempt || (int)$attempt['id_atencion_incidencia'] !== $attemptId
                || (int)$attempt['id_cuadrilla'] !== (int)$member || $attempt['estado'] !== $expected || $expected !== ($origins[$action] ?? null)) {
                throw new DomainException('La atención cambió. Volvé a consultar la incidencia.', 409);
            }
            $now = self::now();
            if ($action === 'aceptar_incidencia') {
                $state = 'Aceptada';
                $this->query("UPDATE atencion_incidencia SET estado=?,fecha_aceptacion=?,id_usuario_acepta=? WHERE id_atencion_incidencia=?", [$state,$now,$actor,$attemptId]);
            } elseif ($action === 'rechazar_incidencia') {
                if ($reason === null || trim($reason) === '' || mb_strlen($reason, 'UTF-8') > 500) throw new DomainException('Indicá un motivo de hasta 500 caracteres.', 400);
                $state = 'Rechazada';
                $this->query('UPDATE atencion_incidencia SET estado=?,fecha_cierre=?,id_usuario_cierra=?,motivo_cierre=? WHERE id_atencion_incidencia=?', [$state,$now,$actor,trim($reason),$attemptId]);
                $this->query('UPDATE incidencia SET id_cuadrilla=NULL WHERE id_incidencia=?', [$id]);
            } elseif ($action === 'iniciar_atencion_incidencia') {
                $state = 'En atención';
                $this->query('UPDATE atencion_incidencia SET estado=?,fecha_inicio=?,id_usuario_inicia=? WHERE id_atencion_incidencia=?', [$state,$now,$actor,$attemptId]);
                $this->query("UPDATE incidencia SET estado='En Proceso' WHERE id_incidencia=?", [$id]);
                $incident['estado'] = 'En Proceso';
            } else {
                if ($incident['estado'] !== 'En Proceso') throw new DomainException('La incidencia no está En Proceso.', 409);
                $state = 'Finalizada';
                $this->query('UPDATE atencion_incidencia SET estado=?,fecha_cierre=?,id_usuario_cierra=? WHERE id_atencion_incidencia=?', [$state,$now,$actor,$attemptId]);
                $this->query("UPDATE incidencia SET fecha_resolucion=?,estado='Resuelta' WHERE id_incidencia=? AND estado='En Proceso'", [$now,$id]);
                $incident['estado'] = 'Resuelta'; $incident['fecha_resolucion'] = $now;
            }
            return ['id_incidencia'=>$id,'estado'=>$incident['estado'],'fecha_resolucion'=>$incident['fecha_resolucion'],
                'atencion'=>['id_atencion_incidencia'=>$attemptId,'estado'=>$state]];
        });
    }
}
