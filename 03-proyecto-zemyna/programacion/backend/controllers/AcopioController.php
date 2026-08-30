<?php
require_once __DIR__ . '/../models/Acopio.php';

class AcopioController {
    private $acopio;

    public function __construct($db) {
        $this->acopio = new Acopio($db);
    }

    public function getAll() {
        $stmt = $this->acopio->read();
        if (!$stmt) {
            return [
                "success" => false,
                "data" => [],
                "message" => "No se pudo conectar con la base de datos de acopios.",
                "statusCode" => 500
            ];
        }

        return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC), "message" => "Centros de acopio cargados correctamente.", "statusCode" => 200];
    }

    public function create($data) {
        $this->acopio->id_centro        = $data['id_centro']        ?? null;
        $this->acopio->horario_atencion = $data['horario_atencion'] ?? null;

        $errors = [];
        if (empty($this->acopio->id_centro))        $errors[] = "El id_centro es obligatorio.";
        if (empty($this->acopio->horario_atencion)) $errors[] = "El horario de atención es obligatorio.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo registrar el centro de acopio.", "errors" => $errors];
        }

        if ($this->acopio->create()) {
            return ["success" => true, "data" => null, "message" => "Centro de acopio registrado con éxito en Zemyna.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al registrar el centro de acopio.", "errors" => []];
    }

    public function update($data) {
        $this->acopio->id_centro        = $data['id_centro']        ?? null;
        $this->acopio->horario_atencion = $data['horario_atencion'] ?? null;

        $errors = [];
        if (empty($this->acopio->id_centro)) $errors[] = "El id_centro es obligatorio para actualizar.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo actualizar el centro de acopio.", "errors" => $errors];
        }

        if ($this->acopio->update()) {
            return ["success" => true, "data" => null, "message" => "Centro de acopio actualizado con éxito.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al actualizar el centro de acopio.", "errors" => []];
    }

    public function delete($id) {
        $this->acopio->id_centro = $id;
        if ($this->acopio->delete()) {
            return ["success" => true, "data" => null, "message" => "Centro de acopio eliminado correctamente.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al eliminar el centro de acopio.", "errors" => []];
    }
}
