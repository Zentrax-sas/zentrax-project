<?php
require_once __DIR__ . '/../models/Incidencia.php';

class IncidenciaController {
    public const USUARIO_SISTEMA_ID = 4;
    public const CUADRILLA_SIN_ASIGNAR_ID = 3;

    private $incidencia;

    public function __construct($db) {
        $this->incidencia = new Incidencia($db);
    }

    private function normalizeString($value) {
        if (is_string($value)) {
            return trim($value);
        }
        return $value;
    }

    private function validateIncidenciaPayload($data, $isUpdate = false) {
        $errors = [];
        $descripcion = $this->normalizeString($data['descripcion'] ?? null);
        $estado = $this->normalizeString($data['estado'] ?? null);
        $prioridad = $this->normalizeString($data['prioridad'] ?? null);
        $tipoProblema = $this->normalizeString($data['tipo_problema'] ?? null);
        $idContenedor = $data['id_contenedor'] ?? null;
        $idCuadrilla = $data['id_cuadrilla'] ?? null;
        $idUsuario = $data['id_usuario'] ?? null;

        if ($descripcion === null || $descripcion === '') {
            if ($isUpdate) $errors[] = 'La descripción es obligatoria.';
        }
        elseif (mb_strlen($descripcion) > 500) $errors[] = 'La descripción no puede superar los 500 caracteres.';

        if ($isUpdate && ($estado === null || $estado === '')) $errors[] = 'El estado es obligatorio.';
        elseif ($estado !== null && $estado !== '' && !in_array($estado, ['Pendiente', 'En Proceso', 'Resuelta'], true)) $errors[] = 'El estado debe ser Pendiente, En Proceso o Resuelta.';

        if ($isUpdate && ($prioridad === null || $prioridad === '')) $errors[] = 'La prioridad es obligatoria.';
        elseif ($prioridad !== null && $prioridad !== '' && !in_array($prioridad, ['Baja', 'Media', 'Alta'], true)) $errors[] = 'La prioridad debe ser Baja, Media o Alta.';

        if ($tipoProblema === null || $tipoProblema === '') $errors[] = 'El tipo de problema es obligatorio.';
        elseif (mb_strlen($tipoProblema) > 100) $errors[] = 'El tipo de problema no puede superar los 100 caracteres.';

        if ($idContenedor === null || $idContenedor === '' || !ctype_digit((string) $idContenedor)) $errors[] = 'El id_contenedor debe ser un número entero válido.';
        if ($isUpdate && ($idCuadrilla === null || $idCuadrilla === '' || !ctype_digit((string) $idCuadrilla))) $errors[] = 'El id_cuadrilla debe ser un número entero válido.';
        if ($isUpdate && ($idUsuario === null || $idUsuario === '' || !ctype_digit((string) $idUsuario))) $errors[] = 'El id_usuario debe ser un número entero válido.';

        if ($isUpdate && (($data['id_incidencia'] ?? null) === null || ($data['id_incidencia'] ?? null) === '')) {
            $errors[] = 'El id_incidencia es obligatorio para actualizar.';
        }

        return $errors;
    }

    public function getAll($filters = []) {
        $id = isset($filters['id']) ? (int) $filters['id'] : null;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;
        $limit = isset($filters['limit']) ? max(1, min(100, (int) $filters['limit'])) : 20;

        $stmt = $this->incidencia->read($id, $page, $limit);
        if ($stmt) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($id !== null && empty($rows)) {
                return ["success" => false, "data" => [], "message" => "Incidencia no encontrada.", "statusCode" => 404];
            }
            return ["success" => true, "data" => $rows, "message" => "Incidencias cargadas correctamente.", "statusCode" => 200];
        }
        return [
            "success" => true,
            "data" => [
                ["id_incidencia" => 1, "descripcion" => "Contenedor dañado, tapa rota.",            "fecha_reporte" => "2025-06-01 09:00:00", "estado" => "Pendiente",   "prioridad" => "Alta",  "tipo_problema" => "Daño físico",    "id_contenedor" => 1, "id_cuadrilla" => 1, "id_usuario" => 2],
                ["id_incidencia" => 2, "descripcion" => "Contenedor desbordado, necesita vaciado.", "fecha_reporte" => "2025-06-02 11:30:00", "estado" => "En Proceso",  "prioridad" => "Media", "tipo_problema" => "Desbordamiento", "id_contenedor" => 2, "id_cuadrilla" => 2, "id_usuario" => 3],
            ],
            "message" => "Incidencias cargadas en modo demo.",
            "statusCode" => 200
        ];
    }

    public function create($data) {
        $data = $data ?? [];
        $tipoProblema = $this->normalizeString($data['tipo_problema'] ?? null);
        $direccion = $this->normalizeString($data['direccion'] ?? null);

        if (empty($data['descripcion'])) {
            $data['descripcion'] = $direccion
                ? "Reporte ciudadano: {$tipoProblema} en {$direccion}."
                : "Reporte ciudadano: {$tipoProblema}.";
        }
        $data['estado'] = 'Pendiente';
        $data['prioridad'] = $data['prioridad'] ?? 'Media';

        if (!isset($data['id_usuario'])) {
            $data['id_usuario'] = $_SESSION['usuario']['id_usuario'] ?? self::USUARIO_SISTEMA_ID;
        }
        if (!isset($data['id_cuadrilla'])) {
            $data['id_cuadrilla'] = self::CUADRILLA_SIN_ASIGNAR_ID;
        }

        $errors = $this->validateIncidenciaPayload($data, false);
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo registrar la incidencia.", "errors" => $errors];
        }

        $this->incidencia->descripcion   = $this->normalizeString($data['descripcion']);
        $this->incidencia->fecha_reporte = $data['fecha_reporte'] ?? date('Y-m-d H:i:s');
        $this->incidencia->estado        = 'Pendiente';
        $this->incidencia->prioridad     = $this->normalizeString($data['prioridad']);
        $this->incidencia->tipo_problema = $tipoProblema;
        $this->incidencia->id_contenedor = (int) ($data['id_contenedor'] ?? 0);
        $this->incidencia->id_cuadrilla  = (int) ($data['id_cuadrilla'] ?? 0);
        $this->incidencia->id_usuario    = (int) ($data['id_usuario'] ?? 0);
        $year = date('Y');
        $randomCode = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $this->incidencia->tracking_number = "INC-{$year}-{$randomCode}";

        if ($this->incidencia->create()) {
            return [
                "success" => true,
                "data" => ["tracking_number" => $this->incidencia->tracking_number],
                "message" => "Incidencia registrada con éxito en Zemyna.",
                "tracking_number" => $this->incidencia->tracking_number,
                "errors" => [],
                "statusCode" => 201
            ];
        }
        return ["success" => false, "data" => null, "message" => "Error al registrar la incidencia.", "errors" => [], "statusCode" => 500];
    }

    public function update($data) {
        $data = $data ?? [];
        $errors = $this->validateIncidenciaPayload($data, true);
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo actualizar la incidencia.", "errors" => $errors];
        }

        $this->incidencia->id_incidencia = (int) ($data['id_incidencia'] ?? 0);
        $this->incidencia->descripcion   = $this->normalizeString($data['descripcion'] ?? null);
        $this->incidencia->fecha_reporte = $data['fecha_reporte'] ?? null;
        $this->incidencia->estado        = $this->normalizeString($data['estado'] ?? null);
        $this->incidencia->prioridad     = $this->normalizeString($data['prioridad'] ?? null);
        $this->incidencia->tipo_problema = $this->normalizeString($data['tipo_problema'] ?? null);
        $this->incidencia->id_contenedor = (int) ($data['id_contenedor'] ?? 0);
        $this->incidencia->id_cuadrilla  = (int) ($data['id_cuadrilla'] ?? 0);
        $this->incidencia->id_usuario    = (int) ($data['id_usuario'] ?? 0);

        if ($this->incidencia->update()) {
            return ["success" => true, "data" => null, "message" => "Incidencia actualizada con éxito.", "errors" => [], "statusCode" => 200];
        }
        return ["success" => false, "data" => null, "message" => "Incidencia no encontrada.", "errors" => ["No existe la incidencia solicitada."], "statusCode" => 404];
    }

    /**
     * Busca una incidencia por su número de seguimiento público.
     *
     * @param string $trackingNumber
     * @return array{success: false, data: null, message: string, statusCode: 404}|array{success: true, data: array{tracking_number: mixed, estado: mixed, fecha_reporte: mixed, tipo_problema: mixed}, message: string, statusCode: 200}
     */
    public function getByTrackingNumber(string $trackingNumber): array {
        $row = $this->incidencia->findByTrackingNumber(trim($trackingNumber));
        if (!$row) {
            return ["success" => false, "data" => null, "message" => "No se encontró ninguna incidencia con ese número de seguimiento.", "statusCode" => 404];
        }

        return [
            "success" => true,
            "data" => [
                "tracking_number" => $row['tracking_number'],
                "estado" => $row['estado'],
                "fecha_reporte" => $row['fecha_reporte'],
                "tipo_problema" => $row['tipo_problema'],
            ],
            "message" => "Incidencia encontrada.",
            "statusCode" => 200
        ];
    }

    public function delete($id) {
        $this->incidencia->id_incidencia = $id;
        if ($this->incidencia->delete()) {
            return ["success" => true, "data" => null, "message" => "Incidencia eliminada correctamente.", "errors" => [], "statusCode" => 200];
        }
        return ["success" => false, "data" => null, "message" => "Incidencia no encontrada.", "errors" => ["No existe la incidencia solicitada."], "statusCode" => 404];
    }
}
