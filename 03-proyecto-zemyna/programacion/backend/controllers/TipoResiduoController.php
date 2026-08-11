<?php
require_once __DIR__ . '/../models/TipoResiduo.php';

class TipoResiduoController {
    private $tipoResiduo;

    public function __construct($db) {
        $this->tipoResiduo = new TipoResiduo($db);
    }

    public function getAll() {
        $stmt = $this->tipoResiduo->read();
        if ($stmt) {
            return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC), "message" => "Tipos de residuo cargados correctamente."];
        }
        // RNE-27: valores canónicos del sistema
        return [
            "success" => true,
            "data" => [
                ["id_tipo_residuo" => 1, "nombre" => "Orgánico",             "descripcion" => "Restos de comida, hojas y residuos de origen biológico."],
                ["id_tipo_residuo" => 2, "nombre" => "Papel y cartón",       "descripcion" => "Papeles, diarios, cartones limpios y secos."],
                ["id_tipo_residuo" => 3, "nombre" => "Plástico",             "descripcion" => "Envases plásticos, botellas PET, tapas y bolsas."],
                ["id_tipo_residuo" => 4, "nombre" => "Vidrio",               "descripcion" => "Botellas, frascos y envases de vidrio."],
                ["id_tipo_residuo" => 5, "nombre" => "Metal",                "descripcion" => "Latas, chatarra y envases metálicos."],
                ["id_tipo_residuo" => 6, "nombre" => "Electrónicos",         "descripcion" => "Equipos y aparatos electrónicos en desuso."],
                ["id_tipo_residuo" => 7, "nombre" => "Pilas y baterías",     "descripcion" => "Pilas, baterías y acumuladores usados."],
                ["id_tipo_residuo" => 8, "nombre" => "Escombros",            "descripcion" => "Residuos de construcción y demolición."],
                ["id_tipo_residuo" => 9, "nombre" => "Residuos voluminosos", "descripcion" => "Muebles, electrodomésticos y objetos de gran tamaño."],
            ],
            "message" => "Tipos de residuo cargados en modo demo."
        ];
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
