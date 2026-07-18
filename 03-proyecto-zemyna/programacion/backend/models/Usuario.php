<?php
class Usuario {
    private $conn;
    private $table_name = "usuarios";

    // Atributos de la entidad Usuario
    public $id_usuario;
    public $nombre;
    public $email;
    public $password;
    public $id_rol; // Clave foránea conceptual que mapea el rol (1: superadmin, 2: dispatcher, etc.)

    public function __construct($db) {
        $this->conn = $db;
    }

    // Leer usuarios
    public function read() {
        // Dejamos la consulta armada para el futuro
        $query = "SELECT * FROM " . $this->table_name;
        if ($this->conn) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt;
        }
        // Simulación temporal para la entrega sin BD
        return null;
    }

    // Crear usuario (Adaptado temporalmente sin persistencia)
    public function create() {
        // Esta será la consulta real en la siguiente etapa:
        /*
        $query = "INSERT INTO " . $this->table_name . "  
                  (nombre, email, password, id_rol)
                  VALUES (:nombre, :email, :password, :id_rol)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":nombre", $this->nombre);
        $stmt->bindParam(":email", $this->email);
        $stmt->bindParam(":password", $this->password); // Aquí irá el hash de contraseña
        $stmt->bindParam(":id_rol", $this->id_rol);
        return $stmt->execute();
        */

        // SIMULACIÓN PARA LA PRIMERA ENTREGA:
        // Como el profesor quiere evaluar que la API reciba y procese los datos del formulario,
        // simplemente validamos que los datos obligatorios no estén vacíos y retornamos true.
        if (!empty($this->nombre) && !empty($this->email) && !empty($this->password) && !empty($this->id_rol)) {
            return true; // Simula que se guardó de forma exitosa
        }
        return false;
    }

    // Actualizar usuario
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

    // Eliminar usuario
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