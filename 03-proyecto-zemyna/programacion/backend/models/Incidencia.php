<?php

class Incidencia {
    private $conn;
    private string $table_name = "incidencia";

    public $id_incidencia;
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

    public function read($id = null, $page = 1, $limit = 20) {
        if (!$this->conn) return null;

        $where = '';
        if ($id !== null && $id !== '') {
            $where = ' WHERE i.id_incidencia = :id_incidencia';
        }

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
                  INNER JOIN cuadrilla q ON q.id_cuadrilla = i.id_cuadrilla
                  INNER JOIN usuario u ON u.id_usuario = i.id_usuario"
                  . $where . "
                  ORDER BY i.id_incidencia ASC
                  LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($query);

        if ($id !== null && $id !== '') {
            $stmt->bindValue(':id_incidencia', (int)$id, PDO::PARAM_INT);
        }

        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt;
    }

    public function create() {
        if (!$this->conn) return false;

        if (empty($this->descripcion) || empty($this->fecha_reporte) ||
            empty($this->estado) || empty($this->prioridad) ||
            empty($this->tipo_problema) || empty($this->id_cuadrilla) ||
            empty($this->id_usuario)) {
            return false;
        }

        $tieneContenedor = !empty($this->id_contenedor);
        $tieneRuta = !empty($this->id_ruta);

        if ($tieneContenedor === $tieneRuta) {
            return false;
        }

        $query = "INSERT INTO " . $this->table_name . "
                  (descripcion, fecha_reporte, estado, prioridad, tipo_problema,
                   id_contenedor, id_ruta, id_cuadrilla, id_usuario)
                  VALUES (:descripcion, :fecha_reporte, :estado, :prioridad, :tipo_problema,
                          :id_contenedor, :id_ruta, :id_cuadrilla, :id_usuario)";

        $stmt = $this->conn->prepare($query);

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