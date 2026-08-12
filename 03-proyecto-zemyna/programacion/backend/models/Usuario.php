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
    public $activo;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function read($id = null, $page = 1, $limit = 20) {
        if (!$this->conn) return null;

        $where = ' WHERE activo = 1';
        $params = [];

        if ($id !== null && $id !== '') {
            $where .= ' AND id_usuario = :id_usuario';
            $params[':id_usuario'] = $id;
        }

        $offset = ($page - 1) * $limit;
        $query = 'SELECT * FROM ' . $this->table_name . $where . ' ORDER BY id_usuario ASC LIMIT :limit OFFSET :offset';
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
        $query = "SELECT * FROM " . $this->table_name . " WHERE email = :email AND activo = 1 LIMIT 1";
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
        $this->activo = $this->activo ?? 1;
        $query = "INSERT INTO " . $this->table_name . "
                  (nombre, apellido, email, `contraseña`, telefono, fecha_registro, rol, id_centro, activo)
                  VALUES (:nombre, :apellido, :email, :contrasena, :telefono, :fecha_registro, :rol, :id_centro, :activo)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":nombre",         $this->nombre);
        $stmt->bindParam(":apellido",        $this->apellido);
        $stmt->bindParam(":email",           $this->email);
        $stmt->bindParam(":contrasena",       $this->contrasena);
        $stmt->bindParam(":telefono",        $this->telefono);
        $stmt->bindParam(":fecha_registro",  $this->fecha_registro);
        $stmt->bindParam(":rol",             $this->rol);
        $stmt->bindParam(":id_centro",       $this->id_centro);
        $stmt->bindParam(":activo",          $this->activo);
        return $stmt->execute();
    }

    public function update() {
        if (!$this->conn) return false;

        $query = "UPDATE " . $this->table_name . "
                  SET nombre=:nombre, apellido=:apellido, email=:email, telefono=:telefono,
                      rol=:rol, id_centro=:id_centro";

        if (!empty($this->contrasena)) {
            $query .= ", contraseña=:contrasena";
        }

        $query .= " WHERE id_usuario=:id_usuario";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_usuario", $this->id_usuario);
        $stmt->bindParam(":nombre",     $this->nombre);
        $stmt->bindParam(":apellido",   $this->apellido);
        $stmt->bindParam(":email",      $this->email);
        $stmt->bindParam(":telefono",   $this->telefono);
        $stmt->bindParam(":rol",        $this->rol);
        $stmt->bindParam(":id_centro",  $this->id_centro);

        if (!empty($this->contrasena)) {
            $stmt->bindParam(":contrasena", $this->contrasena);
        }

        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;
        $query = "UPDATE " . $this->table_name . " SET activo = 0 WHERE id_usuario=:id_usuario";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindParam(":id_usuario", $this->id_usuario);
        return $stmt->execute();
    }
}