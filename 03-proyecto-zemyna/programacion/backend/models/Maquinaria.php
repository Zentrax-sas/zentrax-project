<?php
class Maquinaria {
    private $conn;
    private string $table_name = "maquinaria";

    public $id_maquinaria;
    public $nombre;
    public $tipo;
    public $estado;
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
        if (empty($this->nombre) || empty($this->tipo) || empty($this->estado) || empty($this->id_centro)) return false;
        $query = "INSERT INTO " . $this->table_name . " (nombre, tipo, estado, id_centro)
                  VALUES (:nombre, :tipo, :estado, :id_centro)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":nombre",    $this->nombre);
        $stmt->bindParam(":tipo",      $this->tipo);
        $stmt->bindParam(":estado",    $this->estado);
        $stmt->bindParam(":id_centro", $this->id_centro);
        return $stmt->execute();
    }

    public function update() {
        if (!$this->conn) return false;
        $query = "UPDATE " . $this->table_name . "
                  SET nombre=:nombre, tipo=:tipo, estado=:estado, id_centro=:id_centro
                  WHERE id_maquinaria=:id_maquinaria";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_maquinaria", $this->id_maquinaria);
        $stmt->bindParam(":nombre",        $this->nombre);
        $stmt->bindParam(":tipo",          $this->tipo);
        $stmt->bindParam(":estado",        $this->estado);
        $stmt->bindParam(":id_centro",     $this->id_centro);
        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;
        $query = "DELETE FROM " . $this->table_name . " WHERE id_maquinaria=:id_maquinaria";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindParam(":id_maquinaria", $this->id_maquinaria);
        return $stmt->execute();
    }
}
