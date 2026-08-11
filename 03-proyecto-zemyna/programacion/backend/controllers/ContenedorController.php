<?php
require_once __DIR__ . '/../models/Contenedor.php';

class ContenedorController {
    private $contenedor;

    public function __construct($db) {
        $this->contenedor = new Contenedor($db);
    }

    public function getAll() {
        $stmt = $this->contenedor->read();
        if ($stmt) {
            return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC), "message" => "Contenedores cargados correctamente."];
        }
        return [
            "success" => true,
            "data" => [
                ["id_contenedor" => 1, "codigo" => "CTN-001", "capacidad" => 2400, "direccion" => "Av. Brasil y Lazaro Gadea", "latitud" => -34.9142000, "longitud" => -56.1495000, "estado" => "Disponible", "id_tipo_residuo" => 1, "id_ruta" => 1],
                ["id_contenedor" => 2, "codigo" => "CTN-002", "capacidad" => 3200, "direccion" => "Brito del Pino y Charrua", "latitud" => -34.9210000, "longitud" => -56.1585000, "estado" => "Lleno", "id_tipo_residuo" => 2, "id_ruta" => 1],
                ["id_contenedor" => 3, "codigo" => "CTN-003", "capacidad" => 2400, "direccion" => "Av. 18 de Julio y Tacuari", "latitud" => -34.9065000, "longitud" => -56.1852000, "estado" => "Disponible", "id_tipo_residuo" => 3, "id_ruta" => 2]
            ],
            "message" => "Contenedores cargados en modo demo."
        ];
    }

    public function create($data) {
        $this->contenedor->codigo          = $data['codigo'] ?? null;
        $this->contenedor->capacidad       = $data['capacidad'] ?? null;
        $this->contenedor->direccion       = $data['direccion'] ?? null;
        $this->contenedor->latitud         = $data['latitud'] ?? null;
        $this->contenedor->longitud        = $data['longitud'] ?? null;
        $this->contenedor->estado          = $data['estado'] ?? null;
        $this->contenedor->id_tipo_residuo = $data['id_tipo_residuo'] ?? null;
        $this->contenedor->id_ruta         = $data['id_ruta'] ?? null;

        $estados = ['Disponible', 'Lleno', 'Danado', 'Fuera de Servicio', 'Dañado'];
        $errors = [];
        if (empty($this->contenedor->codigo)) $errors[] = "El codigo es obligatorio.";
        if (empty($this->contenedor->capacidad) || $this->contenedor->capacidad <= 0) $errors[] = "La capacidad debe ser un numero positivo.";
        if (empty($this->contenedor->direccion)) $errors[] = "La direccion es obligatoria.";
        if (!in_array($this->contenedor->estado, $estados)) $errors[] = "El estado debe ser Disponible, Lleno, Danado o Fuera de Servicio.";
        if (empty($this->contenedor->id_tipo_residuo)) $errors[] = "El id_tipo_residuo es obligatorio.";
        if (empty($this->contenedor->id_ruta)) $errors[] = "El id_ruta es obligatorio.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo registrar el contenedor.", "errors" => $errors];
        }

        if ($this->contenedor->create()) {
            return ["success" => true, "data" => null, "message" => "Contenedor urbano registrado con exito en Zemyna.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al registrar el contenedor.", "errors" => []];
    }

    public function update($data) {
        $this->contenedor->id_contenedor   = $data['id_contenedor'] ?? null;
        $this->contenedor->codigo          = $data['codigo'] ?? null;
        $this->contenedor->capacidad       = $data['capacidad'] ?? null;
        $this->contenedor->direccion       = $data['direccion'] ?? null;
        $this->contenedor->latitud         = $data['latitud'] ?? null;
        $this->contenedor->longitud        = $data['longitud'] ?? null;
        $this->contenedor->estado          = $data['estado'] ?? null;
        $this->contenedor->id_tipo_residuo = $data['id_tipo_residuo'] ?? null;
        $this->contenedor->id_ruta         = $data['id_ruta'] ?? null;

        if (empty($this->contenedor->id_contenedor)) {
            return ["success" => false, "data" => null, "message" => "No se pudo actualizar el contenedor.", "errors" => ["El id_contenedor es obligatorio para actualizar."]];
        }

        if ($this->contenedor->update()) {
            return ["success" => true, "data" => null, "message" => "Contenedor actualizado con exito.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al actualizar el contenedor.", "errors" => []];
    }

    public function delete($id) {
        $this->contenedor->id_contenedor = $id;
        if ($this->contenedor->delete()) {
            return ["success" => true, "data" => null, "message" => "Contenedor removido del sistema.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al eliminar el contenedor.", "errors" => []];
    }
}
