<?php
class Solicitud {
    private $conn;
    private string $table_name = "solicitud";

    public $id_solicitud;
    public $fecha;
    public $descripcion;
    public $direccion;
    public $estado;
    public $ci;
    public $id_tipo_residuo;
    public $email;
    public $telefono;
    public $tipo_solicitud;
    public $tracking_number; // generado en el controller, no persiste en BD

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
        if (empty($this->descripcion) || empty($this->direccion) || empty($this->estado) ||
            empty($this->ci) || empty($this->id_tipo_residuo) ||
            empty($this->email) || empty($this->telefono) || empty($this->tipo_solicitud)) {
            return false;
        }
        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) return false;
        $query = "INSERT INTO " . $this->table_name . "
                  (fecha, descripcion, direccion, estado, ci, id_tipo_residuo, email, telefono, tipo_solicitud)
                  VALUES (:fecha, :descripcion, :direccion, :estado, :ci, :id_tipo_residuo, :email, :telefono, :tipo_solicitud)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":fecha",           $this->fecha);
        $stmt->bindParam(":descripcion",     $this->descripcion);
        $stmt->bindParam(":direccion",       $this->direccion);
        $stmt->bindParam(":estado",          $this->estado);
        $stmt->bindParam(":ci",              $this->ci);
        $stmt->bindParam(":id_tipo_residuo", $this->id_tipo_residuo);
        $stmt->bindParam(":email",           $this->email);
        $stmt->bindParam(":telefono",        $this->telefono);
        $stmt->bindParam(":tipo_solicitud",  $this->tipo_solicitud);
        return $stmt->execute();
    }

    public function update() {
        if (!$this->conn) return false;
        $query = "UPDATE " . $this->table_name . "
                  SET descripcion=:descripcion, direccion=:direccion, estado=:estado,
                      ci=:ci, id_tipo_residuo=:id_tipo_residuo,
                      email=:email, telefono=:telefono, tipo_solicitud=:tipo_solicitud
                  WHERE id_solicitud=:id_solicitud";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_solicitud",    $this->id_solicitud);
        $stmt->bindParam(":descripcion",     $this->descripcion);
        $stmt->bindParam(":direccion",       $this->direccion);
        $stmt->bindParam(":estado",          $this->estado);
        $stmt->bindParam(":ci",              $this->ci);
        $stmt->bindParam(":id_tipo_residuo", $this->id_tipo_residuo);
        $stmt->bindParam(":email",           $this->email);
        $stmt->bindParam(":telefono",        $this->telefono);
        $stmt->bindParam(":tipo_solicitud",  $this->tipo_solicitud);
        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;
        $query = "DELETE FROM " . $this->table_name . " WHERE id_solicitud=:id_solicitud";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindParam(":id_solicitud", $this->id_solicitud);
        return $stmt->execute();
    }
}
