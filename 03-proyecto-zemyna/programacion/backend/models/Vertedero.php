<?php
class Vertedero {
    private $conn;
    private string $table_name = "vertedero";

    public $id_centro;
    public $capacidad_maxima;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function read() {
        if (!$this->conn) return null;
        $stmt = $this->conn->prepare(
            "SELECT v.id_centro, v.capacidad_maxima, c.nombre, c.direccion, c.telefono
             FROM vertedero v
             INNER JOIN centro c ON c.id_centro = v.id_centro"
        );
        $stmt->execute();
        return $stmt;
    }

    public function create() {
        if (!$this->conn) return false;
        if (empty($this->id_centro) || !isset($this->capacidad_maxima)) return false;
        $query = "INSERT INTO " . $this->table_name . " (id_centro, capacidad_maxima)
                  VALUES (:id_centro, :capacidad_maxima)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_centro",        $this->id_centro);
        $stmt->bindParam(":capacidad_maxima", $this->capacidad_maxima);
        return $stmt->execute();
    }

    public function update() {
        if (!$this->conn) return false;
        $query = "UPDATE " . $this->table_name . "
                  SET capacidad_maxima=:capacidad_maxima
                  WHERE id_centro=:id_centro";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_centro",        $this->id_centro);
        $stmt->bindParam(":capacidad_maxima", $this->capacidad_maxima);
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
