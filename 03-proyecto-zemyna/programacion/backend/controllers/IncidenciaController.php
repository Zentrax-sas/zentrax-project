<?php
require_once __DIR__ . '/../models/Incidencia.php';
require_once __DIR__ . '/../models/Solicitud.php';

class IncidenciaController {
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
        } elseif (mb_strlen($descripcion) > 500) {
            $errors[] = 'La descripción no puede superar los 500 caracteres.';
        }

        if ($fechaReporte !== null && $fechaReporte !== '') {
            $fechaTimestamp = strtotime($fechaReporte);
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

        if ($tieneContenedor && !ctype_digit((string)$idContenedor)) {
            $errors[] = 'El id_contenedor debe ser un número entero válido.';
        }

        if ($tieneRuta && !ctype_digit((string)$idRuta)) {
            $errors[] = 'El id_ruta debe ser un número entero válido.';
        }

        if ($idCuadrilla !== null && $idCuadrilla !== '' && !ctype_digit((string)$idCuadrilla)) {
            $errors[] = 'El id_cuadrilla debe ser un número entero válido.';
        }

        if ($idUsuario !== null && $idUsuario !== '' && !ctype_digit((string)$idUsuario)) {
            $errors[] = 'El id_usuario debe ser un número entero válido.';
        }

        if ($isUpdate && empty($data['id_incidencia'])) {
            $errors[] = 'El id_incidencia es obligatorio para actualizar.';
        }

        return $errors;
    }

    public function getAll($filters = []) {
        $id = isset($filters['id']) ? (int)$filters['id'] : null;
        $trackingNumber = isset($filters['tracking_number']) ? strtoupper(trim($filters['tracking_number'])) : null;
        $page = isset($filters['page']) ? max(1, (int)$filters['page']) : 1;
        $limit = isset($filters['limit']) ? max(1, min(100, (int)$filters['limit'])) : 20;

        $stmt = $this->incidencia->read($id, $page, $limit, $trackingNumber);

        if (!$stmt) {
            return [
                "success" => false,
                "data" => [],
                "message" => "No se pudieron cargar las incidencias.",
                "statusCode" => 500
            ];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        $trackingNumber = strtoupper(trim((string)$trackingNumber));

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
        $this->incidencia->id_incidencia = (int)$id;

        if ($this->incidencia->delete()) {
            return [
                "success" => true,
                "data" => null,
                "message" => "Incidencia eliminada correctamente.",
                "errors" => [],
                "statusCode" => 200
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "Error al eliminar la incidencia.",
            "errors" => [],
            "statusCode" => 500
        ];
    }
}
