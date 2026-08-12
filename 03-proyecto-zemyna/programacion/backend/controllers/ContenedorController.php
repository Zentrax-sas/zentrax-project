<?php
require_once __DIR__ . '/../models/Contenedor.php';

class ContenedorController {
    private $contenedor;

    public function __construct($db) {
        $this->contenedor = new Contenedor($db);
    }

    private function normalizeString($value) {
        if (is_string($value)) {
            return trim($value);
        }
        return $value;
    }

    private function validateContenedorPayload($data, $isUpdate = false) {
        $errors = [];
        $codigo = $this->normalizeString($data['codigo'] ?? null);
        $direccion = $this->normalizeString($data['direccion'] ?? null);
        $estado = $this->normalizeString($data['estado'] ?? null);
        $capacidad = $data['capacidad'] ?? null;
        $latitud = $data['latitud'] ?? null;
        $longitud = $data['longitud'] ?? null;
        $idTipoResiduo = $data['id_tipo_residuo'] ?? null;
        $idRuta = $data['id_ruta'] ?? null;

        if ($codigo === null || $codigo === '') $errors[] = 'El código es obligatorio.';
        elseif (mb_strlen($codigo) > 50) $errors[] = 'El código no puede superar los 50 caracteres.';

        if ($direccion === null || $direccion === '') $errors[] = 'La dirección es obligatoria.';
        elseif (mb_strlen($direccion) > 255) $errors[] = 'La dirección no puede superar los 255 caracteres.';

        if ($capacidad === null || $capacidad === '') $errors[] = 'La capacidad es obligatoria.';
        elseif (!is_numeric($capacidad) || (float) $capacidad <= 0) $errors[] = 'La capacidad debe ser un número positivo.';

        if ($latitud !== null && $latitud !== '' && (!is_numeric($latitud) || $latitud < -90 || $latitud > 90)) $errors[] = 'La latitud debe estar entre -90 y 90.';
        if ($longitud !== null && $longitud !== '' && (!is_numeric($longitud) || $longitud < -180 || $longitud > 180)) $errors[] = 'La longitud debe estar entre -180 y 180.';

        if ($estado === null || $estado === '') $errors[] = 'El estado es obligatorio.';
        elseif (!in_array($estado, ['Disponible', 'Lleno', 'En Mantenimiento', 'Fuera de Servicio', 'Dañado', 'Danado'], true)) $errors[] = 'El estado no es válido.';

        if ($idTipoResiduo === null || $idTipoResiduo === '' || !ctype_digit((string) $idTipoResiduo)) $errors[] = 'El id_tipo_residuo debe ser un número entero válido.';
        if ($idRuta === null || $idRuta === '' || !ctype_digit((string) $idRuta)) $errors[] = 'El id_ruta debe ser un número entero válido.';

        if ($isUpdate && (($data['id_contenedor'] ?? null) === null || ($data['id_contenedor'] ?? null) === '')) {
            $errors[] = 'El id_contenedor es obligatorio para actualizar.';
        }

        return $errors;
    }

    public function getAll($filters = []) {
        $id = isset($filters['id']) ? (int) $filters['id'] : null;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;
        $limit = isset($filters['limit']) ? max(1, min(100, (int) $filters['limit'])) : 20;

        $stmt = $this->contenedor->read($id, $page, $limit);
        if ($stmt) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($id !== null && empty($rows)) {
                return ["success" => false, "data" => [], "message" => "Contenedor no encontrado.", "statusCode" => 404];
            }
            return ["success" => true, "data" => $rows, "message" => "Contenedores cargados correctamente.", "statusCode" => 200];
        }
        return [
            "success" => true,
            "data" => [
                ["id_contenedor" => 1, "codigo" => "CTN-001", "capacidad" => 2400, "direccion" => "Av. Brasil y Lazaro Gadea", "latitud" => -34.9142000, "longitud" => -56.1495000, "estado" => "Disponible", "id_tipo_residuo" => 1, "id_ruta" => 1],
                ["id_contenedor" => 2, "codigo" => "CTN-002", "capacidad" => 3200, "direccion" => "Brito del Pino y Charrua", "latitud" => -34.9210000, "longitud" => -56.1585000, "estado" => "Lleno", "id_tipo_residuo" => 2, "id_ruta" => 1],
                ["id_contenedor" => 3, "codigo" => "CTN-003", "capacidad" => 2400, "direccion" => "Av. 18 de Julio y Tacuari", "latitud" => -34.9065000, "longitud" => -56.1852000, "estado" => "Disponible", "id_tipo_residuo" => 3, "id_ruta" => 2]
            ],
            "message" => "Contenedores cargados en modo demo.",
            "statusCode" => 200
        ];
    }

    public function create($data) {
        $data = $data ?? [];
        $errors = $this->validateContenedorPayload($data, false);
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo registrar el contenedor.", "errors" => $errors];
        }

        $this->contenedor->codigo          = $this->normalizeString($data['codigo'] ?? null);
        $this->contenedor->capacidad       = (float) ($data['capacidad'] ?? 0);
        $this->contenedor->direccion       = $this->normalizeString($data['direccion'] ?? null);
        $this->contenedor->latitud         = isset($data['latitud']) && $data['latitud'] !== '' ? (float) $data['latitud'] : null;
        $this->contenedor->longitud        = isset($data['longitud']) && $data['longitud'] !== '' ? (float) $data['longitud'] : null;
        $this->contenedor->estado          = $this->normalizeString($data['estado'] ?? null);
        $this->contenedor->id_tipo_residuo = (int) ($data['id_tipo_residuo'] ?? 0);
        $this->contenedor->id_ruta         = (int) ($data['id_ruta'] ?? 0);

        if ($this->contenedor->create()) {
            return ["success" => true, "data" => null, "message" => "Contenedor urbano registrado con exito en Zemyna.", "errors" => [], "statusCode" => 201];
        }
        return ["success" => false, "data" => null, "message" => "Error al registrar el contenedor.", "errors" => [], "statusCode" => 500];
    }

    public function update($data) {
        $data = $data ?? [];
        $errors = $this->validateContenedorPayload($data, true);
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo actualizar el contenedor.", "errors" => $errors];
        }

        $this->contenedor->id_contenedor   = (int) ($data['id_contenedor'] ?? 0);
        $this->contenedor->codigo          = $this->normalizeString($data['codigo'] ?? null);
        $this->contenedor->capacidad       = (float) ($data['capacidad'] ?? 0);
        $this->contenedor->direccion       = $this->normalizeString($data['direccion'] ?? null);
        $this->contenedor->latitud         = isset($data['latitud']) && $data['latitud'] !== '' ? (float) $data['latitud'] : null;
        $this->contenedor->longitud        = isset($data['longitud']) && $data['longitud'] !== '' ? (float) $data['longitud'] : null;
        $this->contenedor->estado          = $this->normalizeString($data['estado'] ?? null);
        $this->contenedor->id_tipo_residuo = (int) ($data['id_tipo_residuo'] ?? 0);
        $this->contenedor->id_ruta         = (int) ($data['id_ruta'] ?? 0);

        if ($this->contenedor->update()) {
            return ["success" => true, "data" => null, "message" => "Contenedor actualizado con exito.", "errors" => [], "statusCode" => 200];
        }
        return ["success" => false, "data" => null, "message" => "Contenedor no encontrado.", "errors" => ["No existe el contenedor solicitado."], "statusCode" => 404];
    }

    public function delete($id) {
        $this->contenedor->id_contenedor = $id;
        if ($this->contenedor->delete()) {
            return ["success" => true, "data" => null, "message" => "Contenedor removido del sistema.", "errors" => [], "statusCode" => 200];
        }
        return ["success" => false, "data" => null, "message" => "Contenedor no encontrado.", "errors" => ["No existe el contenedor solicitado."], "statusCode" => 404];
    }
}
