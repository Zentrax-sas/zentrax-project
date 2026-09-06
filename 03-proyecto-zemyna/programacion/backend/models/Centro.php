<?php
require_once __DIR__ . '/../exceptions/PersistenceException.php';

class Centro {
    private $conn;
    private string $table_name = "centro";

    public $id_centro;
    public $nombre;
    public $direccion;
    public $telefono;
    public $activo;

    public function __construct($db) { $this->conn = $db; }

    public function read() {
        if (!$this->conn) throw new PersistenceException('No hay conexión disponible.');
        $stmt = $this->conn->prepare(
            "SELECT id_centro, nombre, direccion, telefono, activo
             FROM {$this->table_name} WHERE activo = 1 ORDER BY id_centro ASC"
        );
        $stmt->execute();
        return $stmt;
    }

    public function findById($id) {
        if (!$this->conn) throw new PersistenceException('No hay conexión disponible.');
        $stmt = $this->conn->prepare(
            "SELECT id_centro, nombre, direccion, telefono, activo
             FROM {$this->table_name} WHERE id_centro = :id_centro LIMIT 1"
        );
        $stmt->bindValue(':id_centro', (int) $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findDuplicate($nombre, $direccion) {
        if (!$this->conn) throw new PersistenceException('No hay conexión disponible.');
        $stmt = $this->conn->prepare(
            "SELECT id_centro, nombre, direccion, telefono, activo
             FROM {$this->table_name}
             WHERE nombre = :nombre AND direccion = :direccion LIMIT 1"
        );
        $stmt->bindValue(':nombre', trim((string) $nombre));
        $stmt->bindValue(':direccion', trim((string) $direccion));
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create() {
        if (!$this->conn) throw new PersistenceException('No hay conexión disponible.');
        if (empty($this->nombre) || empty($this->direccion)) return false;
        $stmt = $this->conn->prepare(
            "INSERT INTO {$this->table_name} (nombre, direccion, telefono)
             VALUES (:nombre, :direccion, :telefono)"
        );
        $stmt->bindParam(':nombre', $this->nombre);
        $stmt->bindParam(':direccion', $this->direccion);
        $stmt->bindParam(':telefono', $this->telefono);
        $created = $stmt->execute();
        if ($created) $this->id_centro = (int) $this->conn->lastInsertId();
        return $created;
    }

    public function update() {
        if (!$this->conn) throw new PersistenceException('No hay conexión disponible.');
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table_name}
             SET nombre = :nombre, direccion = :direccion, telefono = :telefono
             WHERE id_centro = :id_centro"
        );
        $stmt->bindParam(':id_centro', $this->id_centro);
        $stmt->bindParam(':nombre', $this->nombre);
        $stmt->bindParam(':direccion', $this->direccion);
        $stmt->bindParam(':telefono', $this->telefono);
        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) throw new PersistenceException('No hay conexión disponible.');
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table_name} SET activo = 0
             WHERE id_centro = :id_centro AND activo = 1"
        );
        $stmt->bindParam(':id_centro', $this->id_centro);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }
}
