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
    public $id_centro;
    public $activo;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function read($id = null, $page = 1, $limit = 20) {
        if (!$this->conn) return null;

        $where = '';
        if ($id !== null && $id !== '') {
            $where = ' WHERE id_usuario = :id_usuario';
        }

        $offset = ($page - 1) * $limit;

        $query = "SELECT * FROM " . $this->table_name . $where . "
                  ORDER BY id_usuario ASC
                  LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($query);

        if ($id !== null && $id !== '') {
            $stmt->bindValue(':id_usuario', (int)$id, PDO::PARAM_INT);
        }

        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt;
    }

    public function findByEmail($email) {
        if (!$this->conn) return null;

        $query = "SELECT * FROM " . $this->table_name . "
                  WHERE email = :email
                  LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create() {
        if (!$this->conn) return false;

        if (empty($this->nombre) || empty($this->apellido) || empty($this->email) ||
            empty($this->contrasena) || empty($this->telefono) ||
            empty($this->fecha_registro) || empty($this->id_centro)) {
            return false;
        }

        $this->activo = $this->activo ?? 'Activo';

        $query = "INSERT INTO " . $this->table_name . "
                  (nombre, apellido, email, contrasena, telefono, fecha_registro, id_centro, activo)
                  VALUES (:nombre, :apellido, :email, :contrasena, :telefono, :fecha_registro, :id_centro, :activo)";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':nombre', $this->nombre);
        $stmt->bindParam(':apellido', $this->apellido);
        $stmt->bindParam(':email', $this->email);
        $stmt->bindParam(':contrasena', $this->contrasena);
        $stmt->bindParam(':telefono', $this->telefono);
        $stmt->bindParam(':fecha_registro', $this->fecha_registro);
        $stmt->bindParam(':id_centro', $this->id_centro);
        $stmt->bindParam(':activo', $this->activo);

        if ($stmt->execute()) {
            $this->id_usuario = (int)$this->conn->lastInsertId();
            return true; }

        return false;
    }

    public function beginTransaction(): bool {
        return $this->conn ? $this->conn->beginTransaction() : false;
    }

    public function commit(): bool {
        return $this->conn ? $this->conn->commit() : false;
    }

    public function rollBack(): bool {
        if (!$this->conn || !$this->conn->inTransaction()) return false;
        return $this->conn->rollBack();
    }

    public function findRoleById(int $idRol): ?array {
        if (!$this->conn) return null;
        $stmt = $this->conn->prepare('SELECT id_rol, nombre, descripcion FROM rol WHERE id_rol = :id_rol LIMIT 1');
        $stmt->bindValue(':id_rol', $idRol, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findSectorByName(string $sector): ?array {
        if (!$this->conn) return null;
        $stmt = $this->conn->prepare('SELECT id_sector, nombre, descripcion FROM sector WHERE nombre = :nombre LIMIT 1');
        $stmt->bindValue(':nombre', $sector);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getRolesDisponibles(): array {
        if (!$this->conn) return [];
        $stmt = $this->conn->prepare('SELECT id_rol, nombre, descripcion FROM rol ORDER BY nombre');
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSectoresDisponibles(): array {
        if (!$this->conn) return [];
        $stmt = $this->conn->prepare('SELECT id_sector, nombre, descripcion FROM sector ORDER BY nombre');
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function assignRole(int $idUsuario, int $idRol, string $sector, string $fechaDesde, ?string $fechaHasta): bool {
        if (!$this->conn) return false;
        $stmt = $this->conn->prepare(
            'INSERT INTO usuario_rol (id_usuario, id_rol, sector, fecha_desde, fecha_hasta)
             SELECT :id_usuario, :id_rol, :sector, :fecha_desde, :fecha_hasta
             WHERE NOT EXISTS (
                 SELECT 1 FROM usuario_rol
                 WHERE id_usuario = :check_usuario AND id_rol = :check_rol
                   AND sector = :check_sector AND fecha_desde = :check_desde
                   AND (fecha_hasta <=> :check_hasta)
             )'
        );
        $stmt->execute([
            ':id_usuario' => $idUsuario, ':id_rol' => $idRol, ':sector' => $sector,
            ':fecha_desde' => $fechaDesde, ':fecha_hasta' => $fechaHasta,
            ':check_usuario' => $idUsuario, ':check_rol' => $idRol, ':check_sector' => $sector,
            ':check_desde' => $fechaDesde, ':check_hasta' => $fechaHasta,
        ]);
        return $stmt->rowCount() === 1;
    }

    public function getAsignacionVigente(int $idUsuario): ?array {
        if (!$this->conn) return null;
        $stmt = $this->conn->prepare(
            'SELECT id_usuario_rol, id_usuario, id_rol, sector, fecha_desde, fecha_hasta
             FROM usuario_rol
             WHERE id_usuario = :id_usuario AND fecha_desde <= CURDATE()
               AND (fecha_hasta IS NULL OR fecha_hasta >= CURDATE())
             ORDER BY fecha_desde DESC, id_usuario_rol DESC LIMIT 1'
        );
        $stmt->bindValue(':id_usuario', $idUsuario, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function finalizarAsignacion(int $idAsignacion, string $fechaHasta): bool {
        if (!$this->conn) return false;
        $stmt = $this->conn->prepare(
            'UPDATE usuario_rol SET fecha_hasta = :fecha_hasta
             WHERE id_usuario_rol = :id AND fecha_desde <= :fecha_hasta'
        );
        $stmt->execute([':fecha_hasta' => $fechaHasta, ':id' => $idAsignacion]);
        return $stmt->rowCount() === 1;
    }

    public function actualizarVigenciaAsignacion(int $idAsignacion, ?string $fechaHasta): bool {
        if (!$this->conn) return false;
        $stmt = $this->conn->prepare(
            'UPDATE usuario_rol SET fecha_hasta = :fecha_hasta WHERE id_usuario_rol = :id'
        );
        $stmt->bindValue(':fecha_hasta', $fechaHasta);
        $stmt->bindValue(':id', $idAsignacion, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function actualizarAsignacion(int $idAsignacion, int $idRol, string $sector, string $fechaDesde, ?string $fechaHasta): bool {
        if (!$this->conn) return false;
        $stmt = $this->conn->prepare(
            'UPDATE usuario_rol
             SET id_rol = :id_rol, sector = :sector, fecha_desde = :fecha_desde, fecha_hasta = :fecha_hasta
             WHERE id_usuario_rol = :id'
        );
        return $stmt->execute([
            ':id_rol' => $idRol, ':sector' => $sector, ':fecha_desde' => $fechaDesde,
            ':fecha_hasta' => $fechaHasta, ':id' => $idAsignacion,
        ]);
    }

    public function update() {
        if (!$this->conn) return false;

        $query = "UPDATE " . $this->table_name . "
                  SET nombre = :nombre,
                      apellido = :apellido,
                      email = :email,
                      telefono = :telefono,
                      id_centro = :id_centro,
                      activo = :activo";

        if (!empty($this->contrasena)) {
            $query .= ", contrasena = :contrasena";
        }

        $query .= " WHERE id_usuario = :id_usuario";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':id_usuario', $this->id_usuario);
        $stmt->bindParam(':nombre', $this->nombre);
        $stmt->bindParam(':apellido', $this->apellido);
        $stmt->bindParam(':email', $this->email);
        $stmt->bindParam(':telefono', $this->telefono);
        $stmt->bindParam(':id_centro', $this->id_centro);
        $stmt->bindParam(':activo', $this->activo);

        if (!empty($this->contrasena)) {
            $stmt->bindParam(':contrasena', $this->contrasena);
        }

        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;

        $query = "UPDATE " . $this->table_name . "
                  SET activo = 'Inactivo'
                  WHERE id_usuario = :id_usuario";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_usuario', $this->id_usuario);

        return $stmt->execute();
    }

    public function activar() {
        if (!$this->conn) return false;

        $query = "UPDATE " . $this->table_name . "
                  SET activo = 'Activo'
                  WHERE id_usuario = :id_usuario";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_usuario', $this->id_usuario);

        return $stmt->execute();
    }

    public function getRolesVigentes($idUsuario) {
        if (!$this->conn) return [];

        $query = "SELECT ur.id_usuario_rol, r.id_rol, r.nombre, r.descripcion,
                         ur.sector, ur.fecha_desde, ur.fecha_hasta
                  FROM usuario_rol ur
                  INNER JOIN rol r ON r.id_rol = ur.id_rol
                  WHERE ur.id_usuario = :id_usuario
                  AND ur.fecha_desde <= CURDATE()
                  AND (ur.fecha_hasta IS NULL OR ur.fecha_hasta >= CURDATE())
                  ORDER BY ur.fecha_desde DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id_usuario', (int)$idUsuario, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPermisosVigentes($idUsuario) {
        if (!$this->conn) return [];

        $query = "SELECT DISTINCT p.nombre
                  FROM usuario_rol ur
                  INNER JOIN rol_permiso rp ON rp.id_rol = ur.id_rol
                  INNER JOIN permiso p ON p.id_permiso = rp.id_permiso
                  INNER JOIN rol r ON r.id_rol = ur.id_rol
                  WHERE ur.id_usuario = :id_usuario
                  AND ur.fecha_desde <= CURDATE()
                  AND (ur.fecha_hasta IS NULL OR ur.fecha_hasta >= CURDATE())
                  ORDER BY p.nombre ASC";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id_usuario', (int)$idUsuario, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $exception) {
            return [];
        }
    }

    public function getAutorizacionesVigentes($idUsuario) {
        if (!$this->conn) return [];

        $query = "SELECT r.nombre AS rol, ur.sector, p.nombre AS permiso
                  FROM usuario_rol ur
                  INNER JOIN rol r ON r.id_rol = ur.id_rol
                  INNER JOIN rol_permiso rp ON rp.id_rol = ur.id_rol
                  INNER JOIN permiso p ON p.id_permiso = rp.id_permiso
                  WHERE ur.id_usuario = :id_usuario
                  AND ur.fecha_desde <= CURDATE()
                  AND (ur.fecha_hasta IS NULL OR ur.fecha_hasta >= CURDATE())
                  ORDER BY r.nombre, ur.sector, p.nombre";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id_usuario', (int)$idUsuario, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            return [];
        }
    }

    public function getHistorialRoles($idUsuario) {
        if (!$this->conn) return [];

        $query = "SELECT ur.id_usuario_rol, r.id_rol, r.nombre, r.descripcion,
                         ur.sector, ur.fecha_desde, ur.fecha_hasta
                  FROM usuario_rol ur
                  INNER JOIN rol r ON r.id_rol = ur.id_rol
                  WHERE ur.id_usuario = :id_usuario
                  ORDER BY ur.fecha_desde DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id_usuario', (int)$idUsuario, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
