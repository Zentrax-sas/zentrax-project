<?php

/** Guards internos: nunca usar fuera de la transacción que escribe el recurso. */
final class ProteccionSolicitudOperativa
{
    public function __construct(private PDO $db) {}
    private function rows(string $sql, array $args): array {
        if (!$this->db->inTransaction()) throw new LogicException('Guard fuera de transacción.');
        $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $this->db->prepare($sql . $lock); $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    private function inconsistent(): never {
        throw new DomainException('Contexto de solicitud en atención inconsistente. No se puede modificar el recurso.', 409);
    }
    private function protected(array $assignment): array {
        $rows = $this->rows("SELECT a.*,s.estado solicitud_estado,s.id_cuadrilla solicitud_cuadrilla,s.id_tipo_residuo
            FROM atencion_solicitud a JOIN solicitud s ON s.id_solicitud=a.id_solicitud
            WHERE a.id_asignacion_vehiculo=? AND (a.estado='En atención' OR (a.fecha_cierre IS NULL AND s.estado='En atención'))
            ORDER BY a.id_atencion_solicitud", [$assignment['id_asignacion_vehiculo']]);
        foreach ($rows as $r) {
            if ($r['estado'] !== 'En atención' || $r['solicitud_estado'] !== 'En atención' || $r['fecha_cierre'] !== null
                || $assignment['fecha_fin'] !== null || (int)$r['id_cuadrilla'] !== (int)$assignment['id_cuadrilla']
                || (int)$r['solicitud_cuadrilla'] !== (int)$r['id_cuadrilla']) $this->inconsistent();
        }
        // También detecta En atención sin intento abierto o con una referencia incoherente.
        if ($assignment['fecha_fin'] === null) {
            $requests = $this->rows("SELECT id_solicitud FROM solicitud WHERE id_cuadrilla=? AND estado='En atención' ORDER BY id_solicitud", [$assignment['id_cuadrilla']]);
            foreach ($requests as $s) {
                $open = $this->rows('SELECT estado,id_asignacion_vehiculo,id_cuadrilla FROM atencion_solicitud WHERE id_solicitud=? AND fecha_cierre IS NULL', [$s['id_solicitud']]);
                if (count($open) !== 1 || $open[0]['estado'] !== 'En atención'
                    || (int)$open[0]['id_cuadrilla'] !== (int)$assignment['id_cuadrilla']
                    || (int)$open[0]['id_asignacion_vehiculo'] !== (int)$assignment['id_asignacion_vehiculo']) $this->inconsistent();
            }
        }
        return $rows;
    }
    public function assertAssignmentCanClose(int $id): void {
        $a = $this->rows('SELECT * FROM asignacion_vehiculo_operativa WHERE id_asignacion_vehiculo=?', [$id])[0] ?? null;
        if ($a && $this->protected($a)) throw new DomainException('La utilización tiene una solicitud especial en atención. Finalizá el trabajo antes de cerrar o cambiar el vehículo.', 409);
    }
    /** El llamador ya bloqueó vehículo; comparte ese mutex con F6.3A. */
    public function assertVehicleCompatible(int $vehicle, ?int $residue, bool $deactivate, ?string $function, ?string $state): void {
        foreach ($this->rows('SELECT * FROM asignacion_vehiculo_operativa WHERE id_vehiculo=? ORDER BY id_asignacion_vehiculo', [$vehicle]) as $a) {
            foreach ($this->protected($a) as $work) {
                if ($deactivate || $function !== 'APOYO' || $state === 'En Mantenimiento' || $residue !== (int)$work['id_tipo_residuo'])
                    throw new DomainException('El cambio invalidaría el vehículo de una solicitud especial en atención.', 409);
            }
        }
    }
    public function lockVehicles(array $ids): void {
        $ids = array_unique(array_map('intval', $ids)); sort($ids);
        foreach ($ids as $id) $this->rows('SELECT id_vehiculo FROM vehiculo WHERE id_vehiculo=?', [$id]);
    }
    /** Revisa ambas parejas: la anterior que se pierde y la nueva que puede duplicarse. */
    public function assertUseChange(?array $old, ?array $new): void {
        $pairs = [];
        foreach ([$old, $new] as $p) if ($p) $pairs[(int)$p['id_cuadrilla'].':'.(int)$p['id_vehiculo']] = $p;
        foreach ($pairs as $p) {
            // No filtrar primero por cuadrilla: podría ocultar una referencia incoherente.
            $assignments = $this->rows('SELECT * FROM asignacion_vehiculo_operativa WHERE id_vehiculo=? ORDER BY id_asignacion_vehiculo', [$p['id_vehiculo']]);
            foreach ($assignments as $a) {
                $work = $this->protected($a);
                if (!$work || (int)$a['id_cuadrilla'] !== (int)$p['id_cuadrilla']) continue;
                $same = fn($r) => $r && (int)$r['id_cuadrilla'] === (int)$p['id_cuadrilla'] && (int)$r['id_vehiculo'] === (int)$p['id_vehiculo'];
                $uses = $this->rows('SELECT id_usa FROM usa WHERE id_cuadrilla=? AND id_vehiculo=?', [$p['id_cuadrilla'], $p['id_vehiculo']]);
                if (count($uses) !== 1) $this->inconsistent();
                if (($same($old) && !$same($new)) || (!$same($old) && $same($new)))
                    throw new DomainException('La relación cuadrilla/vehículo es necesaria para una solicitud especial en atención.', 409);
            }
        }
    }
}
