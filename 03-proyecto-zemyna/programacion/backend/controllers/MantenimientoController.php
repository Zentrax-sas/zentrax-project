<?php
require_once __DIR__ . '/../models/Mantenimiento.php';

class MantenimientoController {
    private $mantenimiento;

    public function __construct($db) {
        $this->mantenimiento = new Mantenimiento($db);
    }

    private function normalizeString($value) {
        return is_string($value) ? trim($value) : $value;
    }

    private function validatePayload($data, $isUpdate = false) {
        $errors = [];

        $id = $data['id_mantenimiento'] ?? null;
        $fechaInicio = $data['fecha_inicio'] ?? null;
        $fechaFin = $data['fecha_fin'] ?? null;
        $estado = $this->normalizeString($data['estado'] ?? null);
        $tipo = $this->normalizeString($data['tipo'] ?? null);
        $descripcion = $this->normalizeString($data['descripcion'] ?? null);
        $idVehiculo = $data['id_vehiculo'] ?? null;
        $idMaquinaria = $data['id_maquinaria'] ?? null;

        if ($isUpdate && empty($id)) {
            $errors[] = 'El id_mantenimiento es obligatorio.';
        }

        if (empty($fechaInicio)) {
            $errors[] = 'La fecha de inicio es obligatoria.';
        }

        if (!empty($fechaFin) && !empty($fechaInicio) && strtotime($fechaFin) < strtotime($fechaInicio)) {
            $errors[] = 'La fecha de finalización no puede ser anterior a la fecha de inicio.';
        }

        $estados = ['Pendiente', 'En Proceso', 'Finalizado', 'Cancelado'];
        if ($estado === null || $estado === '') {
            $errors[] = 'El estado es obligatorio.';
        } elseif (!in_array($estado, $estados, true)) {
            $errors[] = 'El estado debe ser Pendiente, En Proceso, Finalizado o Cancelado.';
        }

        $tipos = ['Preventivo', 'Correctivo'];
        if ($tipo === null || $tipo === '') {
            $errors[] = 'El tipo de mantenimiento es obligatorio.';
        } elseif (!in_array($tipo, $tipos, true)) {
            $errors[] = 'El tipo debe ser Preventivo o Correctivo.';
        }

        if ($descripcion === null || $descripcion === '') {
            $errors[] = 'La descripción es obligatoria.';
        }

        $tieneVehiculo = $idVehiculo !== null && $idVehiculo !== '';
        $tieneMaquinaria = $idMaquinaria !== null && $idMaquinaria !== '';

        if ($tieneVehiculo && $tieneMaquinaria) {
            $errors[] = 'El mantenimiento no puede corresponder simultáneamente a un vehículo y una maquinaria.';
        }

        if (!$tieneVehiculo && !$tieneMaquinaria) {
            $errors[] = 'El mantenimiento debe corresponder a un vehículo o a una maquinaria.';
        }

        if ($tieneVehiculo && !ctype_digit((string)$idVehiculo)) {
            $errors[] = 'El id_vehiculo debe ser un número entero válido.';
        }

        if ($tieneMaquinaria && !ctype_digit((string)$idMaquinaria)) {
            $errors[] = 'El id_maquinaria debe ser un número entero válido.';
        }

        return $errors;
    }

    public function getAll($id = null) {
        $stmt = $this->mantenimiento->read($id);

        if (!$stmt) {
            return [
                "success" => false,
                "data" => [],
                "message" => "No se pudieron cargar los mantenimientos.",
                "statusCode" => 500
            ];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($id !== null && empty($rows)) {
            return [
                "success" => false,
                "data" => [],
                "message" => "Mantenimiento no encontrado.",
                "statusCode" => 404
            ];
        }

        return [
            "success" => true,
            "data" => $rows,
            "message" => "Mantenimientos cargados correctamente.",
            "statusCode" => 200
        ];
    }

    public function create($data) {
        $data = $data ?? [];
        $errors = $this->validatePayload($data, false);

        if ($errors) {
            return [
                "success" => false,
                "data" => null,
                "message" => "No se pudo registrar el mantenimiento.",
                "errors" => $errors,
                "statusCode" => 400
            ];
        }

        $this->mantenimiento->fecha_inicio = $data['fecha_inicio'];
        $this->mantenimiento->fecha_fin = $data['fecha_fin'] ?? null;
        $this->mantenimiento->estado = $this->normalizeString($data['estado']);
        $this->mantenimiento->tipo = $this->normalizeString($data['tipo']);
        $this->mantenimiento->descripcion = $this->normalizeString($data['descripcion']);
        $this->mantenimiento->id_vehiculo = isset($data['id_vehiculo']) && $data['id_vehiculo'] !== '' ? (int)$data['id_vehiculo'] : null;
        $this->mantenimiento->id_maquinaria = isset($data['id_maquinaria']) && $data['id_maquinaria'] !== '' ? (int)$data['id_maquinaria'] : null;

        if ($this->mantenimiento->create()) {
            return [
                "success" => true,
                "data" => ["id_mantenimiento" => $this->mantenimiento->id_mantenimiento],
                "message" => "Mantenimiento registrado correctamente.",
                "errors" => [],
                "statusCode" => 201
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "Error al registrar el mantenimiento.",
            "errors" => [],
            "statusCode" => 500
        ];
    }

    public function update($data) {
        $data = $data ?? [];
        $errors = $this->validatePayload($data, true);

        if ($errors) {
            return [
                "success" => false,
                "data" => null,
                "message" => "No se pudo actualizar el mantenimiento.",
                "errors" => $errors,
                "statusCode" => 400
            ];
        }

        $this->mantenimiento->id_mantenimiento = (int)$data['id_mantenimiento'];
        $this->mantenimiento->fecha_inicio = $data['fecha_inicio'];
        $this->mantenimiento->fecha_fin = $data['fecha_fin'] ?? null;
        $this->mantenimiento->estado = $this->normalizeString($data['estado']);
        $this->mantenimiento->tipo = $this->normalizeString($data['tipo']);
        $this->mantenimiento->descripcion = $this->normalizeString($data['descripcion']);
        $this->mantenimiento->id_vehiculo = isset($data['id_vehiculo']) && $data['id_vehiculo'] !== '' ? (int)$data['id_vehiculo'] : null;
        $this->mantenimiento->id_maquinaria = isset($data['id_maquinaria']) && $data['id_maquinaria'] !== '' ? (int)$data['id_maquinaria'] : null;

        if ($this->mantenimiento->update()) {
            return [
                "success" => true,
                "data" => null,
                "message" => "Mantenimiento actualizado correctamente.",
                "errors" => [],
                "statusCode" => 200
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "Error al actualizar el mantenimiento.",
            "errors" => [],
            "statusCode" => 500
        ];
    }

    public function delete($id) {
        $this->mantenimiento->id_mantenimiento = (int)$id;

        if ($this->mantenimiento->delete()) {
            return [
                "success" => true,
                "data" => null,
                "message" => "Mantenimiento eliminado correctamente.",
                "errors" => [],
                "statusCode" => 200
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "Error al eliminar el mantenimiento.",
            "errors" => [],
            "statusCode" => 500
        ];
    }
}