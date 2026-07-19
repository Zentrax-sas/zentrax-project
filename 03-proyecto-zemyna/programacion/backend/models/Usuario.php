<?php
class Usuario {
    private $conn;
    private $table_name = "usuario";

    public $id_usuario;
    public $nombre;
    public $email;
    public $password;
    public $id_rol;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function read() {
        $query = "SELECT * FROM " . $this->table_name;
        if ($this->conn) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt;
        }
        return null;
    }

    public function create() {
        if (!empty($this->nombre) && !empty($this->email) && !empty($this->password) && !empty($this->id_rol)) {
            return true;
        }
        return false;
    }

    public function update() {
        if ($this->conn) {
            $query = "UPDATE " . $this->table_name . " 
                      SET nombre=:nombre, email=:email, password=:password, id_rol=:id_rol
                      WHERE id_usuario=:id_usuario";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":nombre", $this->nombre);
            $stmt->bindParam(":email", $this->email);
            $stmt->bindParam(":password", $this->password);
            $stmt->bindParam(":id_rol", $this->id_rol);
            $stmt->bindParam(":id_usuario", $this->id_usuario);
            return $stmt->execute();
        }
        return true;
    }

    public function delete() {
        if ($this->conn) {
            $query = "DELETE FROM " . $this->table_name . " WHERE id_usuario=:id_usuario";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id_usuario", $this->id_usuario);
            return $stmt->execute();
        }
        return true;
    }
}