<?php
class Reclamo {
    private $conn;
    private string $table_name = "reclamo";

    public $id_reclamo;
    public $fecha;
    public $descripcion;
    public $estado;
    public $ci;
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
        if (empty($this->fecha) || empty($this->descripcion) || empty($this->estado) ||
            empty($this->ci) || empty($this->id_incidencia)) {
            return false;
        }
        $query = "INSERT INTO " . $this->table_name . " (fecha, descripcion, estado, ci, id_incidencia)
                  VALUES (:fecha, :descripcion, :estado, :ci, :id_incidencia)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":fecha",         $this->fecha);
        $stmt->bindParam(":descripcion",   $this->descripcion);
        $stmt->bindParam(":estado",        $this->estado);
        $stmt->bindParam(":ci",            $this->ci);
        $stmt->bindParam(":id_incidencia", $this->id_incidencia);
        return $stmt->execute();
    }

    public function update() {
        if (!$this->conn) return false;
        $query = "UPDATE " . $this->table_name . "
                  SET fecha=:fecha, descripcion=:descripcion, estado=:estado,
                      ci=:ci, id_incidencia=:id_incidencia
                  WHERE id_reclamo=:id_reclamo";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_reclamo",    $this->id_reclamo);
        $stmt->bindParam(":fecha",         $this->fecha);
        $stmt->bindParam(":descripcion",   $this->descripcion);
        $stmt->bindParam(":estado",        $this->estado);
        $stmt->bindParam(":ci",            $this->ci);
        $stmt->bindParam(":id_incidencia", $this->id_incidencia);
        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;
        $query = "DELETE FROM " . $this->table_name . " WHERE id_reclamo=:id_reclamo";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindParam(":id_reclamo", $this->id_reclamo);
        return $stmt->execute();
    }
}
