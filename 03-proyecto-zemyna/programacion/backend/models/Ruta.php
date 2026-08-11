<?php
class Ruta {
    private $conn;
    private string $table_name = "ruta";

    public $id_ruta;
    public $nombre;
    public $zona;

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
        if (empty($this->nombre) || empty($this->zona)) return false;
        $query = "INSERT INTO " . $this->table_name . " (nombre, zona)
                  VALUES (:nombre, :zona)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":nombre", $this->nombre);
        $stmt->bindParam(":zona",   $this->zona);
        return $stmt->execute();
    }

    public function update() {
        if (!$this->conn) return false;
        $query = "UPDATE " . $this->table_name . "
                  SET nombre=:nombre, zona=:zona
                  WHERE id_ruta=:id_ruta";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_ruta", $this->id_ruta);
        $stmt->bindParam(":nombre",  $this->nombre);
        $stmt->bindParam(":zona",    $this->zona);
        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;
        $query = "DELETE FROM " . $this->table_name . " WHERE id_ruta=:id_ruta";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindParam(":id_ruta", $this->id_ruta);
        return $stmt->execute();
    }
}
