<?php
require_once __DIR__ . '/../models/Recorrido.php';

class RecorridoController {
    private $recorrido;

    public function __construct($db) {
        $this->recorrido = new Recorrido($db);
    }

    public function getAll($filters = []) {
        $id = $filters['id'] ?? null;
        $idRuta = $filters['id_ruta'] ?? null;

        $stmt = $this->recorrido->read($id, $idRuta);

        if (!$stmt) {
            return [
                "success" => false,
                "data" => [],
                "message" => "No se pudieron cargar los recorridos.",
                "statusCode" => 500
            ];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($id !== null && empty($rows)) {
            return [
                "success" => false,
                "data" => [],
                "message" => "Recorrido no encontrado.",
                "statusCode" => 404
            ];
        }

        return [
            "success" => true,
            "data" => $rows,
            "message" => "Recorridos cargados correctamente.",
            "statusCode" => 200
        ];
    }

    public function create($data) {
        $data = $data ?? [];

        $this->recorrido->fecha_inicio = $data['fecha_inicio'] ?? null;
        $this->recorrido->fecha_fin = $data['fecha_fin'] ?? null;
        $this->recorrido->estado = $data['estado'] ?? 'Pendiente';
        $this->recorrido->id_ruta = $data['id_ruta'] ?? null;

        $errors = [];
        $estados = ['Pendiente', 'En Proceso', 'Finalizado', 'Cancelado'];

        if (empty($this->recorrido->fecha_inicio)) {
            $errors[] = "La fecha de inicio es obligatoria.";
        }

        if (!in_array($this->recorrido->estado, $estados, true)) {
            $errors[] = "El estado debe ser Pendiente, En Proceso, Finalizado o Cancelado.";
        }

        if (empty($this->recorrido->id_ruta)) {
            $errors[] = "El id_ruta es obligatorio.";
        }

        if (!empty($this->recorrido->fecha_fin) &&
            strtotime($this->recorrido->fecha_fin) < strtotime($this->recorrido->fecha_inicio)) {
            $errors[] = "La fecha de finalización no puede ser anterior a la fecha de inicio.";
        }

        if ($errors) {
            return [
                "success" => false,
                "data" => null,
                "message" => "No se pudo registrar el recorrido.",
                "errors" => $errors,
                "statusCode" => 400
            ];
        }

        if ($this->recorrido->create()) {
            return [
                "success" => true,
                "data" => ["id_recorrido" => $this->recorrido->id_recorrido],
                "message" => "Recorrido registrado correctamente.",
                "errors" => [],
                "statusCode" => 201
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "Error al registrar el recorrido.",
            "errors" => [],
            "statusCode" => 500
        ];
    }

    public function update($data) {
        $data = $data ?? [];

        $this->recorrido->id_recorrido = $data['id_recorrido'] ?? null;
        $this->recorrido->fecha_inicio = $data['fecha_inicio'] ?? null;
        $this->recorrido->fecha_fin = $data['fecha_fin'] ?? null;
        $this->recorrido->estado = $data['estado'] ?? null;
        $this->recorrido->id_ruta = $data['id_ruta'] ?? null;

        $errors = [];
        $estados = ['Pendiente', 'En Proceso', 'Finalizado', 'Cancelado'];

        if (empty($this->recorrido->id_recorrido)) {
            $errors[] = "El id_recorrido es obligatorio.";
        }

        if (empty($this->recorrido->fecha_inicio)) {
            $errors[] = "La fecha de inicio es obligatoria.";
        }

        if (!in_array($this->recorrido->estado, $estados, true)) {
            $errors[] = "El estado debe ser Pendiente, En Proceso, Finalizado o Cancelado.";
        }

        if (empty($this->recorrido->id_ruta)) {
            $errors[] = "El id_ruta es obligatorio.";
        }

        if (!empty($this->recorrido->fecha_fin) &&
            strtotime($this->recorrido->fecha_fin) < strtotime($this->recorrido->fecha_inicio)) {
            $errors[] = "La fecha de finalización no puede ser anterior a la fecha de inicio.";
        }

        if ($errors) {
            return [
                "success" => false,
                "data" => null,
                "message" => "No se pudo actualizar el recorrido.",
                "errors" => $errors,
                "statusCode" => 400
            ];
        }

        if ($this->recorrido->update()) {
            return [
                "success" => true,
                "data" => null,
                "message" => "Recorrido actualizado correctamente.",
                "errors" => [],
                "statusCode" => 200
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "Error al actualizar el recorrido.",
            "errors" => [],
            "statusCode" => 500
        ];
    }

    public function delete($id) {
        $this->recorrido->id_recorrido = (int)$id;

        if ($this->recorrido->delete()) {
            return [
                "success" => true,
                "data" => null,
                "message" => "Recorrido eliminado correctamente.",
                "errors" => [],
                "statusCode" => 200
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "Error al eliminar el recorrido.",
            "errors" => [],
            "statusCode" => 500
        ];
    }
}