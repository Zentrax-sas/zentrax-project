<?php
require_once __DIR__ . '/../models/Contenedor.php';

class ContenedorController {
    private const MAP_MIN_ZOOM = 13;
    private const MAP_MAX_ZOOM = 19;
    private const MAP_LIMIT = 500;
    private const MAP_MAX_LATITUDE_SPAN = 0.5;
    private const MAP_MAX_LONGITUDE_SPAN = 0.5;

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
        elseif (mb_strlen($codigo) > 20) $errors[] = 'El código no puede superar los 20 caracteres.';

        if ($direccion === null || $direccion === '') $errors[] = 'La dirección es obligatoria.';
        elseif (mb_strlen($direccion) > 150) $errors[] = 'La dirección no puede superar los 150 caracteres.';

        if ($capacidad === null || $capacidad === '') $errors[] = 'La capacidad es obligatoria.';
        elseif (!is_numeric($capacidad) || (float) $capacidad <= 0) $errors[] = 'La capacidad debe ser un número positivo.';

        if ($latitud === null || $latitud === '' || !is_numeric($latitud) || $latitud < -90 || $latitud > 90) $errors[] = 'La latitud es obligatoria y debe estar entre -90 y 90.';
        if ($longitud === null || $longitud === '' || !is_numeric($longitud) || $longitud < -180 || $longitud > 180) $errors[] = 'La longitud es obligatoria y debe estar entre -180 y 180.';

        if ($estado === null || $estado === '') $errors[] = 'El estado es obligatorio.';
        elseif (!in_array($estado, ['Disponible', 'Lleno', 'Dañado', 'Fuera de Servicio'], true)) $errors[] = 'El estado no es válido.';

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
        $limit = isset($filters['limit']) ? max(1, min(2000, (int) $filters['limit'])) : 20;

        $bbox = [];
        foreach (['min_lat', 'min_lon', 'max_lat', 'max_lon'] as $coordinate) {
            if (isset($filters[$coordinate]) && is_numeric($filters[$coordinate])) {
                $bbox[$coordinate] = (float) $filters[$coordinate];
            }
        }
        if (count($bbox) !== 4) {
            $bbox = [];
        }

        $stmt = $this->contenedor->read($id, $page, $limit, $bbox);
        if (!$stmt) {
            return [
                "success" => false,
                "data" => [],
                "message" => "No se pudo conectar con la base de datos de contenedores.",
                "statusCode" => 500
            ];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($id !== null && empty($rows)) {
            return ["success" => false, "data" => [], "message" => "Contenedor no encontrado.", "statusCode" => 404];
        }

        return ["success" => true, "data" => $rows, "message" => "Contenedores cargados correctamente.", "statusCode" => 200];
    }

    public function getMap(array $filters): array {
        $errors = [];
        $coordinates = [];

        foreach (['north', 'south', 'east', 'west'] as $field) {
            if (!array_key_exists($field, $filters) || $filters[$field] === '') {
                $errors[$field] = "El límite {$field} es obligatorio.";
                continue;
            }
            if (!is_numeric($filters[$field])) {
                $errors[$field] = "El límite {$field} debe ser numérico.";
                continue;
            }
            $coordinates[$field] = (float)$filters[$field];
            if (!is_finite($coordinates[$field])) {
                $errors[$field] = "El límite {$field} debe ser finito.";
            }
        }

        $zoom = $filters['zoom'] ?? null;
        if ($zoom === null || $zoom === '') {
            $errors['zoom'] = 'El zoom es obligatorio.';
        } elseif (filter_var($zoom, FILTER_VALIDATE_INT) === false) {
            $errors['zoom'] = 'El zoom debe ser un número entero.';
        } else {
            $zoom = (int)$zoom;
            if ($zoom < 0 || $zoom > self::MAP_MAX_ZOOM) {
                $errors['zoom'] = 'El zoom debe estar entre 0 y 19.';
            }
        }

        foreach (['north', 'south'] as $field) {
            if (isset($coordinates[$field]) && ($coordinates[$field] < -90 || $coordinates[$field] > 90)) {
                $errors[$field] = "El límite {$field} debe estar entre -90 y 90.";
            }
        }
        foreach (['east', 'west'] as $field) {
            if (isset($coordinates[$field]) && ($coordinates[$field] < -180 || $coordinates[$field] > 180)) {
                $errors[$field] = "El límite {$field} debe estar entre -180 y 180.";
            }
        }

        if (isset($coordinates['north'], $coordinates['south']) && $coordinates['north'] <= $coordinates['south']) {
            $errors['viewport'] = 'El límite norte debe ser mayor que el límite sur.';
        }
        if (isset($coordinates['east'], $coordinates['west']) && $coordinates['east'] <= $coordinates['west']) {
            $errors['viewport'] = 'El límite este debe ser mayor que el límite oeste.';
        }
        if (isset($coordinates['north'], $coordinates['south'], $coordinates['east'], $coordinates['west'])
            && (($coordinates['north'] - $coordinates['south']) > self::MAP_MAX_LATITUDE_SPAN
                || ($coordinates['east'] - $coordinates['west']) > self::MAP_MAX_LONGITUDE_SPAN)) {
            $errors['viewport'] = 'El área solicitada es demasiado grande.';
        }

        if ($errors) {
            return [
                'success' => false,
                'data' => [],
                'meta' => ['returned' => 0, 'hasMore' => false, 'limit' => self::MAP_LIMIT],
                'message' => 'Los límites del mapa no son válidos.',
                'errors' => $errors,
                'statusCode' => 400
            ];
        }

        if ($zoom < self::MAP_MIN_ZOOM) {
            return [
                'success' => true,
                'data' => [],
                'meta' => ['returned' => 0, 'hasMore' => false, 'limit' => self::MAP_LIMIT],
                'message' => 'Acercá el mapa para ver los contenedores.',
                'errors' => [],
                'statusCode' => 200
            ];
        }

        try {
            $stmt = $this->contenedor->readForMap(
                $coordinates['south'],
                $coordinates['north'],
                $coordinates['west'],
                $coordinates['east'],
                self::MAP_LIMIT + 1
            );
            if (!$stmt) {
                throw new PDOException('No hay conexión disponible.');
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            return [
                'success' => false,
                'data' => [],
                'meta' => ['returned' => 0, 'hasMore' => false, 'limit' => self::MAP_LIMIT],
                'message' => 'No se pudieron cargar los contenedores del mapa.',
                'errors' => [],
                'statusCode' => 500
            ];
        }

        $hasMore = count($rows) > self::MAP_LIMIT;
        $publicFields = array_flip(['id_contenedor', 'codigo', 'direccion', 'latitud', 'longitud', 'estado']);
        $rows = array_map(
            static fn(array $row): array => array_intersect_key($row, $publicFields),
            array_slice($rows, 0, self::MAP_LIMIT)
        );

        return [
            'success' => true,
            'data' => $rows,
            'meta' => ['returned' => count($rows), 'hasMore' => $hasMore, 'limit' => self::MAP_LIMIT],
            'message' => $hasMore
                ? 'Hay más contenedores en esta zona. Acercá el mapa para ver un área menor.'
                : (empty($rows) ? 'No hay contenedores visibles en esta zona.' : 'Contenedores del mapa cargados correctamente.'),
            'errors' => [],
            'statusCode' => 200
        ];
    }

    public function create($data) {
        $data = $data ?? [];
        $errors = $this->validateContenedorPayload($data, false);
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo registrar el contenedor.", "errors" => $errors];
        }

        $codigo = $this->normalizeString($data['codigo'] ?? null);
        if ($codigo !== null && $codigo !== '' && method_exists($this->contenedor, 'findByCodigo')) {
            $existente = $this->contenedor->findByCodigo($codigo);
            if ($existente) {
                return [
                    "success" => false,
                    "data" => null,
                    "message" => "El código del contenedor ya existe.",
                    "errors" => ["El código ya está registrado."],
                    "statusCode" => 409
                ];
            }
        }

        $this->contenedor->codigo          = $codigo;
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

        $codigo = $this->normalizeString($data['codigo'] ?? null);
        $idContenedor = (int) ($data['id_contenedor'] ?? 0);
        if ($codigo !== null && $codigo !== '' && method_exists($this->contenedor, 'findByCodigo')) {
            $existente = $this->contenedor->findByCodigo($codigo);
            if ($existente && ((int) ($existente['id_contenedor'] ?? 0)) !== $idContenedor) {
                return [
                    "success" => false,
                    "data" => null,
                    "message" => "El código del contenedor ya existe para otro registro.",
                    "errors" => ["El código ya está registrado."],
                    "statusCode" => 409
                ];
            }
        }

        $this->contenedor->id_contenedor   = $idContenedor;
        $this->contenedor->codigo          = $codigo;
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
            return ["success" => true, "data" => null, "message" => "Contenedor dado de baja lógicamente.", "errors" => [], "statusCode" => 200];
        }
        return ["success" => false, "data" => null, "message" => "Contenedor no encontrado.", "errors" => ["No existe el contenedor solicitado."], "statusCode" => 404];
    }
}
