<?php
require_once __DIR__ . '/../exceptions/PersistenceException.php';

class Vehiculo {
    private $conn;
    private string $table_name = "vehiculo";

    public $id_vehiculo;
    public $matricula;
    public $marca;
    public $modelo;
    public $capacidad_carga;
    public $estado;
    public $activo;
    public $id_tipo_residuo;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function read() {
        if (!$this->conn) throw new PersistenceException('No hay conexión disponible.');
        $stmt = $this->conn->prepare("SELECT * FROM " . $this->table_name . " WHERE activo = 1");
        $stmt->execute();
        return $stmt;
    }

    public function findById($id) {
        if (!$this->conn) {
            throw new PersistenceException('No hay conexión disponible.');
        }

        $stmt = $this->conn->prepare(
            "SELECT * FROM " . $this->table_name . " WHERE id_vehiculo = :id_vehiculo LIMIT 1"
        );
        $stmt->bindValue(':id_vehiculo', (int) $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByMatricula($matricula) {
        if (!$this->conn) {
            throw new PersistenceException('No hay conexión disponible.');
        }

        $stmt = $this->conn->prepare(
            "SELECT * FROM " . $this->table_name . " WHERE matricula = :matricula LIMIT 1"
        );
        $stmt->bindValue(':matricula', trim((string) $matricula));
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create() {
        if (!$this->conn) throw new PersistenceException('No hay conexión disponible.');
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
        if (!$this->conn) throw new PersistenceException('No hay conexión disponible.');
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
        if (!$this->conn) throw new PersistenceException('No hay conexión disponible.');
        $query = "UPDATE " . $this->table_name . "
              SET activo = 0
              WHERE id_vehiculo = :id_vehiculo
              AND activo = 1";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindParam(":id_vehiculo", $this->id_vehiculo);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }
}
