<?php

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

    public function __construct($db) {
        $this->conn = $db;
    }

    public function read($id = null, $page = 1, $limit = 20, $trackingNumber = null, $estado = null, $prioridad = null) {
        if (!$this->conn) return null;

        $conditions = [];
        $params = [];
        foreach (['id_incidencia' => $id, 'tracking_number' => $trackingNumber,
                  'estado' => $estado, 'prioridad' => $prioridad] as $column => $value) {
            if ($value !== null && $value !== '') {
                $conditions[] = "i.$column = :$column";
                $params[":$column"] = $value;
            }
        }
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

        $offset = ($page - 1) * $limit;

        $query = "SELECT i.*,
                         c.codigo AS contenedor_codigo,
                         r.nombre AS ruta_nombre,
                         q.nombre AS cuadrilla_nombre,
                         u.nombre AS usuario_nombre,
                         u.apellido AS usuario_apellido
                  FROM " . $this->table_name . " i
                  LEFT JOIN contenedor c ON c.id_contenedor = i.id_contenedor
                  LEFT JOIN ruta r ON r.id_ruta = i.id_ruta
                  LEFT JOIN cuadrilla q ON q.id_cuadrilla = i.id_cuadrilla
                  LEFT JOIN usuario u ON u.id_usuario = i.id_usuario"
                  . $where . "
                  ORDER BY i.id_incidencia ASC
                  LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($query);

        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, $name === ':id_incidencia' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        if (!$stmt->execute()) return null;

        return $stmt;
    }

    public function readForMap(float $south, float $north, float $west, float $east, int $limit, ?string $estado = null, ?string $prioridad = null) {
        if (!$this->conn) return null;
        // La incidencia no tiene coordenadas propias. No se infiere una ubicación de la ruta.
        $query = "SELECT i.id_incidencia, i.estado, i.prioridad, i.tipo_problema, i.fecha_reporte,
                         c.latitud, c.longitud, c.codigo AS contenedor_codigo
                  FROM incidencia i
                  INNER JOIN contenedor c ON c.id_contenedor = i.id_contenedor
                  WHERE c.activo = 1
                    AND c.latitud BETWEEN -90 AND 90
                    AND c.longitud BETWEEN -180 AND 180
                    AND c.latitud BETWEEN :south AND :north
                    AND c.longitud BETWEEN :west AND :east";
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

    public function updateManagement(int $id, array $changes): bool {
        if (!$this->conn) return false;
        $sets = [];
        $params = [':id' => $id];
        foreach (['estado', 'prioridad', 'id_cuadrilla'] as $column) {
            if (array_key_exists($column, $changes)) {
                $sets[] = "$column = :$column";
                $params[":$column"] = $changes[$column];
            }
        }
        $stmt = $this->conn->prepare('UPDATE incidencia SET ' . implode(', ', $sets) . ' WHERE id_incidencia = :id');
        return $stmt->execute($params);
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

        if ($tieneContenedor === $tieneRuta) {
            return false;
        }

        $query = "INSERT INTO " . $this->table_name . "
                   (tracking_number, descripcion, fecha_reporte, estado, prioridad, tipo_problema,
                   id_contenedor, id_ruta, id_cuadrilla, id_usuario)
                  VALUES (:tracking_number, :descripcion, :fecha_reporte, :estado, :prioridad, :tipo_problema,
                          :id_contenedor, :id_ruta, :id_cuadrilla, :id_usuario)";

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

        if ($stmt->execute()) {
            $this->id_incidencia = (int)$this->conn->lastInsertId();
            return true;
        }

        return false;
    }

    public function update() {
        if (!$this->conn) return false;

        $tieneContenedor = !empty($this->id_contenedor);
        $tieneRuta = !empty($this->id_ruta);

        if ($tieneContenedor === $tieneRuta) {
            return false;
        }

        $query = "UPDATE " . $this->table_name . "
                  SET descripcion = :descripcion,
                      fecha_reporte = :fecha_reporte,
                      estado = :estado,
                      prioridad = :prioridad,
                      tipo_problema = :tipo_problema,
                      id_contenedor = :id_contenedor,
                      id_ruta = :id_ruta,
                      id_cuadrilla = :id_cuadrilla,
                      id_usuario = :id_usuario
                  WHERE id_incidencia = :id_incidencia";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':id_incidencia', $this->id_incidencia);
        $stmt->bindParam(':descripcion', $this->descripcion);
        $stmt->bindParam(':fecha_reporte', $this->fecha_reporte);
        $stmt->bindParam(':estado', $this->estado);
        $stmt->bindParam(':prioridad', $this->prioridad);
        $stmt->bindParam(':tipo_problema', $this->tipo_problema);
        $stmt->bindParam(':id_contenedor', $this->id_contenedor);
        $stmt->bindParam(':id_ruta', $this->id_ruta);
        $stmt->bindParam(':id_cuadrilla', $this->id_cuadrilla);
        $stmt->bindParam(':id_usuario', $this->id_usuario);

        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;

        $query = "DELETE FROM " . $this->table_name . "
                  WHERE id_incidencia = :id_incidencia";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_incidencia', $this->id_incidencia);

        return $stmt->execute();
    }
}