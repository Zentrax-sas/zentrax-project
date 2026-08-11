<?php
require_once __DIR__ . '/../models/Centro.php';

class CentroController {
    private $centro;

    public function __construct($db) {
        $this->centro = new Centro($db);
    }

    public function getAll() {
        $stmt = $this->centro->read();
        if ($stmt) {
            return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC), "message" => "Centros cargados correctamente."];
        }
        return [
            "success" => true,
            "data" => [
                ["id_centro" => 1, "nombre" => "Centro de Acopio Norte",  "direccion" => "Av. Gral. Rivera 1500",  "telefono" => "099-100100"],
                ["id_centro" => 2, "nombre" => "Vertedero Municipal Sur", "direccion" => "Camino Maldonado km 12", "telefono" => "099-200200"],
                ["id_centro" => 3, "nombre" => "Centro de Acopio Este",   "direccion" => "Av. Italia 3200",        "telefono" => "099-300300"],
            ],
            "message" => "Centros cargados en modo demo."
        ];
    }

    public function create($data) {
        $this->centro->nombre    = $data['nombre']    ?? null;
        $this->centro->direccion = $data['direccion'] ?? null;
        $this->centro->telefono  = $data['telefono']  ?? null;

        $errors = [];
        if (empty($this->centro->nombre))    $errors[] = "El nombre es obligatorio.";
        if (empty($this->centro->direccion)) $errors[] = "La dirección es obligatoria.";
        if (empty($this->centro->telefono))  $errors[] = "El teléfono es obligatorio.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo registrar el centro.", "errors" => $errors];
        }

        if ($this->centro->create()) {
            return ["success" => true, "data" => null, "message" => "Centro registrado con éxito en Zemyna.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al registrar el centro.", "errors" => []];
    }

    public function update($data) {
        $this->centro->id_centro  = $data['id_centro']  ?? null;
        $this->centro->nombre     = $data['nombre']     ?? null;
        $this->centro->direccion  = $data['direccion']  ?? null;
        $this->centro->telefono   = $data['telefono']   ?? null;

        $errors = [];
        if (empty($this->centro->id_centro)) $errors[] = "El id_centro es obligatorio para actualizar.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo actualizar el centro.", "errors" => $errors];
        }

        if ($this->centro->update()) {
            return ["success" => true, "data" => null, "message" => "Centro actualizado con éxito.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al actualizar el centro.", "errors" => []];
    }

    public function delete($id) {
        $this->centro->id_centro = $id;
        if ($this->centro->delete()) {
            return ["success" => true, "data" => null, "message" => "Centro eliminado correctamente.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al eliminar el centro.", "errors" => []];
    }
}
