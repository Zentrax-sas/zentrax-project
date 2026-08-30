<?php
require_once __DIR__ . '/../models/Vertedero.php';

class VertederoController {
    private $vertedero;

    public function __construct($db) {
        $this->vertedero = new Vertedero($db);
    }

    public function getAll() {
        $stmt = $this->vertedero->read();
        if (!$stmt) {
            return [
                "success" => false,
                "data" => [],
                "message" => "No se pudo conectar con la base de datos de vertederos.",
                "statusCode" => 500
            ];
        }

        return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC), "message" => "Vertederos cargados correctamente.", "statusCode" => 200];
    }

    public function create($data) {
        $this->vertedero->id_centro        = $data['id_centro']        ?? null;
        $this->vertedero->capacidad_maxima = $data['capacidad_maxima'] ?? null;

        $errors = [];
        if (empty($this->vertedero->id_centro))                    $errors[] = "El id_centro es obligatorio.";
        if (!isset($data['capacidad_maxima']) || $data['capacidad_maxima'] <= 0) $errors[] = "La capacidad máxima debe ser un número positivo.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo registrar el vertedero.", "errors" => $errors];
        }

        if ($this->vertedero->create()) {
            return ["success" => true, "data" => null, "message" => "Vertedero registrado con éxito en Zemyna.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al registrar el vertedero.", "errors" => []];
    }

    public function update($data) {
        $this->vertedero->id_centro        = $data['id_centro']        ?? null;
        $this->vertedero->capacidad_maxima = $data['capacidad_maxima'] ?? null;

        $errors = [];
        if (empty($this->vertedero->id_centro)) $errors[] = "El id_centro es obligatorio para actualizar.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo actualizar el vertedero.", "errors" => $errors];
        }

        if ($this->vertedero->update()) {
            return ["success" => true, "data" => null, "message" => "Vertedero actualizado con éxito.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al actualizar el vertedero.", "errors" => []];
    }

    public function delete($id) {
        $this->vertedero->id_centro = $id;
        if ($this->vertedero->delete()) {
            return ["success" => true, "data" => null, "message" => "Vertedero eliminado correctamente.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al eliminar el vertedero.", "errors" => []];
    }
}
