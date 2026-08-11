<?php
class Usa {
    private $conn;
    private string $table_name = "usa";

    public $id_cuadrilla;
    public $id_vehiculo;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Filtrable por id_cuadrilla o id_vehiculo vía query param
    public function read(?int $id_cuadrilla = null, ?int $id_vehiculo = null) {
        if (!$this->conn) return null;
        $where = [];
        if ($id_cuadrilla) $where[] = "id_cuadrilla = :id_cuadrilla";
        if ($id_vehiculo)  $where[] = "id_vehiculo  = :id_vehiculo";
        $sql  = "SELECT * FROM " . $this->table_name;
        if ($where) $sql .= " WHERE " . implode(" AND ", $where);
        $stmt = $this->conn->prepare($sql);
        if ($id_cuadrilla) $stmt->bindValue(":id_cuadrilla", $id_cuadrilla, PDO::PARAM_INT);
        if ($id_vehiculo)  $stmt->bindValue(":id_vehiculo",  $id_vehiculo,  PDO::PARAM_INT);
        $stmt->execute();
        return $stmt;
    }

    public function create() {
        if (!$this->conn) return false;
        if (empty($this->id_cuadrilla) || empty($this->id_vehiculo)) return false;
        $query = "INSERT INTO " . $this->table_name . " (id_cuadrilla, id_vehiculo)
                  VALUES (:id_cuadrilla, :id_vehiculo)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_cuadrilla", $this->id_cuadrilla);
        $stmt->bindParam(":id_vehiculo",  $this->id_vehiculo);
        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;
        $query = "DELETE FROM " . $this->table_name . " WHERE id_cuadrilla=:id_cuadrilla AND id_vehiculo=:id_vehiculo";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindParam(":id_cuadrilla", $this->id_cuadrilla);
        $stmt->bindParam(":id_vehiculo",  $this->id_vehiculo);
        return $stmt->execute();
    }
}
