<?php
class TipoResiduo {
    private $conn;
    private string $table_name = "tipo_residuo";

    public $id_tipo_residuo;
    public $nombre;
    public $descripcion;

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
        if (empty($this->nombre) || empty($this->descripcion)) return false;
        $query = "INSERT INTO " . $this->table_name . " (nombre, descripcion)
                  VALUES (:nombre, :descripcion)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":nombre",      $this->nombre);
        $stmt->bindParam(":descripcion", $this->descripcion);
        return $stmt->execute();
    }

    public function update() {
        if (!$this->conn) return false;
        $query = "UPDATE " . $this->table_name . "
                  SET nombre=:nombre, descripcion=:descripcion
                  WHERE id_tipo_residuo=:id_tipo_residuo";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_tipo_residuo", $this->id_tipo_residuo);
        $stmt->bindParam(":nombre",          $this->nombre);
        $stmt->bindParam(":descripcion",     $this->descripcion);
        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;
        $query = "DELETE FROM " . $this->table_name . " WHERE id_tipo_residuo=:id_tipo_residuo";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindParam(":id_tipo_residuo", $this->id_tipo_residuo);
        return $stmt->execute();
    }
}
