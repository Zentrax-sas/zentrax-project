<?php
class Vecino {
    private $conn;
    private string $table_name = "vecino";

    public $ci;
    public $nombre;
    public $apellido;
    public $telefono;

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
        if (empty($this->ci) || empty($this->nombre) || empty($this->apellido) || empty($this->telefono)) {
            return false;
        }
        $query = "INSERT INTO " . $this->table_name . " (ci, nombre, apellido, telefono)
                  VALUES (:ci, :nombre, :apellido, :telefono)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":ci",       $this->ci);
        $stmt->bindParam(":nombre",   $this->nombre);
        $stmt->bindParam(":apellido", $this->apellido);
        $stmt->bindParam(":telefono", $this->telefono);
        return $stmt->execute();
    }

    public function update() {
        if (!$this->conn) return false;
        $query = "UPDATE " . $this->table_name . "
                  SET nombre=:nombre, apellido=:apellido, telefono=:telefono
                  WHERE ci=:ci";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":ci",       $this->ci);
        $stmt->bindParam(":nombre",   $this->nombre);
        $stmt->bindParam(":apellido", $this->apellido);
        $stmt->bindParam(":telefono", $this->telefono);
        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;
        $query = "DELETE FROM " . $this->table_name . " WHERE ci=:ci";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindParam(":ci", $this->ci);
        return $stmt->execute();
    }
}
