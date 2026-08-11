<?php
require_once __DIR__ . '/../models/Vecino.php';

class VecinoController {
    private $vecino;

    public function __construct($db) {
        $this->vecino = new Vecino($db);
    }

    public function getAll() {
        $stmt = $this->vecino->read();
        if ($stmt) {
            return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC), "message" => "Vecinos cargados correctamente."];
        }
        return [
            "success" => true,
            "data" => [
                ["ci" => "12345678", "nombre" => "Carlos",  "apellido" => "García",    "telefono" => "092-111111"],
                ["ci" => "87654321", "nombre" => "Laura",   "apellido" => "Rodríguez", "telefono" => "092-222222"],
                ["ci" => "11223344", "nombre" => "Martín",  "apellido" => "López",     "telefono" => "092-333333"],
            ],
            "message" => "Vecinos cargados en modo demo."
        ];
    }

    public function create($data) {
        $this->vecino->ci       = $data['ci']       ?? null;
        $this->vecino->nombre   = $data['nombre']   ?? null;
        $this->vecino->apellido = $data['apellido'] ?? null;
        $this->vecino->telefono = $data['telefono'] ?? null;

        $errors = [];
        if (empty($this->vecino->ci))       $errors[] = "La cédula de identidad es obligatoria.";
        if (strlen($this->vecino->ci ?? '') !== 8) $errors[] = "La CI debe tener exactamente 8 caracteres.";
        if (empty($this->vecino->nombre))   $errors[] = "El nombre es obligatorio.";
        if (empty($this->vecino->apellido)) $errors[] = "El apellido es obligatorio.";
        if (empty($this->vecino->telefono)) $errors[] = "El teléfono es obligatorio.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo registrar el vecino.", "errors" => $errors];
        }

        if ($this->vecino->create()) {
            return ["success" => true, "data" => null, "message" => "Vecino registrado con éxito en Zemyna.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al registrar el vecino.", "errors" => []];
    }

    public function update($data) {
        $this->vecino->ci       = $data['ci']       ?? null;
        $this->vecino->nombre   = $data['nombre']   ?? null;
        $this->vecino->apellido = $data['apellido'] ?? null;
        $this->vecino->telefono = $data['telefono'] ?? null;

        $errors = [];
        if (empty($this->vecino->ci)) $errors[] = "La CI es obligatoria para actualizar.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo actualizar el vecino.", "errors" => $errors];
        }

        if ($this->vecino->update()) {
            return ["success" => true, "data" => null, "message" => "Vecino actualizado con éxito.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al actualizar el vecino.", "errors" => []];
    }

    public function delete($ci) {
        $this->vecino->ci = $ci;
        if ($this->vecino->delete()) {
            return ["success" => true, "data" => null, "message" => "Vecino eliminado correctamente.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al eliminar el vecino.", "errors" => []];
    }
}
