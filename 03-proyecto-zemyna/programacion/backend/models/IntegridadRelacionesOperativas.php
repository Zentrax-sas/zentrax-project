<?php
declare(strict_types=1);
require_once __DIR__ . '/AsignacionVehiculo.php';

/** Integridad estructural C1 y contexto REGULAR/V19 C2; sin comparar residuos. */
final class IntegridadRelacionesOperativas
{
    public function __construct(private PDO $db) {}
    public function rows(string $sql, array $args = []): array
    {
        if (!$this->db->inTransaction()) throw new LogicException('Integridad fuera de transacción.');
        $suffix = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $s = $this->db->prepare($sql . $suffix); $s->execute($args);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    private function conflict(string $message): never { throw new DomainException($message, 409); }
    /** Mismos padres que asignación/inicio y V19; todos los IDs se ordenan. */
    public function lock(array $pairs, array $trips = []): void
    {
        foreach (['cuadrilla' => 'id_cuadrilla', 'recorrido' => 'id_recorrido', 'vehiculo' => 'id_vehiculo'] as $table => $key) {
            $ids = $table === 'recorrido' ? $trips : array_column($pairs, $key);
            $ids = array_unique(array_map('intval', $ids)); sort($ids);
            foreach ($ids as $id) if (!$this->rows("SELECT $key FROM $table WHERE $key=?", [$id])) throw new DomainException('Recurso operativo no encontrado.', 404);
        }
    }
    public function useChange(?array $old, ?array $new, ?int $id): void
    {
        if ($new) {
            foreach ($this->rows('SELECT id_usa FROM usa WHERE id_cuadrilla=? AND id_vehiculo=?', [$new['id_cuadrilla'], $new['id_vehiculo']]) as $row) {
                if ((int)$row['id_usa'] !== $id) $this->conflict('La pareja cuadrilla/vehículo ya está autorizada.');
            }
        }
        if (!$old || ($new && (int)$old['id_cuadrilla'] === (int)$new['id_cuadrilla'] && (int)$old['id_vehiculo'] === (int)$new['id_vehiculo'])) return;
        if ($this->rows('SELECT id_participa FROM participa WHERE id_usa=?', [$id])) $this->conflict('La autorización está referenciada por recorridos. Conservá su historial.');
        if ($this->rows('SELECT id_asignacion_vehiculo FROM asignacion_vehiculo_operativa WHERE id_cuadrilla=? AND id_vehiculo=? AND fecha_fin IS NULL', [$old['id_cuadrilla'], $old['id_vehiculo']])) $this->conflict('La autorización sostiene una utilización vigente.');
    }
    public function pending(int $trip): array
    {
        $r = $this->rows('SELECT * FROM recorrido WHERE id_recorrido=?', [$trip])[0] ?? null;
        if (!$r) throw new DomainException('Recorrido no encontrado.', 404);
        if ($r['estado'] !== 'Pendiente' || $r['fecha_fin'] !== null || $r['id_usuario_inicio'] !== null || $r['id_usuario_fin'] !== null
            || $this->rows('SELECT id_atencion_contenedor FROM atencion_contenedor WHERE id_recorrido=?', [$trip])
            || $this->rows('SELECT id_participa FROM participa WHERE id_recorrido=? AND hora_fin IS NOT NULL', [$trip])) $this->conflict('Solo se pueden modificar relaciones de recorridos Pendientes sin actividad.');
        return $r;
    }
    public function participation(int $trip, array $pair, ?int $except = null): void
    {
        $this->pending($trip);
        foreach ($this->rows('SELECT id_participa FROM participa WHERE id_recorrido=?', [$trip]) as $r) {
            if ((int)$r['id_participa'] !== $except) $this->conflict('El recorrido ya tiene una participación.');
        }
        $uses = $this->rows('SELECT id_usa FROM usa WHERE id_cuadrilla=? AND id_vehiculo=?', [$pair['id_cuadrilla'], $pair['id_vehiculo']]);
        if (count($uses) !== 1) $this->conflict('La autorización de la pareja es ambigua.');
        $vehicle = $this->rows('SELECT activo,estado FROM vehiculo WHERE id_vehiculo=?', [$pair['id_vehiculo']])[0] ?? null;
        if (!$vehicle || !(int)$vehicle['activo'] || $vehicle['estado'] === 'En Mantenimiento') $this->conflict('El vehículo no está disponible para operar.');
        foreach ($this->rows("SELECT p.id_participa FROM participa p JOIN usa u ON u.id_usa=p.id_usa JOIN recorrido r ON r.id_recorrido=p.id_recorrido WHERE r.estado IN ('Pendiente','En Proceso') AND (u.id_cuadrilla=? OR u.id_vehiculo=?)", [$pair['id_cuadrilla'], $pair['id_vehiculo']]) as $r) {
            if ((int)$r['id_participa'] !== $except) $this->conflict('La cuadrilla o el vehículo ya tienen un recorrido operativo.');
        }
        $this->regularPair((int)$pair['id_cuadrilla'], (int)$pair['id_vehiculo']);
    }

    /** C2. Dentro de la transacción, después de cuadrilla -> recorrido -> vehículo.
     * No compara residuos: una ruta puede contener residuos mixtos.
     */
    public function regularPair(int $squad, int $vehicle): void
    {
        $v = $this->rows('SELECT activo,estado,funcion_operativa FROM vehiculo WHERE id_vehiculo=?', [$vehicle])[0] ?? null;
        if (!$v || !(int)$v['activo'] || $v['estado'] === 'En Mantenimiento') $this->conflict('El vehículo no está disponible para operar.');
        if ($v['funcion_operativa'] !== 'REGULAR') $this->conflict('El recorrido requiere un vehículo habilitado para función REGULAR.');
        $open = $this->rows('SELECT id_cuadrilla,id_vehiculo,fecha_inicio FROM asignacion_vehiculo_operativa WHERE fecha_fin IS NULL AND (id_cuadrilla=? OR id_vehiculo=?)', [$squad,$vehicle]);
        $now = (new DateTimeImmutable('now', new DateTimeZone('America/Montevideo')))->format('Y-m-d H:i:s');
        if (count($open) !== 1 || (int)$open[0]['id_cuadrilla'] !== $squad || (int)$open[0]['id_vehiculo'] !== $vehicle
            || !$open[0]['fecha_inicio'] || $open[0]['fecha_inicio'] > $now) {
            $this->conflict('El recorrido requiere una utilización V19 abierta y vigente para esta cuadrilla y vehículo.');
        }
    }
}
