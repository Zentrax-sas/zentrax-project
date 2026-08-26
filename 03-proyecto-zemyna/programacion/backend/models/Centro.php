<?php
class Centro {
    private $conn;
    private string $table_name = "centro";

    public $id_centro;
    public $nombre;
    public $direccion;
    public $telefono;
    public $activo;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function read() {
        if (!$this->conn) return null;
        $stmt = $this->conn->prepare("SELECT * FROM " . $this->table_name . " WHERE activo = 1");
        $stmt->execute();
        return $stmt;
    }

    public function create() {
        if (!$this->conn) return false;
        if (empty($this->nombre) || empty($this->direccion) || empty($this->telefono)) return false;
        $query = "INSERT INTO " . $this->table_name . " (nombre, direccion, telefono)
                  VALUES (:nombre, :direccion, :telefono)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":nombre",    $this->nombre);
        $stmt->bindParam(":direccion", $this->direccion);
        $stmt->bindParam(":telefono",  $this->telefono);
        return $stmt->execute();
    }

    public function update() {
        if (!$this->conn) return false;
        $query = "UPDATE " . $this->table_name . "
                  SET nombre=:nombre, direccion=:direccion, telefono=:telefono
                  WHERE id_centro=:id_centro";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_centro",  $this->id_centro);
        $stmt->bindParam(":nombre",     $this->nombre);
        $stmt->bindParam(":direccion",  $this->direccion);
        $stmt->bindParam(":telefono",   $this->telefono);
        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;
        $query = "UPDATE " . $this->table_name . "
              SET activo = 0
              WHERE id_centro = :id_centro
              AND activo = 1";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindParam(":id_centro", $this->id_centro);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }
}
