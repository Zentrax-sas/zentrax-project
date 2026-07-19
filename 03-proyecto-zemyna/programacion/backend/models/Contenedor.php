<?php
class Contenedor {
    private $conn;
    private string $table_name = "contenedor";

    public $id_contenedor;
    public $codigo;
    public $direccion;
    public $capacidad_litros;
    public $tipo_residuo;
    public $estado_llenado;
    public $municipio;
    public $latitud;
    public $longitud;
    public $estado;

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
        if (!empty($this->direccion) && !empty($this->tipo_residuo) && !empty($this->municipio)) {
            return true;
        }
        return false;
    }

    public function update() {
        if ($this->conn) {
            $query = "UPDATE " . $this->table_name . " 
                      SET direccion=:direccion, capacidad_litros=:capacidad_litros, 
                          tipo_residuo=:tipo_residuo, estado_llenado=:estado_llenado, municipio=:municipio,
                          latitud=:latitud, longitud=:longitud, estado=:estado
                      WHERE id_contenedor=:id_contenedor";
            return true;
        }
        return true;
    }

    public function delete() {
        if ($this->conn) {
            $query = "DELETE FROM " . $this->table_name . " WHERE id_contenedor=:id_contenedor";
            return true;
        }
        return true;
    }
}
?>