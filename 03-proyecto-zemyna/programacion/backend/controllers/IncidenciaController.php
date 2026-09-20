<?php
require_once __DIR__ . '/../models/Incidencia.php';
require_once __DIR__ . '/../models/Solicitud.php';

class IncidenciaController {
    private const MAP_MIN_ZOOM = 13;
    private const MAP_MAX_ZOOM = 19;
    private const MAP_LIMIT = 300;
    private const MAP_MAX_LATITUDE_SPAN = 0.5;
    private const MAP_MAX_LONGITUDE_SPAN = 0.5;
    private $incidencia;
    private $solicitud;

    public function __construct($db) {
        $this->incidencia = new Incidencia($db);
        $this->solicitud = new Solicitud($db);
    }

    private function normalizeString($value) {
        return is_string($value) ? trim($value) : $value;
    }

    private function generateTrackingNumber(array $attempted): string {
        $base = hexdec(substr(bin2hex(random_bytes(3)), 0, 5));
        for ($offset = 0; $offset <= count($attempted); $offset++) {
            $code = strtoupper(str_pad(dechex(($base + $offset) % 0x100000), 5, '0', STR_PAD_LEFT));
            $trackingNumber = 'INC-' . date('Y') . '-' . $code;
            if (!isset($attempted[$trackingNumber])) {
                return $trackingNumber;
            }
        }
        throw new RuntimeException('No se pudo generar un tracking único en memoria.');
    }

    private function isTrackingDuplicate(PDOException $exception): bool {
        $errorInfo = $exception->errorInfo ?? null;
        return is_array($errorInfo)
            && (string)($errorInfo[0] ?? '') === '23000'
            && (int)($errorInfo[1] ?? 0) === 1062;
    }

    private function persistenceError(): array {
        return [
            'success' => false,
            'data' => null,
            'message' => 'No se pudo registrar la incidencia.',
            'errors' => ['Ocurrió un error al guardar la incidencia.'],
            'statusCode' => 500
        ];
    }

    private function validateIncidenciaPayload($data, $isUpdate = false) {
        $errors = [];

        $descripcion = $this->normalizeString($data['descripcion'] ?? null);
        $estado = $this->normalizeString($data['estado'] ?? null);
        $prioridad = $this->normalizeString($data['prioridad'] ?? null);
        $tipoProblema = $this->normalizeString($data['tipo_problema'] ?? null);
        $fechaReporte = $data['fecha_reporte'] ?? null;

        $idContenedor = $data['id_contenedor'] ?? null;
        $idRuta = $data['id_ruta'] ?? null;
        $idCuadrilla = $data['id_cuadrilla'] ?? null;
        $idUsuario = $data['id_usuario'] ?? null;

        if ($descripcion === null || $descripcion === '') {
            $errors[] = 'La descripción es obligatoria.';
        } elseif (!is_string($descripcion) || mb_strlen($descripcion) > 500) {
            $errors[] = 'La descripción no puede superar los 500 caracteres.';
        }

        if ($fechaReporte !== null && $fechaReporte !== '') {
            $fechaTimestamp = is_string($fechaReporte) ? strtotime($fechaReporte) : false;
            if ($fechaTimestamp === false) {
                $errors[] = 'La fecha de reporte no tiene un formato válido.';
            } elseif ($fechaTimestamp > time()) {
                $errors[] = 'La fecha de reporte no puede ser posterior a la fecha actual.';
            }
        }

        $estados = ['Pendiente', 'En Proceso', 'Resuelta'];
        if ($estado === null || $estado === '') {
            $errors[] = 'El estado es obligatorio.';
        } elseif (!in_array($estado, $estados, true)) {
            $errors[] = 'El estado debe ser Pendiente, En Proceso o Resuelta.';
        }

        $prioridades = ['Baja', 'Media', 'Alta'];
        if ($prioridad === null || $prioridad === '') {
            $errors[] = 'La prioridad es obligatoria.';
        } elseif (!in_array($prioridad, $prioridades, true)) {
            $errors[] = 'La prioridad debe ser Baja, Media o Alta.';
        }

        $tiposProblema = [
            'Contenedor Desbordado',
            'Contenedor Roto/Dañado',
            'Obstruido por Vehículo',
            'Incendio/Vandalismo'
        ];

        if ($tipoProblema === null || $tipoProblema === '') {
            $errors[] = 'El tipo de problema es obligatorio.';
        } elseif (!in_array($tipoProblema, $tiposProblema, true)) {
            $errors[] = 'El tipo de problema no es válido.';
        }

        $tieneContenedor = $idContenedor !== null && $idContenedor !== '';
        $tieneRuta = $idRuta !== null && $idRuta !== '';

        if ($tieneContenedor && $tieneRuta) {
            $errors[] = 'La incidencia no puede estar asociada simultáneamente a un contenedor y una ruta.';
        }

        if (!$tieneContenedor && !$tieneRuta) {
            $errors[] = 'La incidencia debe estar asociada a un contenedor o a una ruta.';
        }

        if ($tieneContenedor && !$this->positiveInteger($idContenedor)) {
            $errors[] = 'El id_contenedor debe ser un número entero válido.';
        }

        if ($tieneRuta && !$this->positiveInteger($idRuta)) {
            $errors[] = 'El id_ruta debe ser un número entero válido.';
        }

        if ($idCuadrilla !== null && $idCuadrilla !== '' && !$this->positiveInteger($idCuadrilla)) {
            $errors[] = 'El id_cuadrilla debe ser un número entero válido.';
        }

        if ($idUsuario !== null && $idUsuario !== '' && !$this->positiveInteger($idUsuario)) {
            $errors[] = 'El id_usuario debe ser un número entero válido.';
        }

        if ($isUpdate && !$this->positiveInteger($data['id_incidencia'] ?? null)) {
            $errors[] = 'El id_incidencia es obligatorio para actualizar.';
        }

        return $errors;
    }

    private function positiveInteger($value, int $max = 2147483647): bool {
        return (is_int($value) || is_string($value))
            && preg_match('/^[1-9][0-9]*$/', (string)$value)
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]) !== false;
    }

    private function managementError(int $status, string $message): array {
        return ['success' => false, 'data' => [], 'message' => $message, 'errors' => [], 'statusCode' => $status];
    }

    public function getLocation($id): array {
        if (!$this->positiveInteger($id)) return $this->managementError(400, 'ID inválido.');
        try {
            $row = $this->incidencia->location((int)$id);
            if (!$row) return $this->managementError(404, 'Incidencia no encontrada.');
            $valid = is_numeric($row['latitud']) && is_numeric($row['longitud'])
                && abs((float)$row['latitud']) <= 90 && abs((float)$row['longitud']) <= 180;
            return ['success' => true, 'statusCode' => 200, 'data' => $valid ? $row : null,
                'message' => $valid ? 'Ubicación del contenedor relacionado.' : 'Ubicación no disponible'];
        } catch (PDOException | PersistenceException $exception) {
            return $this->managementError(500, 'No se pudo consultar la ubicación.');
        }
    }

    public function getReport(array $filters): array {
        $group = $filters['grupo'] ?? 'todos';
        $page = $filters['page'] ?? 1;
        $limit = $filters['limit'] ?? 20;
        if (!in_array($group, ['todos', 'abiertas', 'cerradas'], true)
            || !$this->positiveInteger($page, 1000000) || !$this->positiveInteger($limit, 100)) {
            return $this->managementError(400, 'Grupo o paginación inválidos.');
        }
        $dates = [];
        foreach (['desde', 'hasta'] as $key) {
            $value = $filters[$key] ?? null;
            if ($value === '') $value = null;
            if ($value !== null) {
                if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) {
                    return $this->managementError(400, 'Las fechas deben tener formato AAAA-MM-DD.');
                }
                [$year, $month, $day] = array_map('intval', explode('-', $value));
                if ($year < 1000 || !checkdate($month, $day, $year)) {
                    return $this->managementError(400, 'Fecha inválida.');
                }
            }
            $dates[$key] = $value;
        }
        if ($dates['desde'] !== null && $dates['hasta'] !== null && $dates['desde'] > $dates['hasta']) {
            return $this->managementError(400, 'Desde no puede ser posterior a hasta.');
        }
        try {
            return ['success' => true, 'statusCode' => 200] + $this->incidencia->report(
                $group, $dates['desde'], $dates['hasta'], (int)$page, (int)$limit);
        } catch (PDOException | PersistenceException $exception) {
            return $this->managementError(500, 'No se pudo cargar el informe de incidencias.');
        }
    }

    public function getCuadrillas(): array {
        try {
            return ['success' => true, 'data' => $this->incidencia->cuadrillas(), 'statusCode' => 200];
        } catch (PDOException | PersistenceException $exception) {
            return $this->managementError(500, 'No se pudieron cargar las cuadrillas.');
        }
    }

    public function updateAdministrative($data): array {
        if (!is_array($data) || !$this->positiveInteger($data['id_incidencia'] ?? null)) {
            return $this->managementError(400, 'El id_incidencia debe ser un entero positivo.');
        }
        foreach (['estado' => ['Pendiente', 'En Proceso', 'Resuelta'], 'prioridad' => ['Baja', 'Media', 'Alta']] as $field => $allowed) {
            if (array_key_exists($field, $data) && !in_array($data[$field], $allowed, true)) {
                return $this->managementError(400, "El campo $field no es válido.");
            }
        }
        if (array_key_exists('id_cuadrilla', $data) && $data['id_cuadrilla'] !== null && !$this->positiveInteger($data['id_cuadrilla'])) {
            return $this->managementError(400, 'La cuadrilla debe ser un ID positivo o null.');
        }
        $changes = array_intersect_key($data, array_flip(['estado', 'prioridad', 'id_cuadrilla']));
        if (!$changes) return $this->managementError(400, 'Indicá estado, prioridad o cuadrilla.');
        $existing = $this->getAll(['id' => $data['id_incidencia']]);
        if (!$existing['success']) return $existing;
        try {
            if (isset($data['id_cuadrilla']) && !$this->incidencia->cuadrillaExists((int)$data['id_cuadrilla'])) {
                return $this->managementError(400, 'La cuadrilla seleccionada no existe.');
            }
            // Mantiene compatibles los clientes anteriores que envían el registro completo.
            if (array_key_exists('descripcion', $data)) return $this->update($data);
            if (!$this->incidencia->updateManagement((int)$data['id_incidencia'], $changes)) {
                return $this->managementError(500, 'No se pudo actualizar la incidencia.');
            }
            return ['success' => true, 'data' => null, 'message' => 'Incidencia actualizada correctamente.', 'errors' => [], 'statusCode' => 200];
        } catch (PDOException | PersistenceException $exception) {
            return $this->managementError(500, 'No se pudo actualizar la incidencia.');
        }
    }

    public function getMap(array $filters): array {
        $errors = [];
        $coordinates = [];
        $limit = $filters['limit'] ?? self::MAP_LIMIT;
        if (!$this->positiveInteger($limit, self::MAP_LIMIT)) {
            $errors['limit'] = 'El límite debe ser un entero entre 1 y 300.';
            $limit = self::MAP_LIMIT;
        }
        $limit = (int)$limit;
        foreach (['estado' => ['Pendiente', 'En Proceso', 'Resuelta'], 'prioridad' => ['Baja', 'Media', 'Alta']] as $field => $allowed) {
            if (array_key_exists($field, $filters) && !in_array($filters[$field], $allowed, true)) {
                $errors[$field] = "El filtro $field no es válido.";
            }
        }

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
        } elseif ((!is_int($zoom) && !is_string($zoom)) || filter_var($zoom, FILTER_VALIDATE_INT) === false) {
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
                'meta' => ['returned' => 0, 'hasMore' => false, 'limit' => $limit],
                'message' => 'Los límites del mapa no son válidos.',
                'errors' => $errors,
                'statusCode' => 400
            ];
        }

        if ($zoom < self::MAP_MIN_ZOOM) {
            return [
                'success' => true,
                'data' => [],
                'meta' => ['returned' => 0, 'hasMore' => false, 'limit' => $limit],
                'message' => 'Acercá el mapa para ver las incidencias.',
                'errors' => [],
                'statusCode' => 200
            ];
        }

        try {
            $stmt = $this->incidencia->readForMap(
                $coordinates['south'],
                $coordinates['north'],
                $coordinates['west'],
                $coordinates['east'],
                $limit + 1,
                $filters['estado'] ?? null,
                $filters['prioridad'] ?? null,
                ($filters['activas'] ?? null) === '1'
            );
            if (!$stmt) {
                throw new PDOException('No hay conexión disponible.');
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException | PersistenceException $exception) {
            return [
                'success' => false,
                'data' => [],
                'meta' => ['returned' => 0, 'hasMore' => false, 'limit' => $limit],
                'message' => 'No se pudieron cargar las incidencias del mapa.',
                'errors' => [],
                'statusCode' => 500
            ];
        }

        $hasMore = count($rows) > $limit;
        $publicFields = array_flip(['id_incidencia', 'estado', 'prioridad', 'tipo_problema', 'fecha_reporte', 'latitud', 'longitud', 'contenedor_codigo']);
        $rows = array_map(
            static fn(array $row): array => array_intersect_key($row, $publicFields),
            array_slice($rows, 0, $limit)
        );

        // tipo_problema es VARCHAR: no difundir texto libre importado por clientes antiguos.
        foreach ($rows as &$row) {
            if (!in_array($row['tipo_problema'] ?? null, ['Contenedor Desbordado', 'Contenedor Roto/Dañado', 'Obstruido por Vehículo', 'Incendio/Vandalismo'], true)) {
                $row['tipo_problema'] = null;
            }
        }
        unset($row);

        return [
            'success' => true,
            'data' => $rows,
            'meta' => ['returned' => count($rows), 'hasMore' => $hasMore, 'limit' => $limit],
            'message' => $hasMore
                ? 'Hay más incidencias en esta zona. Acercá el mapa para ver un área menor.'
                : (empty($rows) ? 'No hay incidencias visibles en esta zona.' : 'Incidencias del mapa cargadas correctamente.'),
            'errors' => [],
            'statusCode' => 200
        ];
    }

    public function getAll($filters = []) {
        foreach (['id' => 2147483647, 'page' => 1000000, 'limit' => 100] as $field => $max) {
            if (isset($filters[$field]) && !$this->positiveInteger($filters[$field], $max)) {
                return $this->managementError(400, "El filtro $field no es válido.");
            }
        }
        foreach (['estado' => ['Pendiente', 'En Proceso', 'Resuelta'], 'prioridad' => ['Baja', 'Media', 'Alta']] as $field => $allowed) {
            if (isset($filters[$field]) && !in_array($filters[$field], $allowed, true)) {
                return $this->managementError(400, "El filtro $field no es válido.");
            }
        }
        $trackingNumber = $filters['tracking_number'] ?? null;
        if ($trackingNumber !== null) {
            if (!is_string($trackingNumber)) return $this->managementError(400, 'Tracking inválido.');
            $trackingNumber = strtoupper(trim($trackingNumber));
            if (!preg_match('/^(INC-\d{4}-[A-F0-9]{5}|INC-MIG-[1-9][0-9]*)$/', $trackingNumber) || strlen($trackingNumber) > 20) {
                return $this->managementError(400, 'Tracking inválido.');
            }
        }
        $id = isset($filters['id']) ? (int)$filters['id'] : null;
        $page = (int)($filters['page'] ?? 1);
        $limit = (int)($filters['limit'] ?? 20);
        try {
            $stmt = $this->incidencia->read($id, $page, $limit, $trackingNumber, $filters['estado'] ?? null, $filters['prioridad'] ?? null);
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (PDOException | PersistenceException $exception) {
            return $this->managementError(500, 'No se pudieron cargar las incidencias.');
        }

        if (!$stmt) {
            return [
                "success" => false,
                "data" => [],
                "message" => "No se pudieron cargar las incidencias.",
                "statusCode" => 500
            ];
        }

        if (($id !== null || $trackingNumber !== null) && empty($rows)) {
            return [
                "success" => false,
                "data" => [],
                "message" => "Incidencia no encontrada.",
                "statusCode" => 404
            ];
        }

        return [
            "success" => true,
            "data" => $rows,
            "message" => "Incidencias cargadas correctamente.",
            "statusCode" => 200
        ];
    }

    public function getPublicByTracking($trackingNumber) {
        $trackingNumber = is_string($trackingNumber) ? strtoupper(trim($trackingNumber)) : '';

        if (!preg_match('/^(INC|REF)-\d{4}-[A-F0-9]{5}$/', $trackingNumber)) {
            return [
                'success' => false,
                'data' => null,
                'message' => 'El número de seguimiento no tiene un formato válido.',
                'errors' => ['tracking_number' => 'Usá el formato INC-AAAA-XXXXX o REF-AAAA-XXXXX.'],
                'statusCode' => 400
            ];
        }

        if (str_starts_with($trackingNumber, 'REF-')) {
            try {
                $solicitud = $this->solicitud->findPublicByTracking($trackingNumber);
            } catch (PDOException | PersistenceException $exception) {
                return [
                    'success' => false,
                    'data' => null,
                    'message' => 'No se pudo consultar el seguimiento.',
                    'errors' => [],
                    'statusCode' => 500
                ];
            }

            if ($solicitud === null) {
                return [
                    'success' => false,
                    'data' => null,
                    'message' => 'No se encontró un registro con ese número de seguimiento.',
                    'errors' => [],
                    'statusCode' => 404
                ];
            }

            return [
                'success' => true,
                'data' => [
                    'tracking_number' => $solicitud['tracking_number'],
                    'estado' => $solicitud['estado'],
                    'fecha_reporte' => $solicitud['fecha'],
                    'tipo_problema' => $solicitud['tipo_solicitud']
                ],
                'message' => 'Estado consultado correctamente.',
                'errors' => [],
                'statusCode' => 200
            ];
        }

        try {
            $response = $this->getAll([
                'tracking_number' => $trackingNumber,
                'limit' => 1
            ]);
        } catch (PDOException | PersistenceException $exception) {
            return [
                'success' => false,
                'data' => null,
                'message' => 'No se pudo consultar la incidencia.',
                'errors' => [],
                'statusCode' => 500
            ];
        }

        if (!$response['success']) {
            return $response;
        }

        $incidencia = $response['data'][0] ?? null;
        if (!$incidencia) {
            return [
                'success' => false,
                'data' => null,
                'message' => 'No se encontró una incidencia con ese número de seguimiento.',
                'statusCode' => 404
            ];
        }

        return [
            'success' => true,
            'data' => [
                'tracking_number' => $incidencia['tracking_number'],
                'estado' => $incidencia['estado'],
                'fecha_reporte' => $incidencia['fecha_reporte'],
                'tipo_problema' => $incidencia['tipo_problema']
            ],
            'message' => 'Estado de la incidencia consultado correctamente.',
            'statusCode' => 200
        ];
    }

    public function create($data) {
        $data = $data ?? [];

        $data['fecha_reporte'] = $data['fecha_reporte'] ?? date('Y-m-d H:i:s');
        $data['estado'] = $data['estado'] ?? 'Pendiente';
        $data['prioridad'] = $data['prioridad'] ?? 'Media';
        unset($data['id_usuario']);

        $errors = $this->validateIncidenciaPayload($data, false);

        if ($errors) {
            return [
                "success" => false,
                "data" => null,
                "message" => "No se pudo registrar la incidencia.",
                "errors" => $errors,
                "statusCode" => 400
            ];
        }

        $this->incidencia->descripcion = $this->normalizeString($data['descripcion']);
        $this->incidencia->fecha_reporte = $data['fecha_reporte'];
        $this->incidencia->estado = $this->normalizeString($data['estado']);
        $this->incidencia->prioridad = $this->normalizeString($data['prioridad']);
        $this->incidencia->tipo_problema = $this->normalizeString($data['tipo_problema']);

        $this->incidencia->id_contenedor =
            isset($data['id_contenedor']) && $data['id_contenedor'] !== ''
                ? (int)$data['id_contenedor']
                : null;

        $this->incidencia->id_ruta =
            isset($data['id_ruta']) && $data['id_ruta'] !== ''
                ? (int)$data['id_ruta']
                : null;

        $this->incidencia->id_cuadrilla = !empty($data['id_cuadrilla']) ? (int)$data['id_cuadrilla'] : null;
        $this->incidencia->id_usuario = null;

        $attemptedTrackingNumbers = [];
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->incidencia->tracking_number = $this->generateTrackingNumber($attemptedTrackingNumbers);
            $attemptedTrackingNumbers[$this->incidencia->tracking_number] = true;

            try {
                $created = $this->incidencia->create();
            } catch (PDOException $exception) {
                if ($this->isTrackingDuplicate($exception) && $attempt < 3) {
                    continue;
                }
                return $this->persistenceError();
            } catch (PersistenceException $exception) {
                return $this->persistenceError();
            }

            if ($created) {
                return [
                    "success" => true,
                    "data" => [
                        "id_incidencia" => $this->incidencia->id_incidencia,
                        "tracking_number" => $this->incidencia->tracking_number
                    ],
                    "message" => "Incidencia registrada correctamente.",
                    "errors" => [],
                    "statusCode" => 201
                ];
            }

            return [
                "success" => false,
                "data" => null,
                "message" => "Error al registrar la incidencia.",
                "errors" => [],
                "statusCode" => 500
            ];
        }

        return $this->persistenceError();
    }

    public function update($data) {
        $data = $data ?? [];

        $errors = $this->validateIncidenciaPayload($data, true);

        if ($errors) {
            return [
                "success" => false,
                "data" => null,
                "message" => "No se pudo actualizar la incidencia.",
                "errors" => $errors,
                "statusCode" => 400
            ];
        }

        $this->incidencia->id_incidencia = (int)$data['id_incidencia'];
        $this->incidencia->descripcion = $this->normalizeString($data['descripcion']);
        $this->incidencia->fecha_reporte = $data['fecha_reporte'];
        $this->incidencia->estado = $this->normalizeString($data['estado']);
        $this->incidencia->prioridad = $this->normalizeString($data['prioridad']);
        $this->incidencia->tipo_problema = $this->normalizeString($data['tipo_problema']);

        $this->incidencia->id_contenedor =
            isset($data['id_contenedor']) && $data['id_contenedor'] !== ''
                ? (int)$data['id_contenedor']
                : null;

        $this->incidencia->id_ruta =
            isset($data['id_ruta']) && $data['id_ruta'] !== ''
                ? (int)$data['id_ruta']
                : null;

        $this->incidencia->id_cuadrilla =
            isset($data['id_cuadrilla']) && $data['id_cuadrilla'] !== ''
                ? (int)$data['id_cuadrilla']
                : null;
        $this->incidencia->id_usuario =
            isset($data['id_usuario']) && $data['id_usuario'] !== ''
                ? (int)$data['id_usuario']
                : null;

        if ($this->incidencia->update()) {
            return [
                "success" => true,
                "data" => null,
                "message" => "Incidencia actualizada correctamente.",
                "errors" => [],
                "statusCode" => 200
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "No se pudo actualizar la incidencia.",
            "errors" => [],
            "statusCode" => 500
        ];
    }

    public function delete($id) {
        if (!$this->positiveInteger($id)) return $this->managementError(400, 'El ID debe ser un entero positivo.');
        $existing = $this->getAll(['id' => $id]);
        if (!$existing['success']) return $existing;
        $this->incidencia->id_incidencia = (int)$id;
        try {
            if ($this->incidencia->delete()) {
                return ['success' => true, 'data' => null, 'message' => 'Incidencia eliminada correctamente.', 'errors' => [], 'statusCode' => 200];
            }
        } catch (PDOException | PersistenceException $exception) {
            return $this->managementError(500, 'No se pudo eliminar la incidencia.');
        }
        return $this->managementError(500, 'No se pudo eliminar la incidencia.');
    }
}
