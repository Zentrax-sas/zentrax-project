<?php
require_once __DIR__ . '/../models/Usa.php';

class UsaController {
    private $usa;

    public function __construct($db) {
        $this->usa = new Usa($db);
    }

    public function getAll($filters = []) {
        $id = $filters['id'] ?? null;
        $idCuadrilla = $filters['id_cuadrilla'] ?? null;
        $idVehiculo = $filters['id_vehiculo'] ?? null;

        $stmt = $this->usa->read($id, $idCuadrilla, $idVehiculo);

        if (!$stmt) {
            return [
                "success" => false,
                "data" => [],
                "message" => "No se pudieron cargar las asignaciones.",
                "statusCode" => 500
            ];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($id !== null && empty($rows)) {
            return [
                "success" => false,
                "data" => [],
                "message" => "Asignación no encontrada.",
                "statusCode" => 404
            ];
        }

        return [
            "success" => true,
            "data" => $rows,
            "message" => "Asignaciones cuadrilla-vehículo cargadas correctamente.",
            "statusCode" => 200
        ];
    }

    public function create($data) {
        $data = $data ?? [];

        $this->usa->id_cuadrilla = $data['id_cuadrilla'] ?? null;
        $this->usa->id_vehiculo = $data['id_vehiculo'] ?? null;

        $errors = [];

        if (empty($this->usa->id_cuadrilla)) {
            $errors[] = "El id_cuadrilla es obligatorio.";
        }

        if (empty($this->usa->id_vehiculo)) {
            $errors[] = "El id_vehiculo es obligatorio.";
        }

        if ($errors) {
            return [
                "success" => false,
                "data" => null,
                "message" => "No se pudo registrar la asignación.",
                "errors" => $errors,
                "statusCode" => 400
            ];
        }

        if ($this->usa->existeRelacion($this->usa->id_cuadrilla, $this->usa->id_vehiculo)) {
            return [
                "success" => false,
                "data" => null,
                "message" => "La cuadrilla ya tiene asignado ese vehículo.",
                "statusCode" => 409
            ];
        }

        if ($this->usa->create()) {
            return [
                "success" => true,
                "data" => ["id_usa" => $this->usa->id_usa],
                "message" => "Vehículo asignado a la cuadrilla correctamente.",
                "errors" => [],
                "statusCode" => 201
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "Error al registrar la asignación.",
            "errors" => [],
            "statusCode" => 500
        ];
    }

    public function update($data) {
        $data = $data ?? [];

        $this->usa->id_usa = $data['id_usa'] ?? null;
        $this->usa->id_cuadrilla = $data['id_cuadrilla'] ?? null;
        $this->usa->id_vehiculo = $data['id_vehiculo'] ?? null;

        $errors = [];

        if (empty($this->usa->id_usa)) {
            $errors[] = "El id_usa es obligatorio.";
        }

        if (empty($this->usa->id_cuadrilla)) {
            $errors[] = "El id_cuadrilla es obligatorio.";
        }

        if (empty($this->usa->id_vehiculo)) {
            $errors[] = "El id_vehiculo es obligatorio.";
        }

        if ($errors) {
            return [
                "success" => false,
                "data" => null,
                "message" => "No se pudo actualizar la asignación.",
                "errors" => $errors,
                "statusCode" => 400
            ];
        }

        if ($this->usa->update()) {
            return [
                "success" => true,
                "data" => null,
                "message" => "Asignación actualizada correctamente.",
                "errors" => [],
                "statusCode" => 200
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "Error al actualizar la asignación.",
            "errors" => [],
            "statusCode" => 500
        ];
    }

    public function delete($id) {
        $this->usa->id_usa = (int)$id;

        if ($this->usa->delete()) {
            return [
                "success" => true,
                "data" => null,
                "message" => "Asignación eliminada correctamente.",
                "errors" => [],
                "statusCode" => 200
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "Error al eliminar la asignación.",
            "errors" => [],
            "statusCode" => 500
        ];
    }
}