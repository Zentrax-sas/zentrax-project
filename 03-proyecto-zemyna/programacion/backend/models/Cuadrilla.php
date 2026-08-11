<?php
class Cuadrilla {
    private $conn;
    private string $table_name = "cuadrilla";

    public $id_cuadrilla;
    public $nombre;
    public $turno;
    public $id_centro;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function read() {
        if (!$this->conn) return null;
        $stmt = $this->conn->prepare("SELECT * FROM " . $this->table_name);
        $stmt->execute();
        return $stmt;
    }

    public function create() {
        if (!$this->conn) return false;
        if (empty($this->nombre) || empty($this->turno) || empty($this->id_centro)) return false;
        $query = "INSERT INTO " . $this->table_name . " (nombre, turno, id_centro)
                  VALUES (:nombre, :turno, :id_centro)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":nombre",    $this->nombre);
        $stmt->bindParam(":turno",     $this->turno);
        $stmt->bindParam(":id_centro", $this->id_centro);
        return $stmt->execute();
    }

    public function update() {
        if (!$this->conn) return false;
        $query = "UPDATE " . $this->table_name . "
                  SET nombre=:nombre, turno=:turno, id_centro=:id_centro
                  WHERE id_cuadrilla=:id_cuadrilla";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_cuadrilla", $this->id_cuadrilla);
        $stmt->bindParam(":nombre",       $this->nombre);
        $stmt->bindParam(":turno",        $this->turno);
        $stmt->bindParam(":id_centro",    $this->id_centro);
        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;
        $query = "DELETE FROM " . $this->table_name . " WHERE id_cuadrilla=:id_cuadrilla";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindParam(":id_cuadrilla", $this->id_cuadrilla);
        return $stmt->execute();
    }
}
