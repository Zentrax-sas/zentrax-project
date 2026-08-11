<?php
require_once __DIR__ . '/../models/Ruta.php';

class RutaController {
    private $ruta;

    public function __construct($db) {
        $this->ruta = new Ruta($db);
    }

    public function getAll() {
        $stmt = $this->ruta->read();
        if ($stmt) {
            return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC), "message" => "Rutas cargadas correctamente."];
        }
        return [
            "success" => true,
            "data" => [
                ["id_ruta" => 1, "nombre" => "Ruta Norte",   "zona" => "Zona norte de la ciudad"],
                ["id_ruta" => 2, "nombre" => "Ruta Centro",  "zona" => "Zona céntrica y microcentro"],
                ["id_ruta" => 3, "nombre" => "Ruta Sur",     "zona" => "Zona sur y periferia"],
            ],
            "message" => "Rutas cargadas en modo demo."
        ];
    }

    public function create($data) {
        $this->ruta->nombre = $data['nombre'] ?? null;
        $this->ruta->zona   = $data['zona']   ?? null;

        $errors = [];
        if (empty($this->ruta->nombre)) $errors[] = "El nombre es obligatorio.";
        if (empty($this->ruta->zona))   $errors[] = "La zona es obligatoria.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo registrar la ruta.", "errors" => $errors];
        }

        if ($this->ruta->create()) {
            return ["success" => true, "data" => null, "message" => "Ruta registrada con éxito en Zemyna.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al registrar la ruta.", "errors" => []];
    }

    public function update($data) {
        $this->ruta->id_ruta = $data['id_ruta'] ?? null;
        $this->ruta->nombre  = $data['nombre']  ?? null;
        $this->ruta->zona    = $data['zona']    ?? null;

        $errors = [];
        if (empty($this->ruta->id_ruta)) $errors[] = "El id_ruta es obligatorio para actualizar.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo actualizar la ruta.", "errors" => $errors];
        }

        if ($this->ruta->update()) {
            return ["success" => true, "data" => null, "message" => "Ruta actualizada con éxito.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al actualizar la ruta.", "errors" => []];
    }

    public function delete($id) {
        $this->ruta->id_ruta = $id;
        if ($this->ruta->delete()) {
            return ["success" => true, "data" => null, "message" => "Ruta eliminada correctamente.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al eliminar la ruta.", "errors" => []];
    }
}
