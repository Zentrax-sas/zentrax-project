<?php
class Contenedor {
    private $conn;
    private string $table_name = "contenedores";

    // Atributos de la entidad Contenedor
    public $id_contenedor;
    public $direccion;
    public $capacidad_litros;
    public $tipo_residuo; // Ej: 'Organico', 'Reciclable', 'Vidrio'
    public $estado_llenado; // Ej: 'Vacio', 'Medio', 'Lleno', 'Saturado'
    public $municipio; // Ej: 'CH', 'B', 'E', etc.

    public function __construct($db) {
        $this->conn = $db;
    }

    // Leer contenedores
    public function read() {
        $query = "SELECT * FROM " . $this->table_name;
        if ($this->conn) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt;
        }
        return null;
    }

    // Crear contenedor (Simulado para la primera entrega)
    public function create() {
        /* Consulta real futura:
        $query = "INSERT INTO " . $this->table_name . "  
                  (direccion, capacidad_litros, tipo_residuo, estado_llenado, municipio)
                  VALUES (:direccion, :capacidad_litros, :tipo_residuo, :estado_llenado, :municipio)";
        ...
        */
        
        // Validación temporal para la entrega sin Base de Datos
        if (!empty($this->direccion) && !empty($this->tipo_residuo) && !empty($this->municipio)) {
            return true; 
        }
        return false;
    }

    // Actualizar contenedor
    public function update() {
        if ($this->conn) {
            $query = "UPDATE " . $this->table_name . " 
                      SET direccion=:direccion, capacidad_litros=:capacidad_litros, 
                          tipo_residuo=:tipo_residuo, estado_llenado=:estado_llenado, municipio=:municipio
                      WHERE id_contenedor=:id_contenedor";
            // ... ejecución real ...
            return true;
        }
        return true;
    }

    // Eliminar contenedor
    public function delete() {
        if ($this->conn) {
            $query = "DELETE FROM " . $this->table_name . " WHERE id_contenedor=:id_contenedor";
            // ... ejecución real ...
            return true;
        }
        return true;
    }
}
?>