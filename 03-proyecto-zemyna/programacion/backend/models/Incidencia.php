<?php
require_once __DIR__ . '/RecoleccionOperativa.php';
require_once __DIR__ . '/AtencionIncidencia.php';

class Incidencia {
    private $conn;
    private string $table_name = "incidencia";

    public $id_incidencia;
    public $tracking_number;
    public $descripcion;
    public $fecha_reporte;
    public $estado;
    public $prioridad;
    public $tipo_problema;
    public $id_contenedor;
    public $id_ruta;
    public $id_cuadrilla;
    public $id_usuario;
    public $latitud;
    public $longitud;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function read($id = null, $page = 1, $limit = 20, $trackingNumber = null, $estado = null, $prioridad = null, array $filters = [], bool $lookahead = false) {
        return $this->readQuery($id, $page, $limit, $trackingNumber, $estado, $prioridad, $filters, $lookahead);
    }

    /** Usuario y cuadrilla provienen de la sesión/pertenencia, nunca del request. */
    public function readOwn(int $user, int $squad, ?int $id, int $page, int $limit, ?string $estado) {
        $filters = $id === null && $estado === null ? ['activas' => '1'] : [];
        return $this->readQuery($id, $page, $limit, null, $estado, null, $filters, true, [$user, $squad]);
    }

    private function readQuery($id, $page, $limit, $trackingNumber, $estado, $prioridad, array $filters, bool $lookahead, ?array $ownership = null) {
        if (!$this->conn) return null;

        $conditions = [];
        $params = [];
        if ($ownership !== null) {
            $conditions[] = 'i.id_cuadrilla = :own_squad';
            // Revalidar la pertenencia también en el SELECT: un traslado no amplía el alcance.
            $conditions[] = 'EXISTS (SELECT 1 FROM usuario_cuadrilla uc WHERE uc.id_usuario = :own_user
                AND uc.id_cuadrilla = i.id_cuadrilla AND uc.fecha_fin IS NULL)';
            $params[':own_user'] = $ownership[0];
            $params[':own_squad'] = $ownership[1];
        }
        foreach (['id_incidencia' => $id, 'tracking_number' => $trackingNumber,
                  'estado' => $estado, 'prioridad' => $prioridad] as $column => $value) {
            if ($value !== null && $value !== '') {
                $conditions[] = "i.$column = :$column";
                $params[":$column"] = $value;
            }
        }
        if (($filters['activas'] ?? null) === '1') $conditions[] = "i.estado IN ('Pendiente', 'En Proceso')";
        foreach (['tipo_problema' => 'i.tipo_problema', 'id_ruta' => 'r.id_ruta', 'zona' => 'r.zona'] as $key => $column) {
            if (isset($filters[$key])) { $conditions[] = "$column = :$key"; $params[":$key"] = $filters[$key]; }
        }
        foreach (['desde' => '>=', 'hasta' => '<='] as $key => $operator) {
            if (isset($filters[$key])) {
                $conditions[] = "i.fecha_reporte $operator :$key";
                $params[":$key"] = $filters[$key] . ($key === 'desde' ? ' 00:00:00' : ' 23:59:59');
            }
        }
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

        $offset = ($page - 1) * $limit;

        $fields = $ownership === null ? "i.*,
                         c.codigo AS contenedor_codigo, c.direccion AS contenedor_direccion,
                         r.nombre AS ruta_nombre,
                         q.nombre AS cuadrilla_nombre,
                         u.nombre AS usuario_nombre,
                         u.apellido AS usuario_apellido" : "i.id_incidencia, i.tracking_number, i.estado, i.prioridad,
                         i.tipo_problema, i.descripcion, i.fecha_reporte, i.fecha_resolucion,
                         i.id_contenedor, c.codigo AS contenedor_codigo, c.direccion AS contenedor_direccion,
                         r.id_ruta, r.nombre AS ruta_nombre,
                         COALESCE(i.latitud, c.latitud) AS latitud, COALESCE(i.longitud, c.longitud) AS longitud,
                         CASE WHEN i.latitud IS NOT NULL AND i.longitud IS NOT NULL THEN 'problema'
                              WHEN c.latitud IS NOT NULL AND c.longitud IS NOT NULL THEN 'contenedor'
                              ELSE NULL END AS ubicacion_origen";
        if ($ownership !== null) {
            $fields .= ", (SELECT a.id_atencion_incidencia FROM atencion_incidencia a WHERE a.id_incidencia=i.id_incidencia AND a.id_cuadrilla=i.id_cuadrilla ORDER BY a.id_atencion_incidencia DESC LIMIT 1) AS atencion_id,
                (SELECT a.estado FROM atencion_incidencia a WHERE a.id_incidencia=i.id_incidencia AND a.id_cuadrilla=i.id_cuadrilla ORDER BY a.id_atencion_incidencia DESC LIMIT 1) AS atencion_estado";
        }
        $query = "SELECT " . $fields . "
                  FROM " . $this->table_name . " i
                  LEFT JOIN contenedor c ON c.id_contenedor = i.id_contenedor
                  LEFT JOIN ruta r ON r.id_ruta = COALESCE(i.id_ruta, c.id_ruta)
                  LEFT JOIN cuadrilla q ON q.id_cuadrilla = i.id_cuadrilla
                  LEFT JOIN usuario u ON u.id_usuario = i.id_usuario"
                  . $where . "
                  ORDER BY CASE i.prioridad WHEN 'Alta' THEN 1 WHEN 'Media' THEN 2 WHEN 'Baja' THEN 3 ELSE 4 END, i.fecha_reporte ASC, i.id_incidencia ASC
                  LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($query);

        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, $name === ':id_incidencia' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->bindValue(':limit', (int)$limit + ($lookahead ? 1 : 0), PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        if (!$stmt->execute()) return null;

        return $stmt;
    }

    public function inboxOptions(): array {
        if (!$this->conn) throw new PDOException('Sin conexión.');
        return ['rutas' => $this->conn->query('SELECT id_ruta, nombre, zona FROM ruta ORDER BY zona, nombre, id_ruta')->fetchAll(PDO::FETCH_ASSOC),
            'tipos' => $this->conn->query('SELECT DISTINCT tipo_problema FROM incidencia ORDER BY tipo_problema')->fetchAll(PDO::FETCH_COLUMN)];
    }

    public function attentionHistory(int $id): array {
        return (new AtencionIncidencia($this->conn))->history($id);
    }

    public function evidence(int $id): array {
        if (!$this->conn) throw new PDOException('Sin conexión.');
        $stmt = $this->conn->prepare('SELECT id_foto, fecha FROM foto WHERE id_incidencia = :id ORDER BY fecha, id_foto');
        $stmt->execute([':id' => $id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function report(string $group, ?string $from, ?string $to, int $page, int $limit): array {
        if (!$this->conn) throw new PDOException('Sin conexión.');
        $conditions = ["i.estado IN ('Pendiente', 'En Proceso', 'Resuelta')"];
        $params = [];
        if ($group === 'abiertas') $conditions[] = "i.estado IN ('Pendiente', 'En Proceso')";
        if ($group === 'cerradas') $conditions[] = "i.estado = 'Resuelta'";
        if ($from !== null) { $conditions[] = 'i.fecha_reporte >= :desde'; $params[':desde'] = $from . ' 00:00:00'; }
        if ($to !== null) { $conditions[] = 'i.fecha_reporte <= :hasta'; $params[':hasta'] = $to . ' 23:59:59'; }
        $where = ' WHERE ' . implode(' AND ', $conditions);
        $count = $this->conn->prepare("SELECT COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN i.estado IN ('Pendiente', 'En Proceso') THEN 1 ELSE 0 END), 0) AS abiertas,
            COALESCE(SUM(CASE WHEN i.estado = 'Resuelta' THEN 1 ELSE 0 END), 0) AS cerradas FROM incidencia i" . $where);
        if (!$count->execute($params)) throw new PDOException('Error al contar.');
        $totals = array_map('intval', $count->fetch(PDO::FETCH_ASSOC));
        $stmt = $this->conn->prepare('SELECT i.id_incidencia, i.tracking_number, i.fecha_reporte,
            i.tipo_problema, i.estado, i.prioridad, i.fecha_resolucion, i.latitud, i.longitud, c.codigo AS contenedor_codigo, r.nombre AS ruta_nombre
            FROM incidencia i LEFT JOIN contenedor c ON c.id_contenedor = i.id_contenedor
            LEFT JOIN ruta r ON r.id_ruta = i.id_ruta' . $where . '
            ORDER BY i.fecha_reporte DESC, i.id_incidencia DESC LIMIT :limit OFFSET :offset');
        foreach ($params as $key => $value) $stmt->bindValue($key, $value, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        if (!$stmt->execute()) throw new PDOException('Error al consultar.');
        return ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'totals' => $totals,
            'meta' => ['page' => $page, 'limit' => $limit, 'total' => $totals['total'],
                'pages' => (int)ceil($totals['total'] / $limit)]];
    }

    public function readForMap(float $south, float $north, float $west, float $east, int $limit, ?string $estado = null, ?string $prioridad = null, bool $activeOnly = false, bool $administrative = false) {
        if (!$this->conn) return null;
        // Expresiones seleccionadas exclusivamente por el modo autorizado, nunca por texto del cliente.
        $lat = $administrative ? 'CAST(COALESCE(i.latitud, c.latitud) AS DECIMAL(10,7))' : 'c.latitud';
        $lng = $administrative ? 'CAST(COALESCE(i.longitud, c.longitud) AS DECIMAL(10,7))' : 'c.longitud';
        $source = $administrative ? ", CASE WHEN i.latitud IS NOT NULL THEN 'problema' ELSE 'contenedor' END AS ubicacion_origen" : '';
        $scope = $administrative ? '(i.latitud IS NOT NULL OR c.activo = 1)' : 'c.activo = 1 AND i.latitud IS NULL AND i.longitud IS NULL';
        $query = "SELECT i.id_incidencia, i.estado, i.prioridad, i.tipo_problema, i.fecha_reporte,
                         $lat AS latitud, $lng AS longitud, c.codigo AS contenedor_codigo $source
                  FROM incidencia i
                  LEFT JOIN contenedor c ON c.id_contenedor = i.id_contenedor
                  WHERE $scope
                    AND $lat BETWEEN -90 AND 90
                    AND $lng BETWEEN -180 AND 180
                    AND $lat BETWEEN :south AND :north
                    AND $lng BETWEEN :west AND :east";
        if ($activeOnly) $query .= " AND i.estado IN ('Pendiente', 'En Proceso')";
        if ($estado !== null) $query .= ' AND i.estado = :estado';
        if ($prioridad !== null) $query .= ' AND i.prioridad = :prioridad';
        $query .= ' ORDER BY i.id_incidencia DESC LIMIT :limit';
        $stmt = $this->conn->prepare($query);
        foreach (['south' => $south, 'north' => $north, 'west' => $west, 'east' => $east] as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        if ($estado !== null) $stmt->bindValue(':estado', $estado);
        if ($prioridad !== null) $stmt->bindValue(':prioridad', $prioridad);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        if (!$stmt->execute()) return null;
        return $stmt;
    }

    public function location(int $id): ?array {
        if (!$this->conn) throw new PDOException('Sin conexión.');
        $stmt = $this->conn->prepare('SELECT i.id_incidencia, i.estado, i.prioridad, i.tipo_problema,
            i.fecha_reporte, c.codigo AS contenedor_codigo, COALESCE(i.latitud, c.latitud) AS latitud, COALESCE(i.longitud, c.longitud) AS longitud,
            CASE WHEN i.latitud IS NOT NULL THEN \'problema\' ELSE \'contenedor\' END AS ubicacion_origen
            FROM incidencia i LEFT JOIN contenedor c ON c.id_contenedor = i.id_contenedor
            WHERE i.id_incidencia = :id');
        if (!$stmt->execute([':id' => $id])) throw new PDOException('Error de consulta.');
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Se evalúa antes de asignar estado: MariaDB evalúa SET de izquierda a derecha.
    private function resolutionAssignment(): string {
        return "fecha_resolucion = CASE WHEN :resolution_state <> 'Resuelta' THEN NULL
            WHEN estado IN ('Pendiente', 'En Proceso') THEN :resolution_now ELSE fecha_resolucion END";
    }

    private function resolutionNow(): string {
        return (new DateTimeImmutable('now', new DateTimeZone('America/Montevideo')))->format('Y-m-d H:i:s');
    }

    public function containerExists(int $id): bool {
        if (!$this->conn) throw new PDOException('Sin conexión.');
        $stmt = $this->conn->prepare('SELECT id_contenedor FROM contenedor WHERE id_contenedor = :id AND activo = 1');
        if (!$stmt->execute([':id' => $id])) throw new PDOException('Error al consultar contenedor.');
        return $stmt->fetchColumn() !== false;
    }

    public function cuadrillas(): array {
        if (!$this->conn) throw new PersistenceException('Sin conexión.');
        $stmt = $this->conn->prepare('SELECT id_cuadrilla, nombre, turno FROM cuadrilla ORDER BY nombre, id_cuadrilla');
        if (!$stmt->execute()) throw new PersistenceException('No se pudieron consultar las cuadrillas.');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function cuadrillaExists(int $id): bool {
        if (!$this->conn) throw new PersistenceException('Sin conexión.');
        $stmt = $this->conn->prepare('SELECT id_cuadrilla FROM cuadrilla WHERE id_cuadrilla = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        if (!$stmt->execute()) throw new PersistenceException('No se pudo consultar la cuadrilla.');
        return $stmt->fetchColumn() !== false;
    }

    public function assignmentOptions(): array {
        if (!$this->conn) throw new PDOException('Sin conexión.');
        return (new RecoleccionOperativa($this->conn))->opcionesIncidencia();
    }

    public function assignOperational(int $id, ?int $squad, ?int $trip, ?int $use, ?int $expectedSquad, string $expectedState, int $actor = 0): void {
        if (!$this->conn) throw new PDOException('Sin conexión.');
        $this->conn->beginTransaction();
        try {
            $lock = $this->conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->conn->prepare('SELECT estado, id_cuadrilla FROM incidencia WHERE id_incidencia = ?' . $lock);
            $stmt->execute([$id]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$current) throw new DomainException('Incidencia no encontrada.', 404);
            if ($current['estado'] === 'Resuelta') throw new DomainException('No se puede asignar ni desasignar una incidencia Resuelta.', 409);
            $currentSquad = $current['id_cuadrilla'] === null ? null : (int) $current['id_cuadrilla'];
            if ($currentSquad !== $expectedSquad || $current['estado'] !== $expectedState) throw new DomainException('La incidencia cambió. Volvé a abrir el detalle antes de asignar.', 409);
            if ($squad !== null) (new RecoleccionOperativa($this->conn))->validarOpcionIncidencia($squad, $trip, $use, true);
            if ($currentSquad === $squad) throw new DomainException('La incidencia ya tiene esa asignación. Volvé a consultar.', 409);
            $attention = new AtencionIncidencia($this->conn);
            $now = AtencionIncidencia::now();
            $attention->interrupt($id, $actor, $squad === null ? 'Desasignación' : 'Reasignación', $now);
            if ($squad !== null) $attention->register($id, $squad, $actor, 'Asignacion', $now);
            $stmt = $this->conn->prepare('UPDATE incidencia SET id_cuadrilla = ? WHERE id_incidencia = ?');
            if (!$stmt->execute([$squad, $id])) throw new PDOException('No se pudo asignar.');
            $this->conn->commit();
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

    public function updateManagement(int $id, array $changes, int $actor = 0): bool {
        if (!$this->conn) return false;
        $sets = [];
        $params = [':id' => $id];
        if (array_key_exists('estado', $changes)) {
            $sets[] = $this->resolutionAssignment();
            $params[':resolution_state'] = $changes['estado'];
            $params[':resolution_now'] = $this->resolutionNow();
        }
        foreach (['estado', 'prioridad'] as $column) {
            if (array_key_exists($column, $changes)) {
                $sets[] = "$column = :$column";
                $params[":$column"] = $changes[$column];
            }
        }
        $stmt = $this->conn->prepare('UPDATE incidencia SET ' . implode(', ', $sets) . ' WHERE id_incidencia = :id');
        return (new AtencionIncidencia($this->conn))->administrative($id, $changes['estado'] ?? null, $actor, fn() => $stmt->execute($params));
    }

    public function create() {
        if (!$this->conn) return false;

        if (empty($this->descripcion) || empty($this->fecha_reporte) ||
            empty($this->estado) || empty($this->prioridad) ||
            empty($this->tipo_problema)) {
            return false;
        }

        $tieneContenedor = !empty($this->id_contenedor);
        $tieneRuta = !empty($this->id_ruta);

        if (($tieneContenedor && $tieneRuta) || (!$tieneContenedor && !$tieneRuta && ($this->latitud === null || $this->longitud === null))) {
            return false;
        }

        $query = "INSERT INTO " . $this->table_name . "
                   (tracking_number, descripcion, fecha_reporte, estado, prioridad, tipo_problema,
                   id_contenedor, id_ruta, id_cuadrilla, id_usuario, latitud, longitud)
                  VALUES (:tracking_number, :descripcion, :fecha_reporte, :estado, :prioridad, :tipo_problema,
                          :id_contenedor, :id_ruta, :id_cuadrilla, :id_usuario, :latitud, :longitud)";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':tracking_number', $this->tracking_number);
        $stmt->bindParam(':descripcion', $this->descripcion);
        $stmt->bindParam(':fecha_reporte', $this->fecha_reporte);
        $stmt->bindParam(':estado', $this->estado);
        $stmt->bindParam(':prioridad', $this->prioridad);
        $stmt->bindParam(':tipo_problema', $this->tipo_problema);
        $stmt->bindParam(':id_contenedor', $this->id_contenedor);
        $stmt->bindParam(':id_ruta', $this->id_ruta);
        $stmt->bindParam(':id_cuadrilla', $this->id_cuadrilla);
        $stmt->bindParam(':id_usuario', $this->id_usuario);
        $stmt->bindParam(':latitud', $this->latitud);
        $stmt->bindParam(':longitud', $this->longitud);

        if ($stmt->execute()) {
            $this->id_incidencia = (int)$this->conn->lastInsertId();
            return true;
        }

        return false;
    }

    public function update(int $actor = 0) {
        if (!$this->conn) return false;

        $tieneContenedor = !empty($this->id_contenedor);
        $tieneRuta = !empty($this->id_ruta);

        if (($tieneContenedor && $tieneRuta) || (!$tieneContenedor && !$tieneRuta && ($this->latitud === null || $this->longitud === null))) {
            return false;
        }

        $query = "UPDATE " . $this->table_name . "
                  SET " . $this->resolutionAssignment() . ", descripcion = :descripcion,
                      fecha_reporte = :fecha_reporte,
                      estado = :estado,
                      prioridad = :prioridad,
                      tipo_problema = :tipo_problema,
                      id_contenedor = :id_contenedor,
                      id_ruta = :id_ruta,
                      id_usuario = :id_usuario
                  WHERE id_incidencia = :id_incidencia";

        $stmt = $this->conn->prepare($query);

        $stmt->bindValue(':resolution_state', $this->estado);
        $stmt->bindValue(':resolution_now', $this->resolutionNow());
        $stmt->bindParam(':id_incidencia', $this->id_incidencia);
        $stmt->bindParam(':descripcion', $this->descripcion);
        $stmt->bindParam(':fecha_reporte', $this->fecha_reporte);
        $stmt->bindParam(':estado', $this->estado);
        $stmt->bindParam(':prioridad', $this->prioridad);
        $stmt->bindParam(':tipo_problema', $this->tipo_problema);
        $stmt->bindParam(':id_contenedor', $this->id_contenedor);
        $stmt->bindParam(':id_ruta', $this->id_ruta);
        $stmt->bindParam(':id_usuario', $this->id_usuario);

        return (new AtencionIncidencia($this->conn))->administrative((int)$this->id_incidencia, $this->estado, $actor, fn() => $stmt->execute());
    }

    public function delete() {
        if (!$this->conn) return false;

        $query = "DELETE FROM " . $this->table_name . "
                  WHERE id_incidencia = :id_incidencia";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_incidencia', $this->id_incidencia);

        return (new AtencionIncidencia($this->conn))->delete((int)$this->id_incidencia, fn() => $stmt->execute());
    }
}
