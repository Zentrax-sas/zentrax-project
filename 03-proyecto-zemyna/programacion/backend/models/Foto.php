<?php
class Foto {
    private $conn;
    private string $table_name = "foto";

    public $id_foto;
    public $fecha;
    public $url;
    public $id_incidencia;

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
        if (empty($this->fecha) || empty($this->url) || empty($this->id_incidencia)) return false;
        $query = "INSERT INTO " . $this->table_name . " (fecha, url, id_incidencia)
                  VALUES (:fecha, :url, :id_incidencia)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":fecha",         $this->fecha);
        $stmt->bindParam(":url",           $this->url);
        $stmt->bindParam(":id_incidencia", $this->id_incidencia);
        return $stmt->execute();
    }

    public function update() {
        if (!$this->conn) return false;
        $query = "UPDATE " . $this->table_name . "
                  SET fecha=:fecha, url=:url, id_incidencia=:id_incidencia
                  WHERE id_foto=:id_foto";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_foto",       $this->id_foto);
        $stmt->bindParam(":fecha",         $this->fecha);
        $stmt->bindParam(":url",           $this->url);
        $stmt->bindParam(":id_incidencia", $this->id_incidencia);
        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;
        $query = "DELETE FROM " . $this->table_name . " WHERE id_foto=:id_foto";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindParam(":id_foto", $this->id_foto);
        return $stmt->execute();
    }
}
