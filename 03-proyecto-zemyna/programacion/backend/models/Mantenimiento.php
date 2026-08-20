<?php

class Mantenimiento {
    private $conn;
    private string $table_name = "mantenimiento";

    public $id_mantenimiento;
    public $fecha_inicio;
    public $fecha_fin;
    public $estado;
    public $tipo;
    public $descripcion;
    public $id_vehiculo;
    public $id_maquinaria;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function read($id = null) {
        if (!$this->conn) return null;

        $where = '';

        if ($id !== null && $id !== '') {
            $where = " WHERE m.id_mantenimiento = :id_mantenimiento";
        }

        $query = "SELECT m.*,
                         v.matricula,
                         v.marca,
                         v.modelo,
                         ma.nombre AS maquinaria_nombre,
                         ma.tipo AS maquinaria_tipo
                  FROM " . $this->table_name . " m
                  LEFT JOIN vehiculo v 
                    ON v.id_vehiculo = m.id_vehiculo
                  LEFT JOIN maquinaria ma 
                    ON ma.id_maquinaria = m.id_maquinaria"
                  . $where . "
                  ORDER BY m.fecha_inicio DESC";

        $stmt = $this->conn->prepare($query);

        if ($id !== null && $id !== '') {
            $stmt->bindValue(
                ':id_mantenimiento',
                (int)$id,
                PDO::PARAM_INT
            );
        }

        $stmt->execute();

        return $stmt;
    }

    public function create() {
        if (!$this->conn) return false;

        if (empty($this->fecha_inicio) ||
            empty($this->estado) ||
            empty($this->tipo) ||
            empty($this->descripcion)) {
            return false;
        }

        $tieneVehiculo = !empty($this->id_vehiculo);
        $tieneMaquinaria = !empty($this->id_maquinaria);

        if ($tieneVehiculo === $tieneMaquinaria) {
            return false;
        }

        $query = "INSERT INTO " . $this->table_name . "
                  (fecha_inicio, fecha_fin, estado, tipo, descripcion,
                   id_vehiculo, id_maquinaria)
                  VALUES
                  (:fecha_inicio, :fecha_fin, :estado, :tipo, :descripcion,
                   :id_vehiculo, :id_maquinaria)";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':fecha_inicio', $this->fecha_inicio);
        $stmt->bindParam(':fecha_fin', $this->fecha_fin);
        $stmt->bindParam(':estado', $this->estado);
        $stmt->bindParam(':tipo', $this->tipo);
        $stmt->bindParam(':descripcion', $this->descripcion);
        $stmt->bindParam(':id_vehiculo', $this->id_vehiculo);
        $stmt->bindParam(':id_maquinaria', $this->id_maquinaria);

        if ($stmt->execute()) {
            $this->id_mantenimiento =
                (int)$this->conn->lastInsertId();

            return true;
        }

        return false;
    }

    public function update() {
        if (!$this->conn) return false;

        $tieneVehiculo = !empty($this->id_vehiculo);
        $tieneMaquinaria = !empty($this->id_maquinaria);

        if ($tieneVehiculo === $tieneMaquinaria) {
            return false;
        }

        $query = "UPDATE " . $this->table_name . "
                  SET fecha_inicio = :fecha_inicio,
                      fecha_fin = :fecha_fin,
                      estado = :estado,
                      tipo = :tipo,
                      descripcion = :descripcion,
                      id_vehiculo = :id_vehiculo,
                      id_maquinaria = :id_maquinaria
                  WHERE id_mantenimiento = :id_mantenimiento";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(
            ':id_mantenimiento',
            $this->id_mantenimiento
        );
        $stmt->bindParam(':fecha_inicio', $this->fecha_inicio);
        $stmt->bindParam(':fecha_fin', $this->fecha_fin);
        $stmt->bindParam(':estado', $this->estado);
        $stmt->bindParam(':tipo', $this->tipo);
        $stmt->bindParam(':descripcion', $this->descripcion);
        $stmt->bindParam(':id_vehiculo', $this->id_vehiculo);
        $stmt->bindParam(':id_maquinaria', $this->id_maquinaria);

        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;

        $query = "DELETE FROM " . $this->table_name . "
                  WHERE id_mantenimiento = :id_mantenimiento";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(
            ':id_mantenimiento',
            $this->id_mantenimiento
        );

        return $stmt->execute();
    }
}