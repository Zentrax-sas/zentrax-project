<?php
class Vehiculo {
    private $conn;
    private string $table_name = "vehiculo";

    public $id_vehiculo;
    public $matricula;
    public $marca;
    public $modelo;
    public $capacidad_carga;
    public $estado;
    public $id_tipo_residuo;

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
        if (empty($this->matricula) || empty($this->marca) || empty($this->modelo) ||
            !isset($this->capacidad_carga) || empty($this->estado) || empty($this->id_tipo_residuo)) {
            return false;
        }
        $query = "INSERT INTO " . $this->table_name . "
                  (matricula, marca, modelo, capacidad_carga, estado, id_tipo_residuo)
                  VALUES (:matricula, :marca, :modelo, :capacidad_carga, :estado, :id_tipo_residuo)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":matricula",       $this->matricula);
        $stmt->bindParam(":marca",           $this->marca);
        $stmt->bindParam(":modelo",          $this->modelo);
        $stmt->bindParam(":capacidad_carga", $this->capacidad_carga);
        $stmt->bindParam(":estado",          $this->estado);
        $stmt->bindParam(":id_tipo_residuo", $this->id_tipo_residuo);
        return $stmt->execute();
    }

    public function update() {
        if (!$this->conn) return false;
        $query = "UPDATE " . $this->table_name . "
                  SET matricula=:matricula, marca=:marca, modelo=:modelo,
                      capacidad_carga=:capacidad_carga, estado=:estado, id_tipo_residuo=:id_tipo_residuo
                  WHERE id_vehiculo=:id_vehiculo";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_vehiculo",     $this->id_vehiculo);
        $stmt->bindParam(":matricula",       $this->matricula);
        $stmt->bindParam(":marca",           $this->marca);
        $stmt->bindParam(":modelo",          $this->modelo);
        $stmt->bindParam(":capacidad_carga", $this->capacidad_carga);
        $stmt->bindParam(":estado",          $this->estado);
        $stmt->bindParam(":id_tipo_residuo", $this->id_tipo_residuo);
        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;
        $query = "DELETE FROM " . $this->table_name . " WHERE id_vehiculo=:id_vehiculo";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindParam(":id_vehiculo", $this->id_vehiculo);
        return $stmt->execute();
    }
}
