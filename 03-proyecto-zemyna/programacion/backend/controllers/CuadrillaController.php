<?php
require_once __DIR__ . '/../models/Cuadrilla.php';

class CuadrillaController {
    private $cuadrilla;

    public function __construct($db) {
        $this->cuadrilla = new Cuadrilla($db);
    }

    public function getAll() {
        $stmt = $this->cuadrilla->read();
        if ($stmt) {
            return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC), "message" => "Cuadrillas cargadas correctamente."];
        }
        return [
            "success" => true,
            "data" => [
                ["id_cuadrilla" => 1, "nombre" => "Cuadrilla Alpha", "turno" => "Matutino",   "id_centro" => 1],
                ["id_cuadrilla" => 2, "nombre" => "Cuadrilla Beta",  "turno" => "Vespertino", "id_centro" => 2],
                ["id_cuadrilla" => 3, "nombre" => "Cuadrilla Gamma", "turno" => "Nocturno",   "id_centro" => 1],
            ],
            "message" => "Cuadrillas cargadas en modo demo."
        ];
    }

    public function create($data) {
        $this->cuadrilla->nombre    = $data['nombre']    ?? null;
        $this->cuadrilla->turno     = $data['turno']     ?? null;
        $this->cuadrilla->id_centro = $data['id_centro'] ?? null;

        $turnos = ['Matutino', 'Vespertino', 'Nocturno'];
        $errors = [];
        if (empty($this->cuadrilla->nombre))    $errors[] = "El nombre es obligatorio.";
        if (!in_array($this->cuadrilla->turno, $turnos)) $errors[] = "El turno debe ser Matutino, Vespertino o Nocturno.";
        if (empty($this->cuadrilla->id_centro)) $errors[] = "El id_centro es obligatorio.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo registrar la cuadrilla.", "errors" => $errors];
        }

        if ($this->cuadrilla->create()) {
            return ["success" => true, "data" => null, "message" => "Cuadrilla registrada con éxito en Zemyna.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al registrar la cuadrilla.", "errors" => []];
    }

    public function update($data) {
        $this->cuadrilla->id_cuadrilla = $data['id_cuadrilla'] ?? null;
        $this->cuadrilla->nombre       = $data['nombre']       ?? null;
        $this->cuadrilla->turno        = $data['turno']        ?? null;
        $this->cuadrilla->id_centro    = $data['id_centro']    ?? null;

        $errors = [];
        if (empty($this->cuadrilla->id_cuadrilla)) $errors[] = "El id_cuadrilla es obligatorio para actualizar.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo actualizar la cuadrilla.", "errors" => $errors];
        }

        if ($this->cuadrilla->update()) {
            return ["success" => true, "data" => null, "message" => "Cuadrilla actualizada con éxito.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al actualizar la cuadrilla.", "errors" => []];
    }

    public function delete($id) {
        $this->cuadrilla->id_cuadrilla = $id;
        if ($this->cuadrilla->delete()) {
            return ["success" => true, "data" => null, "message" => "Cuadrilla eliminada correctamente.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al eliminar la cuadrilla.", "errors" => []];
    }
}
