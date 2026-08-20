<?php

class Recorrido {
    private $conn;
    private string $table_name = "recorrido";

    public $id_recorrido;
    public $fecha_inicio;
    public $fecha_fin;
    public $estado;
    public $id_ruta;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function read($id = null, $idRuta = null) {
        if (!$this->conn) return null;

        $where = [];
        $params = [];

        if ($id !== null && $id !== '') {
            $where[] = "r.id_recorrido = :id_recorrido";
            $params[':id_recorrido'] = (int)$id;
        }

        if ($idRuta !== null && $idRuta !== '') {
            $where[] = "r.id_ruta = :id_ruta";
            $params[':id_ruta'] = (int)$idRuta;
        }

        $query = "SELECT r.*, ru.nombre AS ruta_nombre, ru.zona
                  FROM " . $this->table_name . " r
                  INNER JOIN ruta ru ON ru.id_ruta = r.id_ruta";

        if ($where) {
            $query .= " WHERE " . implode(" AND ", $where);
        }

        $query .= " ORDER BY r.fecha_inicio DESC";

        $stmt = $this->conn->prepare($query);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt;
    }

    public function create() {
        if (!$this->conn) return false;

        if (empty($this->fecha_inicio) || empty($this->estado) || empty($this->id_ruta)) {
            return false;
        }

        $query = "INSERT INTO " . $this->table_name . "
                  (fecha_inicio, fecha_fin, estado, id_ruta)
                  VALUES (:fecha_inicio, :fecha_fin, :estado, :id_ruta)";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':fecha_inicio', $this->fecha_inicio);
        $stmt->bindParam(':fecha_fin', $this->fecha_fin);
        $stmt->bindParam(':estado', $this->estado);
        $stmt->bindParam(':id_ruta', $this->id_ruta);

        if ($stmt->execute()) {
            $this->id_recorrido = (int)$this->conn->lastInsertId();
            return true;
        }

        return false;
    }

    public function update() {
        if (!$this->conn) return false;

        $query = "UPDATE " . $this->table_name . "
                  SET fecha_inicio = :fecha_inicio,
                      fecha_fin = :fecha_fin,
                      estado = :estado,
                      id_ruta = :id_ruta
                  WHERE id_recorrido = :id_recorrido";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_recorrido', $this->id_recorrido);
        $stmt->bindParam(':fecha_inicio', $this->fecha_inicio);
        $stmt->bindParam(':fecha_fin', $this->fecha_fin);
        $stmt->bindParam(':estado', $this->estado);
        $stmt->bindParam(':id_ruta', $this->id_ruta);

        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;

        $query = "DELETE FROM " . $this->table_name . "
                  WHERE id_recorrido = :id_recorrido";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_recorrido', $this->id_recorrido);

        return $stmt->execute();
    }
}