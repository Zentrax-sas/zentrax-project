<?php
class Acopio {
    private $conn;
    private string $table_name = "acopio";

    public $id_centro;
    public $horario_atencion;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function read() {
        if (!$this->conn) return null;
        // JOIN para devolver también datos del centro padre
        $stmt = $this->conn->prepare(
            "SELECT a.id_centro, a.horario_atencion, c.nombre, c.direccion, c.telefono
             FROM acopio a
             INNER JOIN centro c ON c.id_centro = a.id_centro"
        );
        $stmt->execute();
        return $stmt;
    }

    public function create() {
        if (!$this->conn) return false;
        if (empty($this->id_centro) || empty($this->horario_atencion)) return false;
        $query = "INSERT INTO " . $this->table_name . " (id_centro, horario_atencion)
                  VALUES (:id_centro, :horario_atencion)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_centro",        $this->id_centro);
        $stmt->bindParam(":horario_atencion", $this->horario_atencion);
        return $stmt->execute();
    }

    public function update() {
        if (!$this->conn) return false;
        $query = "UPDATE " . $this->table_name . "
                  SET horario_atencion=:horario_atencion
                  WHERE id_centro=:id_centro";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_centro",        $this->id_centro);
        $stmt->bindParam(":horario_atencion", $this->horario_atencion);
        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;
        $query = "DELETE FROM " . $this->table_name . " WHERE id_centro=:id_centro";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindParam(":id_centro", $this->id_centro);
        return $stmt->execute();
    }
}
