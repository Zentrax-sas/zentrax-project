<?php
require_once __DIR__ . '/../exceptions/PersistenceException.php';

class Maquinaria {
    private $conn;
    private string $table_name = 'maquinaria';
    public $id_maquinaria;
    public $nombre;
    public $tipo;
    public $estado;
    public $activo;
    public $id_centro;

    public function __construct($db) { $this->conn = $db; }

    public function read() {
        if (!$this->conn) throw new PersistenceException('No hay conexión disponible.');
        $stmt = $this->conn->prepare("SELECT id_maquinaria, nombre, tipo, estado, activo, id_centro FROM {$this->table_name} WHERE activo = 1 ORDER BY id_maquinaria ASC");
        $stmt->execute();
        return $stmt;
    }

    public function findById($id) {
        if (!$this->conn) throw new PersistenceException('No hay conexión disponible.');
        $stmt = $this->conn->prepare("SELECT id_maquinaria, nombre, tipo, estado, activo, id_centro FROM {$this->table_name} WHERE id_maquinaria = :id_maquinaria LIMIT 1");
        $stmt->bindValue(':id_maquinaria', (int) $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findDuplicate($nombre, $idCentro) {
        if (!$this->conn) throw new PersistenceException('No hay conexión disponible.');
        $stmt = $this->conn->prepare("SELECT id_maquinaria, nombre, tipo, estado, activo, id_centro FROM {$this->table_name} WHERE nombre = :nombre AND id_centro = :id_centro LIMIT 1");
        $stmt->bindValue(':nombre', trim((string) $nombre));
        $stmt->bindValue(':id_centro', (int) $idCentro, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findCentroById($idCentro) {
        if (!$this->conn) throw new PersistenceException('No hay conexión disponible.');
        $stmt = $this->conn->prepare('SELECT id_centro FROM centro WHERE id_centro = :id_centro AND activo = 1 LIMIT 1');
        $stmt->bindValue(':id_centro', (int) $idCentro, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create() {
        if (!$this->conn) throw new PersistenceException('No hay conexión disponible.');
        $stmt = $this->conn->prepare("INSERT INTO {$this->table_name} (nombre, tipo, estado, id_centro) VALUES (:nombre, :tipo, :estado, :id_centro)");
        $stmt->bindParam(':nombre', $this->nombre);
        $stmt->bindParam(':tipo', $this->tipo);
        $stmt->bindParam(':estado', $this->estado);
        $stmt->bindParam(':id_centro', $this->id_centro);
        $created = $stmt->execute();
        if ($created) $this->id_maquinaria = (int) $this->conn->lastInsertId();
        return $created;
    }

    public function update() {
        if (!$this->conn) throw new PersistenceException('No hay conexión disponible.');
        $stmt = $this->conn->prepare("UPDATE {$this->table_name} SET nombre = :nombre, tipo = :tipo, estado = :estado, id_centro = :id_centro WHERE id_maquinaria = :id_maquinaria");
        $stmt->bindParam(':id_maquinaria', $this->id_maquinaria);
        $stmt->bindParam(':nombre', $this->nombre);
        $stmt->bindParam(':tipo', $this->tipo);
        $stmt->bindParam(':estado', $this->estado);
        $stmt->bindParam(':id_centro', $this->id_centro);
        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) throw new PersistenceException('No hay conexión disponible.');
        $stmt = $this->conn->prepare("UPDATE {$this->table_name} SET activo = 0 WHERE id_maquinaria = :id_maquinaria AND activo = 1");
        $stmt->bindParam(':id_maquinaria', $this->id_maquinaria);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }
}
