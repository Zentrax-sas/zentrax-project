<?php
require_once __DIR__ . '/../models/Incidencia.php';

class IncidenciaController {
    private $incidencia;

    public function __construct($db) {
        $this->incidencia = new Incidencia($db);
    }

    private function normalizeString($value) {
        return is_string($value) ? trim($value) : $value;
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

        if ($fechaReporte !== null && $fechaReporte !== '' && strtotime($fechaReporte) > time()) {
            $errors[] = 'La fecha de reporte no puede ser posterior a la fecha actual.';
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

        if ($idCuadrilla === null || $idCuadrilla === '' || !ctype_digit((string)$idCuadrilla)) {
            $errors[] = 'El id_cuadrilla debe ser un número entero válido.';
        }

        if ($idUsuario === null || $idUsuario === '' || !ctype_digit((string)$idUsuario)) {
            $errors[] = 'El id_usuario debe ser un número entero válido.';
        }

        if ($isUpdate && empty($data['id_incidencia'])) {
            $errors[] = 'El id_incidencia es obligatorio para actualizar.';
        }

        return $errors;
    }

    public function getAll($filters = []) {
        $id = isset($filters['id']) ? (int)$filters['id'] : null;
        $page = isset($filters['page']) ? max(1, (int)$filters['page']) : 1;
        $limit = isset($filters['limit']) ? max(1, min(100, (int)$filters['limit'])) : 20;

        $stmt = $this->incidencia->read($id, $page, $limit);

        if (!$stmt) {
            return [
                "success" => false,
                "data" => [],
                "message" => "No se pudieron cargar las incidencias.",
                "statusCode" => 500
            ];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($id !== null && empty($rows)) {
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

    public function create($data) {
        $data = $data ?? [];

        $data['fecha_reporte'] = $data['fecha_reporte'] ?? date('Y-m-d H:i:s');
        $data['estado'] = $data['estado'] ?? 'Pendiente';
        $data['prioridad'] = $data['prioridad'] ?? 'Media';

        if (!isset($data['id_usuario']) && isset($_SESSION['usuario']['id_usuario'])) {
            $data['id_usuario'] = $_SESSION['usuario']['id_usuario'];
        }

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

        $this->incidencia->id_cuadrilla = (int)$data['id_cuadrilla'];
        $this->incidencia->id_usuario = (int)$data['id_usuario'];

        if ($this->incidencia->create()) {
            return [
                "success" => true,
                "data" => ["id_incidencia" => $this->incidencia->id_incidencia],
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

        $this->incidencia->id_cuadrilla = (int)$data['id_cuadrilla'];
        $this->incidencia->id_usuario = (int)$data['id_usuario'];

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