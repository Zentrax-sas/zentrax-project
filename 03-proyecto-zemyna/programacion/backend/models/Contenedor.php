<?php
class Contenedor {
    private $conn;
    private string $table_name = "contenedor";

    public $id_contenedor;
    public $codigo;
    public $capacidad;
    public $direccion;
    public $latitud;
    public $longitud;
    public $estado;
    public $id_tipo_residuo;
    public $id_ruta;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function read($id = null, $page = 1, $limit = 20, $bbox = []) {
        if (!$this->conn) return null;

        $conditions = [];
        if ($id !== null && $id !== '') {
            $conditions[] = 'id_contenedor = :id_contenedor';
        }
        if (isset($bbox['min_lat'], $bbox['min_lon'], $bbox['max_lat'], $bbox['max_lon'])) {
            $conditions[] = 'latitud BETWEEN :min_lat AND :max_lat AND longitud BETWEEN :min_lon AND :max_lon';
        }

        $offset = ($page - 1) * $limit;
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $query = 'SELECT * FROM ' . $this->table_name . $where . ' ORDER BY id_contenedor ASC LIMIT :limit OFFSET :offset';
        $stmt = $this->conn->prepare($query);

        if ($id !== null && $id !== '') {
            $stmt->bindValue(':id_contenedor', (int) $id, PDO::PARAM_INT);
        }
        if (isset($bbox['min_lat'], $bbox['min_lon'], $bbox['max_lat'], $bbox['max_lon'])) {
            $stmt->bindValue(':min_lat', (float) $bbox['min_lat']);
            $stmt->bindValue(':min_lon', (float) $bbox['min_lon']);
            $stmt->bindValue(':max_lat', (float) $bbox['max_lat']);
            $stmt->bindValue(':max_lon', (float) $bbox['max_lon']);
        }
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt;
    }

    public function create() {
        if (!$this->conn) return false;
        if (empty($this->codigo) || empty($this->capacidad) || empty($this->direccion) ||
            empty($this->estado) || empty($this->id_tipo_residuo) || empty($this->id_ruta)) {
            return false;
        }
        $query = "INSERT INTO " . $this->table_name . "
                  (codigo, capacidad, direccion, latitud, longitud, estado, id_tipo_residuo, id_ruta)
                  VALUES (:codigo, :capacidad, :direccion, :latitud, :longitud, :estado, :id_tipo_residuo, :id_ruta)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":codigo",          $this->codigo);
        $stmt->bindParam(":capacidad",        $this->capacidad);
        $stmt->bindParam(":direccion",        $this->direccion);
        $stmt->bindParam(":latitud",          $this->latitud);
        $stmt->bindParam(":longitud",         $this->longitud);
        $stmt->bindParam(":estado",           $this->estado);
        $stmt->bindParam(":id_tipo_residuo",  $this->id_tipo_residuo);
        $stmt->bindParam(":id_ruta",          $this->id_ruta);
        return $stmt->execute();
    }

    public function update() {
        if (!$this->conn) return false;
        $query = "UPDATE " . $this->table_name . "
                  SET codigo=:codigo, capacidad=:capacidad, direccion=:direccion,
                      latitud=:latitud, longitud=:longitud, estado=:estado,
                      id_tipo_residuo=:id_tipo_residuo, id_ruta=:id_ruta
                  WHERE id_contenedor=:id_contenedor";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_contenedor",   $this->id_contenedor);
        $stmt->bindParam(":codigo",          $this->codigo);
        $stmt->bindParam(":capacidad",       $this->capacidad);
        $stmt->bindParam(":direccion",       $this->direccion);
        $stmt->bindParam(":latitud",         $this->latitud);
        $stmt->bindParam(":longitud",        $this->longitud);
        $stmt->bindParam(":estado",          $this->estado);
        $stmt->bindParam(":id_tipo_residuo", $this->id_tipo_residuo);
        $stmt->bindParam(":id_ruta",         $this->id_ruta);
        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;
        $query = "DELETE FROM " . $this->table_name . " WHERE id_contenedor=:id_contenedor";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindParam(":id_contenedor", $this->id_contenedor);
        return $stmt->execute();
    }
}