<?php
class Solicitud {
    private $conn;
    private string $table_name = "solicitud";

    public $id;
    public $direccion;
    public $email;
    public $telefono;
    public $descripcion;
    public $tipo_solicitud;
    public $tracking_number;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function create() {
        // Validar campos obligatorios
        if (empty($this->direccion) || empty($this->email) || empty($this->telefono) || 
            empty($this->descripcion) || empty($this->tipo_solicitud)) {
            return false;
        }

        // Validar formato de email
        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return true;
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

    public function update() {
        if ($this->conn) {
            $query = "UPDATE " . $this->table_name . " 
                      SET direccion=:direccion, email=:email, telefono=:telefono, 
                          descripcion=:descripcion, tipo_solicitud=:tipo_solicitud
                      WHERE id=:id";
            return true;
        }
        return true;
    }

    public function delete() {
        if ($this->conn) {
            $query = "DELETE FROM " . $this->table_name . " WHERE id=:id";
            return true;
        }
        return true;
    }
}
