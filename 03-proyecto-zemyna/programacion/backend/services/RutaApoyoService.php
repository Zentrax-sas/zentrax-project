<?php
declare(strict_types=1);
require_once __DIR__ . '/OsrmClient.php';
require_once __DIR__ . '/../models/RecoleccionOperativa.php';

final class RutaApoyoService
{
    public function __construct(private PDO $db, private Closure $clientFactory) {}
    private function rows(string $sql, array $args = []): array
    { $s = $this->db->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC); }
    private function fail(int $code, string $message): never { throw new DomainException($message, $code); }

    /** Dos instantáneas cortas y de lectura; ninguna transacción abarca HTTP. */
    private function snapshot(int $actor, int $incidentId): array
    {
        if ($this->db->inTransaction()) throw new LogicException('Transacción externa no admitida.');
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        }
        $this->db->beginTransaction();
        try {
            $now = (new DateTimeImmutable('now', new DateTimeZone('America/Montevideo')))->format('Y-m-d H:i:s');
            $user = $this->rows('SELECT activo FROM usuario WHERE id_usuario=?', [$actor])[0] ?? null;
            if (!$user || $user['activo'] !== 'Activo') $this->fail(401, 'Sesión inválida o cuenta inactiva.');
            $operational = new RecoleccionOperativa($this->db);
            if (!$operational->elegibilidad($actor)['elegible']) $this->fail(403, 'Sin habilitación operativa vigente en Operaciones.');
            $permissions = $this->rows("SELECT DISTINCT p.nombre FROM usuario_rol ur JOIN rol_permiso rp ON rp.id_rol=ur.id_rol JOIN permiso p ON p.id_permiso=rp.id_permiso WHERE ur.id_usuario=? AND ur.sector='OPERACIONES' AND ur.fecha_desde<=? AND (ur.fecha_hasta IS NULL OR ur.fecha_hasta>=?) ORDER BY p.nombre", [$actor, substr($now, 0, 10), substr($now, 0, 10)]);
            if (!in_array('incidencia.operar', array_column($permissions, 'nombre'), true)) $this->fail(403, 'Sin permiso vigente para operar incidencias.');
            $members = $this->rows('SELECT id_usuario_cuadrilla,id_cuadrilla,fecha_inicio FROM usuario_cuadrilla WHERE id_usuario=? AND fecha_fin IS NULL', [$actor]);
            if (count($members) !== 1 || $members[0]['fecha_inicio'] > $now) $this->fail(409, 'Sin pertenencia vigente e inequívoca.');
            $member = $members[0]; $squad = (int) $member['id_cuadrilla'];
            $incident = $this->rows('SELECT * FROM incidencia WHERE id_incidencia=? AND id_cuadrilla=?', [$incidentId, $squad])[0] ?? null;
            if (!$incident) $this->fail(404, 'Incidencia no disponible para tu cuadrilla.');
            $attempts = $this->rows('SELECT * FROM atencion_incidencia WHERE id_incidencia=? AND fecha_cierre IS NULL', [$incidentId]);
            if (count($attempts) !== 1 || (int) $attempts[0]['id_cuadrilla'] !== $squad
                || $attempts[0]['fecha_aceptacion'] === null || $incident['fecha_resolucion'] !== null) {
                $this->fail(409, 'La incidencia no tiene un intento propio vigente disponible.');
            }
            $attempt = $attempts[0];
            $accepted = $attempt['estado'] === 'Aceptada' && $attempt['fecha_inicio'] === null
                && in_array($incident['estado'], ['Pendiente', 'En Proceso'], true);
            $inProgress = $attempt['estado'] === 'En atención' && $incident['estado'] === 'En Proceso'
                && $this->coherentStart($attempt, $now);
            if (!$accepted && !$inProgress) $this->fail(409, 'La ruta requiere un intento aceptado o en atención con fechas y estado coherentes.');
            $assignments = $this->rows('SELECT * FROM asignacion_vehiculo_operativa WHERE id_cuadrilla=? AND fecha_fin IS NULL', [$squad]);
            if (count($assignments) !== 1 || $assignments[0]['fecha_inicio'] > $now) $this->fail(409, 'V19 no vigente o ambigua.');
            $assignment = $assignments[0];
            $option = $operational->validarOpcionF3($squad, (int) $assignment['id_asignacion_vehiculo']);
            if ($option['funcion_operativa'] !== 'APOYO') $this->fail(409, 'La sugerencia requiere un vehículo APOYO.');
            $vehicle = $this->rows('SELECT * FROM vehiculo WHERE id_vehiculo=?', [$option['id_vehiculo']])[0];
            $container = null;
            if ($incident['id_contenedor'] !== null) {
                $container = $this->rows('SELECT id_contenedor,latitud,longitud,activo,id_tipo_residuo FROM contenedor WHERE id_contenedor=?', [$incident['id_contenedor']])[0] ?? null;
                if (!$container || !(int) $container['activo'] || (int) $container['id_tipo_residuo'] !== (int) $vehicle['id_tipo_residuo']) {
                    $this->fail(409, 'Contenedor no disponible o residuo incompatible con el vehículo.');
                }
            }
            $source = 'problema'; $lat = $incident['latitud']; $lon = $incident['longitud'];
            if ($lat === null && $lon === null && $container) { $lat = $container['latitud']; $lon = $container['longitud']; $source = 'contenedor'; }
            if ($lat === null || $lon === null || !is_numeric($lat) || !is_numeric($lon)) $this->fail(422, 'La incidencia no tiene una ubicación puntual válida.');
            try { $destination = OsrmClient::point(['latitud' => (float) $lat, 'longitud' => (float) $lon]); }
            catch (InvalidArgumentException $e) { $this->fail(422, 'La incidencia no tiene una ubicación puntual válida.'); }
            // Incluye relaciones coherentes que validarOpcionF3 comprueba pero no devuelve.
            $links = $this->rows('SELECT p.*,u.id_cuadrilla,u.id_vehiculo,re.estado,re.fecha_fin FROM participa p JOIN usa u ON u.id_usa=p.id_usa JOIN recorrido re ON re.id_recorrido=p.id_recorrido WHERE (u.id_cuadrilla=? OR u.id_vehiculo=?) AND re.estado IN (\'Pendiente\',\'En Proceso\') ORDER BY p.id_participa', [$squad, $option['id_vehiculo']]);
            $fingerprint = hash('sha256', json_encode([$permissions, $member, $incident, $attempts, $assignment, $vehicle, $option, $container, $links], JSON_THROW_ON_ERROR));
            $this->db->commit();
            return ['fingerprint' => $fingerprint, 'destination' => $destination, 'source' => $source, 'unclassified' => $container === null];
        } catch (Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }

    private function coherentStart(array $attempt, string $now): bool
    {
        $zone = new DateTimeZone('America/Montevideo');
        $dates = [];
        foreach (['fecha_aceptacion', 'fecha_inicio'] as $key) {
            $value = $attempt[$key];
            if (!is_string($value)) return false;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $zone);
            if (!$date || $date->format('Y-m-d H:i:s') !== $value) return false;
            $dates[$key] = $date;
        }
        return $dates['fecha_aceptacion'] <= $dates['fecha_inicio']
            && $dates['fecha_inicio'] <= new DateTimeImmutable($now, $zone);
    }

    public function suggest(int $actor, int $incidentId, array $origin): array
    {
        $before = $this->snapshot($actor, $incidentId);
        $route = null; $failure = null;
        try { $route = ($this->clientFactory)()->route($origin, $before['destination']); }
        catch (Throwable $error) { $failure = $error; }
        $after = $this->snapshot($actor, $incidentId);
        if (!hash_equals($before['fingerprint'], $after['fingerprint'])) $this->fail(409, 'El contexto cambió durante la consulta. Volvé a consultar.');
        if ($failure) throw $failure;
        $warnings = ['Origen declarado por el usuario; no representa GPS actual del vehículo.',
            'Punto trazable al registro, sin certificación geográfica persistida. Se controla el ajuste a la vía.',
            'Perfil driving: no garantiza restricciones de peso, altura, ancho o acceso de vehículos pesados.',
            'Estimaciones dependientes del dataset vial y del perfil; sin tráfico en tiempo real garantizado.',
            'Ruta calculada por OSRM, independiente del Dijkstra académico F5.1.'];
        if ($before['unclassified']) $warnings[] = 'Sin residuo vinculado a contenedor: no se certifica compatibilidad de carga ni se autoriza recogida.';
        return $route + ['origen' => $origin, 'destino' => $before['destination'] + ['fuente' => $before['source']],
            'motor' => 'OSRM', 'perfil' => 'driving', 'consultiva' => true, 'advertencias' => $warnings];
    }
}
