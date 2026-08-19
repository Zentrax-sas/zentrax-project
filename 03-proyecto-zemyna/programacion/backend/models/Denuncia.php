<?php

class Denuncia {
    private $conn;
    private string $table_name = "denuncia";

    public $id_denuncia;
    public $fecha;
    public $descripcion;
    public $ci;
    public $id_incidencia;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function read($id = null) {
        if (!$this->conn) return null;

        $query = "SELECT d.*, v.nombre AS vecino_nombre, v.apellido AS vecino_apellido
                  FROM " . $this->table_name . " d
                  INNER JOIN vecino v ON v.ci = d.ci";

        if ($id !== null && $id !== '') {
            $query .= " WHERE d.id_denuncia = :id_denuncia";
        }

        $query .= " ORDER BY d.fecha DESC";

        $stmt = $this->conn->prepare($query);

        if ($id !== null && $id !== '') {
            $stmt->bindValue(':id_denuncia', (int)$id, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt;
    }

    public function create() {
        if (!$this->conn) return false;

        if (empty($this->fecha) || empty($this->descripcion) ||
            empty($this->ci) || empty($this->id_incidencia)) {
            return false;
        }

        $query = "INSERT INTO " . $this->table_name . "
                  (fecha, descripcion, ci, id_incidencia)
                  VALUES (:fecha, :descripcion, :ci, :id_incidencia)";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':fecha', $this->fecha);
        $stmt->bindParam(':descripcion', $this->descripcion);
        $stmt->bindParam(':ci', $this->ci);
        $stmt->bindParam(':id_incidencia', $this->id_incidencia);

        if ($stmt->execute()) {
            $this->id_denuncia = (int)$this->conn->lastInsertId();
            return true;
        }

        return false;
    }

    public function update() {
        if (!$this->conn) return false;

        $query = "UPDATE " . $this->table_name . "
                  SET fecha = :fecha,
                      descripcion = :descripcion,
                      ci = :ci,
                      id_incidencia = :id_incidencia
                  WHERE id_denuncia = :id_denuncia";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_denuncia', $this->id_denuncia);
        $stmt->bindParam(':fecha', $this->fecha);
        $stmt->bindParam(':descripcion', $this->descripcion);
        $stmt->bindParam(':ci', $this->ci);
        $stmt->bindParam(':id_incidencia', $this->id_incidencia);

        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;

        $query = "DELETE FROM " . $this->table_name . "
                  WHERE id_denuncia = :id_denuncia";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_denuncia', $this->id_denuncia);

        return $stmt->execute();
    }
}