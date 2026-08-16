<?php
class Usuario {
    private $conn;
    private string $table_name = "usuario";

    public $id_usuario;
    public $nombre;
    public $apellido;
    public $email;
    public $contrasena;
    public $telefono;
    public $fecha_registro;
    public $rol;
    public $id_centro;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function read($id = null, $page = 1, $limit = 20) {
        if (!$this->conn) return null;

        $where = '';
        $params = [];

        if ($id !== null && $id !== '') {
            $where .= ' WHERE u.id_usuario = :id_usuario';
            $params[':id_usuario'] = $id;
        }

        $offset = ($page - 1) * $limit;
                $query = 'SELECT u.*, r.nombre AS rol
                                    FROM ' . $this->table_name . ' u
                                    LEFT JOIN usuario_rol ur
                                        ON ur.id_usuario = u.id_usuario AND ur.fecha_hasta IS NULL
                                    LEFT JOIN rol r ON r.id_rol = ur.id_rol' . $where . '
                                    ORDER BY u.id_usuario ASC LIMIT :limit OFFSET :offset';
        $stmt = $this->conn->prepare($query);

        if ($id !== null && $id !== '') {
            $stmt->bindValue(':id_usuario', $id, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt;
    }

    public function findByEmail($email) {
        if (!$this->conn) return null;
                $query = "SELECT u.*, r.nombre AS rol
                                    FROM " . $this->table_name . " u
                                    LEFT JOIN usuario_rol ur
                                        ON ur.id_usuario = u.id_usuario AND ur.fecha_hasta IS NULL
                                    LEFT JOIN rol r ON r.id_rol = ur.id_rol
                                    WHERE u.email = :email
                                    ORDER BY ur.fecha_desde DESC
                                    LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create() {
        if (!$this->conn) return false;
        if (empty($this->nombre) || empty($this->apellido) || empty($this->email) ||
            empty($this->contrasena) || empty($this->telefono) || empty($this->rol) || empty($this->id_centro)) {
            return false;
        }
        try {
            $this->conn->beginTransaction();

            $query = "INSERT INTO " . $this->table_name . "
                      (nombre, apellido, email, contrasena, telefono, fecha_registro, id_centro)
                      VALUES (:nombre, :apellido, :email, :contrasena, :telefono, :fecha_registro, :id_centro)";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':nombre' => $this->nombre,
                ':apellido' => $this->apellido,
                ':email' => $this->email,
                ':contrasena' => $this->contrasena,
                ':telefono' => $this->telefono,
                ':fecha_registro' => $this->fecha_registro,
                ':id_centro' => $this->id_centro,
            ]);

            $this->id_usuario = (int) $this->conn->lastInsertId();
            $this->assignRole($this->id_usuario, $this->rol, $this->fecha_registro ?: date('Y-m-d'));
            $this->conn->commit();
            return true;
        } catch (PDOException | RuntimeException $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            return false;
        }
    }

    public function update() {
        if (!$this->conn) return false;

        try {
            $this->conn->beginTransaction();
            $query = "UPDATE " . $this->table_name . "
                      SET nombre=:nombre, apellido=:apellido, email=:email, telefono=:telefono,
                          id_centro=:id_centro";

            if (!empty($this->contrasena)) {
                $query .= ", contrasena=:contrasena";
            }

            $query .= " WHERE id_usuario=:id_usuario";
            $stmt = $this->conn->prepare($query);
            $params = [
                ':id_usuario' => $this->id_usuario,
                ':nombre' => $this->nombre,
                ':apellido' => $this->apellido,
                ':email' => $this->email,
                ':telefono' => $this->telefono,
                ':id_centro' => $this->id_centro,
            ];

            if (!empty($this->contrasena)) $params[':contrasena'] = $this->contrasena;
            $stmt->execute($params);
            $this->replaceRole($this->id_usuario, $this->rol);
            $this->conn->commit();
            return true;
        } catch (PDOException | RuntimeException $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            return false;
        }
    }

    public function delete() {
        if (!$this->conn) return false;
        $query = "DELETE FROM " . $this->table_name . " WHERE id_usuario=:id_usuario";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindParam(":id_usuario", $this->id_usuario);
        return $stmt->execute();
    }

    private function getRoleId($roleName) {
        $stmt = $this->conn->prepare('SELECT id_rol FROM rol WHERE nombre = :nombre LIMIT 1');
        $stmt->execute([':nombre' => $roleName]);
        $roleId = $stmt->fetchColumn();
        return $roleId === false ? null : (int) $roleId;
    }

    private function assignRole($userId, $roleName, $startDate) {
        $roleId = $this->getRoleId($roleName);
        if ($roleId === null) throw new RuntimeException('El rol indicado no existe.');

        $stmt = $this->conn->prepare(
            'INSERT INTO usuario_rol (id_usuario, id_rol, sector, fecha_desde)
             VALUES (:id_usuario, :id_rol, :sector, :fecha_desde)'
        );
        $stmt->execute([
            ':id_usuario' => $userId,
            ':id_rol' => $roleId,
            ':sector' => 'General',
            ':fecha_desde' => $startDate,
        ]);
    }

    private function replaceRole($userId, $roleName) {
        $currentRole = $this->conn->prepare(
            'SELECT r.nombre FROM usuario_rol ur
             INNER JOIN rol r ON r.id_rol = ur.id_rol
             WHERE ur.id_usuario = :id_usuario AND ur.fecha_hasta IS NULL
             ORDER BY ur.fecha_desde DESC LIMIT 1'
        );
        $currentRole->execute([':id_usuario' => $userId]);
        if ($currentRole->fetchColumn() === $roleName) return;

        $close = $this->conn->prepare(
            'UPDATE usuario_rol SET fecha_hasta = CURDATE()
             WHERE id_usuario = :id_usuario AND fecha_hasta IS NULL'
        );
        $close->execute([':id_usuario' => $userId]);
        $this->assignRole($userId, $roleName, date('Y-m-d'));
    }
}