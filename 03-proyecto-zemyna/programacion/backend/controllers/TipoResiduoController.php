<?php
require_once __DIR__ . '/../models/TipoResiduo.php';

class TipoResiduoController {
    private $tipoResiduo;

    public function __construct($db) {
        $this->tipoResiduo = new TipoResiduo($db);
    }

    public function getAll() {
        $stmt = $this->tipoResiduo->read();
        if (!$stmt) {
            return [
                "success" => false,
                "data" => [],
                "message" => "No se pudo conectar con la base de datos de tipos de residuo.",
                "statusCode" => 500
            ];
        }

        return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC), "message" => "Tipos de residuo cargados correctamente.", "statusCode" => 200];
    }

    public function create($data) {
        $this->tipoResiduo->nombre      = $data['nombre']      ?? null;
        $this->tipoResiduo->descripcion = $data['descripcion'] ?? null;

        $errors = [];
        if (empty($this->tipoResiduo->nombre))      $errors[] = "El nombre es obligatorio.";
        if (empty($this->tipoResiduo->descripcion)) $errors[] = "La descripción es obligatoria.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo registrar el tipo de residuo.", "errors" => $errors];
        }

        if ($this->tipoResiduo->create()) {
            return ["success" => true, "data" => null, "message" => "Tipo de residuo registrado con éxito en Zemyna.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al registrar el tipo de residuo.", "errors" => []];
    }

    public function update($data) {
        $this->tipoResiduo->id_tipo_residuo = $data['id_tipo_residuo'] ?? null;
        $this->tipoResiduo->nombre          = $data['nombre']          ?? null;
        $this->tipoResiduo->descripcion     = $data['descripcion']     ?? null;

        $errors = [];
        if (empty($this->tipoResiduo->id_tipo_residuo)) $errors[] = "El id_tipo_residuo es obligatorio para actualizar.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo actualizar el tipo de residuo.", "errors" => $errors];
        }

        if ($this->tipoResiduo->update()) {
            return ["success" => true, "data" => null, "message" => "Tipo de residuo actualizado con éxito.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al actualizar el tipo de residuo.", "errors" => []];
    }

    public function delete($id) {
        $this->tipoResiduo->id_tipo_residuo = $id;
        if ($this->tipoResiduo->delete()) {
            return ["success" => true, "data" => null, "message" => "Tipo de residuo eliminado correctamente.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al eliminar el tipo de residuo.", "errors" => []];
    }
}
