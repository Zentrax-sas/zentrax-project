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

    public function read() {
        if (!$this->conn) return null;
        $stmt = $this->conn->prepare("SELECT * FROM " . $this->table_name);
        $stmt->execute();
        return $stmt;
    }

    public function create() {
        if (!$this->conn) return false;
        if (empty($this->nombre) || empty($this->apellido) || empty($this->email) ||
            empty($this->contrasena) || empty($this->telefono) || empty($this->rol) || empty($this->id_centro)) {
            return false;
        }
        $query = "INSERT INTO " . $this->table_name . "
                  (nombre, apellido, email, `contraseña`, telefono, fecha_registro, rol, id_centro)
                  VALUES (:nombre, :apellido, :email, :contrasena, :telefono, :fecha_registro, :rol, :id_centro)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":nombre",         $this->nombre);
        $stmt->bindParam(":apellido",        $this->apellido);
        $stmt->bindParam(":email",           $this->email);
        $stmt->bindParam(":contrasena",       $this->contrasena);
        $stmt->bindParam(":telefono",        $this->telefono);
        $stmt->bindParam(":fecha_registro",  $this->fecha_registro);
        $stmt->bindParam(":rol",             $this->rol);
        $stmt->bindParam(":id_centro",       $this->id_centro);
        return $stmt->execute();
    }

    public function update() {
        if (!$this->conn) return false;
        $query = "UPDATE " . $this->table_name . "
                  SET nombre=:nombre, apellido=:apellido, email=:email, telefono=:telefono,
                      rol=:rol, id_centro=:id_centro
                  WHERE id_usuario=:id_usuario";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_usuario", $this->id_usuario);
        $stmt->bindParam(":nombre",     $this->nombre);
        $stmt->bindParam(":apellido",   $this->apellido);
        $stmt->bindParam(":email",      $this->email);
        $stmt->bindParam(":telefono",   $this->telefono);
        $stmt->bindParam(":rol",        $this->rol);
        $stmt->bindParam(":id_centro",  $this->id_centro);
        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;
        $query = "DELETE FROM " . $this->table_name . " WHERE id_usuario=:id_usuario";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindParam(":id_usuario", $this->id_usuario);
        return $stmt->execute();
    }
}