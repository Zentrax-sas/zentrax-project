<?php
require_once __DIR__ . '/../models/Incidencia.php';

class IncidenciaController {
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

        if ($descripcion === null || $descripcion === '') $errors[] = 'La descripción es obligatoria.';
        elseif (mb_strlen($descripcion) > 500) $errors[] = 'La descripción no puede superar los 500 caracteres.';

        if ($estado === null || $estado === '') $errors[] = 'El estado es obligatorio.';
        elseif (!in_array($estado, ['Pendiente', 'En Proceso', 'Resuelta'], true)) $errors[] = 'El estado debe ser Pendiente, En Proceso o Resuelta.';

        if ($prioridad === null || $prioridad === '') $errors[] = 'La prioridad es obligatoria.';
        elseif (!in_array($prioridad, ['Baja', 'Media', 'Alta'], true)) $errors[] = 'La prioridad debe ser Baja, Media o Alta.';

        if ($tipoProblema === null || $tipoProblema === '') $errors[] = 'El tipo de problema es obligatorio.';
        elseif (mb_strlen($tipoProblema) > 100) $errors[] = 'El tipo de problema no puede superar los 100 caracteres.';

        if ($idContenedor === null || $idContenedor === '' || !ctype_digit((string) $idContenedor)) $errors[] = 'El id_contenedor debe ser un número entero válido.';
        if ($idCuadrilla === null || $idCuadrilla === '' || !ctype_digit((string) $idCuadrilla)) $errors[] = 'El id_cuadrilla debe ser un número entero válido.';
        if ($idUsuario === null || $idUsuario === '' || !ctype_digit((string) $idUsuario)) $errors[] = 'El id_usuario debe ser un número entero válido.';

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
        $errors = $this->validateIncidenciaPayload($data, false);
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo registrar la incidencia.", "errors" => $errors];
        }

        $this->incidencia->descripcion   = $this->normalizeString($data['descripcion'] ?? null);
        $this->incidencia->fecha_reporte = $data['fecha_reporte'] ?? date('Y-m-d H:i:s');
        $this->incidencia->estado        = $this->normalizeString($data['estado'] ?? null);
        $this->incidencia->prioridad     = $this->normalizeString($data['prioridad'] ?? null);
        $this->incidencia->tipo_problema = $this->normalizeString($data['tipo_problema'] ?? null);
        $this->incidencia->id_contenedor = (int) ($data['id_contenedor'] ?? 0);
        $this->incidencia->id_cuadrilla  = (int) ($data['id_cuadrilla'] ?? 0);
        $this->incidencia->id_usuario    = (int) ($data['id_usuario'] ?? 0);

        if ($this->incidencia->create()) {
            return ["success" => true, "data" => null, "message" => "Incidencia registrada con éxito en Zemyna.", "errors" => [], "statusCode" => 201];
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

    public function delete($id) {
        $this->incidencia->id_incidencia = $id;
        if ($this->incidencia->delete()) {
            return ["success" => true, "data" => null, "message" => "Incidencia eliminada correctamente.", "errors" => [], "statusCode" => 200];
        }
        return ["success" => false, "data" => null, "message" => "Incidencia no encontrada.", "errors" => ["No existe la incidencia solicitada."], "statusCode" => 404];
    }
}
