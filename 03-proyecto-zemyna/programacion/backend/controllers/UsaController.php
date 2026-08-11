<?php
require_once __DIR__ . '/../models/Usa.php';

class UsaController {
    private $usa;

    public function __construct($db) {
        $this->usa = new Usa($db);
    }

    public function getAll(?int $id_cuadrilla = null, ?int $id_vehiculo = null) {
        $stmt = $this->usa->read($id_cuadrilla, $id_vehiculo);
        if ($stmt) {
            return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC), "message" => "Asignaciones cuadrilla-vehículo cargadas correctamente."];
        }
        return [
            "success" => true,
            "data" => [
                ["id_cuadrilla" => 1, "id_vehiculo" => 1],
                ["id_cuadrilla" => 1, "id_vehiculo" => 2],
                ["id_cuadrilla" => 2, "id_vehiculo" => 3],
            ],
            "message" => "Asignaciones cargadas en modo demo."
        ];
    }

    public function create($data) {
        $this->usa->id_cuadrilla = $data['id_cuadrilla'] ?? null;
        $this->usa->id_vehiculo  = $data['id_vehiculo']  ?? null;

        $errors = [];
        if (empty($this->usa->id_cuadrilla)) $errors[] = "El id_cuadrilla es obligatorio.";
        if (empty($this->usa->id_vehiculo))  $errors[] = "El id_vehiculo es obligatorio.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo registrar la asignación.", "errors" => $errors];
        }

        if ($this->usa->create()) {
            return ["success" => true, "data" => null, "message" => "Vehículo asignado a la cuadrilla con éxito en Zemyna.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al registrar la asignación.", "errors" => []];
    }

    public function delete($data) {
        $this->usa->id_cuadrilla = $data['id_cuadrilla'] ?? null;
        $this->usa->id_vehiculo  = $data['id_vehiculo']  ?? null;

        $errors = [];
        if (empty($this->usa->id_cuadrilla)) $errors[] = "El id_cuadrilla es obligatorio.";
        if (empty($this->usa->id_vehiculo))  $errors[] = "El id_vehiculo es obligatorio.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo eliminar la asignación.", "errors" => $errors];
        }

        if ($this->usa->delete()) {
            return ["success" => true, "data" => null, "message" => "Asignación eliminada correctamente.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al eliminar la asignación.", "errors" => []];
    }
}
