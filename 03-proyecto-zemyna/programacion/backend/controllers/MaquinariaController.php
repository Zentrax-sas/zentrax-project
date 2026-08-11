<?php
require_once __DIR__ . '/../models/Maquinaria.php';

class MaquinariaController {
    private $maquinaria;

    public function __construct($db) {
        $this->maquinaria = new Maquinaria($db);
    }

    public function getAll() {
        $stmt = $this->maquinaria->read();
        if ($stmt) {
            return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC), "message" => "Maquinaria cargada correctamente."];
        }
        return [
            "success" => true,
            "data" => [
                ["id_maquinaria" => 1, "nombre" => "Prensadora P-01",  "tipo" => "Prensadora",  "estado" => "Disponible",        "id_centro" => 1],
                ["id_maquinaria" => 2, "nombre" => "Trituradora T-01", "tipo" => "Trituradora", "estado" => "En Mantenimiento",  "id_centro" => 2],
                ["id_maquinaria" => 3, "nombre" => "Cargador C-01",    "tipo" => "Cargador",    "estado" => "En Uso",            "id_centro" => 1],
            ],
            "message" => "Maquinaria cargada en modo demo."
        ];
    }

    public function create($data) {
        $this->maquinaria->nombre    = $data['nombre']    ?? null;
        $this->maquinaria->tipo      = $data['tipo']      ?? null;
        $this->maquinaria->estado    = $data['estado']    ?? null;
        $this->maquinaria->id_centro = $data['id_centro'] ?? null;

        $estados = ['Disponible', 'En Uso', 'En Mantenimiento'];
        $errors  = [];
        if (empty($this->maquinaria->nombre))    $errors[] = "El nombre es obligatorio.";
        if (empty($this->maquinaria->tipo))      $errors[] = "El tipo es obligatorio.";
        if (!in_array($this->maquinaria->estado, $estados)) $errors[] = "El estado debe ser Disponible, En Uso o En Mantenimiento.";
        if (empty($this->maquinaria->id_centro)) $errors[] = "El id_centro es obligatorio.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo registrar la maquinaria.", "errors" => $errors];
        }

        if ($this->maquinaria->create()) {
            return ["success" => true, "data" => null, "message" => "Maquinaria registrada con éxito en Zemyna.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al registrar la maquinaria.", "errors" => []];
    }

    public function update($data) {
        $this->maquinaria->id_maquinaria = $data['id_maquinaria'] ?? null;
        $this->maquinaria->nombre        = $data['nombre']        ?? null;
        $this->maquinaria->tipo          = $data['tipo']          ?? null;
        $this->maquinaria->estado        = $data['estado']        ?? null;
        $this->maquinaria->id_centro     = $data['id_centro']     ?? null;

        $errors = [];
        if (empty($this->maquinaria->id_maquinaria)) $errors[] = "El id_maquinaria es obligatorio para actualizar.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo actualizar la maquinaria.", "errors" => $errors];
        }

        if ($this->maquinaria->update()) {
            return ["success" => true, "data" => null, "message" => "Maquinaria actualizada con éxito.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al actualizar la maquinaria.", "errors" => []];
    }

    public function delete($id) {
        $this->maquinaria->id_maquinaria = $id;
        if ($this->maquinaria->delete()) {
            return ["success" => true, "data" => null, "message" => "Maquinaria eliminada correctamente.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al eliminar la maquinaria.", "errors" => []];
    }
}
