<?php
require_once __DIR__ . '/../models/Recorre.php';

class RecorreController {
    private $recorre;

    public function __construct($db) {
        $this->recorre = new Recorre($db);
    }

    public function getAll(?int $id_vehiculo = null, ?int $id_ruta = null) {
        $stmt = $this->recorre->read($id_vehiculo, $id_ruta);
        if ($stmt) {
            return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC), "message" => "Asignaciones vehículo-ruta cargadas correctamente."];
        }
        return [
            "success" => true,
            "data" => [
                ["id_vehiculo" => 1, "id_ruta" => 1],
                ["id_vehiculo" => 2, "id_ruta" => 1],
                ["id_vehiculo" => 3, "id_ruta" => 2],
            ],
            "message" => "Asignaciones cargadas en modo demo."
        ];
    }

    public function create($data) {
        $this->recorre->id_vehiculo = $data['id_vehiculo'] ?? null;
        $this->recorre->id_ruta     = $data['id_ruta']     ?? null;

        $errors = [];
        if (empty($this->recorre->id_vehiculo)) $errors[] = "El id_vehiculo es obligatorio.";
        if (empty($this->recorre->id_ruta))     $errors[] = "El id_ruta es obligatorio.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo registrar la asignación.", "errors" => $errors];
        }

        if ($this->recorre->create()) {
            return ["success" => true, "data" => null, "message" => "Ruta asignada al vehículo con éxito en Zemyna.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al registrar la asignación.", "errors" => []];
    }

    public function delete($data) {
        $this->recorre->id_vehiculo = $data['id_vehiculo'] ?? null;
        $this->recorre->id_ruta     = $data['id_ruta']     ?? null;

        $errors = [];
        if (empty($this->recorre->id_vehiculo)) $errors[] = "El id_vehiculo es obligatorio.";
        if (empty($this->recorre->id_ruta))     $errors[] = "El id_ruta es obligatorio.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo eliminar la asignación.", "errors" => $errors];
        }

        if ($this->recorre->delete()) {
            return ["success" => true, "data" => null, "message" => "Asignación eliminada correctamente.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al eliminar la asignación.", "errors" => []];
    }
}
