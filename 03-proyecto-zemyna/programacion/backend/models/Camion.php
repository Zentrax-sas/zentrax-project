<?php
class Camion {
    private $conn;
    private string $table_name = "camion";

    public $id_camion;
    public $matricula;
    public $capacidad_toneladas;
    public $estado;
    public $id_chofer_asignado;

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
        if (!empty($this->matricula) && !empty($this->capacidad_toneladas) && !empty($this->estado)) {
            return true;
        }
        return false;
    }

    public function update() {
        if ($this->conn) {
            $query = "UPDATE " . $this->table_name . " 
                      SET matricula=:matricula, capacidad_toneladas=:capacidad_toneladas, estado=:estado, id_chofer_asignado=:id_chofer_asignado
                      WHERE id_camion=:id_camion";
            return true;
        }
        return true;
    }

    public function delete() {
        if ($this->conn) {
            $query = "DELETE FROM " . $this->table_name . " WHERE id_camion=:id_camion";
            return true;
        }
        return true;
    }
}
?>
