<?php
class Recorre {
    private $conn;
    private string $table_name = "recorre";

    public $id_vehiculo;
    public $id_ruta;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function read(?int $id_vehiculo = null, ?int $id_ruta = null) {
        if (!$this->conn) return null;
        $where = [];
        if ($id_vehiculo) $where[] = "id_vehiculo = :id_vehiculo";
        if ($id_ruta)     $where[] = "id_ruta     = :id_ruta";
        $sql  = "SELECT * FROM " . $this->table_name;
        if ($where) $sql .= " WHERE " . implode(" AND ", $where);
        $stmt = $this->conn->prepare($sql);
        if ($id_vehiculo) $stmt->bindValue(":id_vehiculo", $id_vehiculo, PDO::PARAM_INT);
        if ($id_ruta)     $stmt->bindValue(":id_ruta",     $id_ruta,     PDO::PARAM_INT);
        $stmt->execute();
        return $stmt;
    }

    public function create() {
        if (!$this->conn) return false;
        if (empty($this->id_vehiculo) || empty($this->id_ruta)) return false;
        $query = "INSERT INTO " . $this->table_name . " (id_vehiculo, id_ruta)
                  VALUES (:id_vehiculo, :id_ruta)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_vehiculo", $this->id_vehiculo);
        $stmt->bindParam(":id_ruta",     $this->id_ruta);
        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;
        $query = "DELETE FROM " . $this->table_name . " WHERE id_vehiculo=:id_vehiculo AND id_ruta=:id_ruta";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindParam(":id_vehiculo", $this->id_vehiculo);
        $stmt->bindParam(":id_ruta",     $this->id_ruta);
        return $stmt->execute();
    }
}
