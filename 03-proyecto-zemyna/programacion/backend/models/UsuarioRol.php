<?php

class UsuarioRol {
    private $conn;
    private string $table_name = "usuario_rol";

    public $id_usuario_rol;
    public $id_usuario;
    public $id_rol;
    public $sector;
    public $fecha_desde;
    public $fecha_hasta;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function readByUsuario($idUsuario) {
        if (!$this->conn) return null;

        $query = "SELECT ur.*, r.nombre AS rol, r.descripcion
                  FROM " . $this->table_name . " ur
                  INNER JOIN rol r ON r.id_rol = ur.id_rol
                  WHERE ur.id_usuario = :id_usuario
                  ORDER BY ur.fecha_desde DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id_usuario', (int)$idUsuario, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt;
    }

    public function create() {
        if (!$this->conn) return false;

        $query = "INSERT INTO " . $this->table_name . "
                  (id_usuario, id_rol, sector, fecha_desde, fecha_hasta)
                  VALUES (:id_usuario, :id_rol, :sector, :fecha_desde, :fecha_hasta)";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':id_usuario', $this->id_usuario);
        $stmt->bindParam(':id_rol', $this->id_rol);
        $stmt->bindParam(':sector', $this->sector);
        $stmt->bindParam(':fecha_desde', $this->fecha_desde);
        $stmt->bindParam(':fecha_hasta', $this->fecha_hasta);

        if ($stmt->execute()) {
            $this->id_usuario_rol = (int)$this->conn->lastInsertId();
            return true;
        }

        return false;
    }

    public function finalizar() {
        if (!$this->conn) return false;

        $query = "UPDATE " . $this->table_name . "
                  SET fecha_hasta = :fecha_hasta
                  WHERE id_usuario_rol = :id_usuario_rol";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':fecha_hasta', $this->fecha_hasta);
        $stmt->bindParam(':id_usuario_rol', $this->id_usuario_rol);

        return $stmt->execute();
    }

    public function existeRolVigente($idUsuario, $idRol, $sector) {
        if (!$this->conn) return false;

        $query = "SELECT COUNT(*)
                  FROM " . $this->table_name . "
                  WHERE id_usuario = :id_usuario
                  AND id_rol = :id_rol
                  AND sector = :sector
                  AND fecha_desde <= CURDATE()
                  AND (fecha_hasta IS NULL OR fecha_hasta >= CURDATE())";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':id_usuario' => $idUsuario,
            ':id_rol' => $idRol,
            ':sector' => $sector
        ]);

        return (int)$stmt->fetchColumn() > 0;
    }

    public function findById($id) {
        if (!$this->conn) return null;

        $query = "SELECT *
                  FROM " . $this->table_name . "
                  WHERE id_usuario_rol = :id_usuario_rol
                  LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id_usuario_rol', (int)$id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}