<?php
require_once __DIR__ . '/../models/Denuncia.php';

class DenunciaController {
    private $denuncia;

    public function __construct($db) {
        $this->denuncia = new Denuncia($db);
    }

    public function getAll($id = null) {
        $stmt = $this->denuncia->read($id);

        if ($stmt) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($id !== null && empty($rows)) {
                return [
                    "success" => false,
                    "data" => [],
                    "message" => "Denuncia no encontrada.",
                    "statusCode" => 404
                ];
            }

            return [
                "success" => true,
                "data" => $rows,
                "message" => "Denuncias cargadas correctamente.",
                "statusCode" => 200
            ];
        }

        return [
            "success" => false,
            "data" => [],
            "message" => "No se pudieron cargar las denuncias.",
            "statusCode" => 500
        ];
    }

    public function create($data) {
        $this->denuncia->fecha = $data['fecha'] ?? date('Y-m-d H:i:s');
        $this->denuncia->descripcion = trim($data['descripcion'] ?? '');
        $this->denuncia->ci = trim($data['ci'] ?? '');
        $this->denuncia->id_incidencia = $data['id_incidencia'] ?? null;

        $errors = [];

        if ($this->denuncia->descripcion === '') {
            $errors[] = "La descripción es obligatoria.";
        }

        if ($this->denuncia->ci === '') {
            $errors[] = "La CI del vecino es obligatoria.";
        }

        if (empty($this->denuncia->id_incidencia)) {
            $errors[] = "El id_incidencia es obligatorio.";
        }

        if (strtotime($this->denuncia->fecha) > time()) {
            $errors[] = "La fecha de la denuncia no puede ser posterior a la fecha actual.";
        }

        if ($errors) {
            return [
                "success" => false,
                "data" => null,
                "message" => "No se pudo registrar la denuncia.",
                "errors" => $errors,
                "statusCode" => 400
            ];
        }

        if ($this->denuncia->create()) {
            return [
                "success" => true,
                "data" => ["id_denuncia" => $this->denuncia->id_denuncia],
                "message" => "Denuncia registrada con éxito.",
                "errors" => [],
                "statusCode" => 201
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "Error al registrar la denuncia.",
            "errors" => [],
            "statusCode" => 500
        ];
    }

    public function update($data) {
        $this->denuncia->id_denuncia = $data['id_denuncia'] ?? null;
        $this->denuncia->fecha = $data['fecha'] ?? null;
        $this->denuncia->descripcion = trim($data['descripcion'] ?? '');
        $this->denuncia->ci = trim($data['ci'] ?? '');
        $this->denuncia->id_incidencia = $data['id_incidencia'] ?? null;

        $errors = [];

        if (empty($this->denuncia->id_denuncia)) {
            $errors[] = "El id_denuncia es obligatorio para actualizar.";
        }

        if (empty($this->denuncia->fecha)) {
            $errors[] = "La fecha es obligatoria.";
        } elseif (strtotime($this->denuncia->fecha) > time()) {
            $errors[] = "La fecha de la denuncia no puede ser posterior a la fecha actual.";
        }

        if ($this->denuncia->descripcion === '') {
            $errors[] = "La descripción es obligatoria.";
        }

        if ($this->denuncia->ci === '') {
            $errors[] = "La CI del vecino es obligatoria.";
        }

        if (empty($this->denuncia->id_incidencia)) {
            $errors[] = "El id_incidencia es obligatorio.";
        }

        if ($errors) {
            return [
                "success" => false,
                "data" => null,
                "message" => "No se pudo actualizar la denuncia.",
                "errors" => $errors,
                "statusCode" => 400
            ];
        }

        if ($this->denuncia->update()) {
            return [
                "success" => true,
                "data" => null,
                "message" => "Denuncia actualizada con éxito.",
                "errors" => [],
                "statusCode" => 200
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "Error al actualizar la denuncia.",
            "errors" => [],
            "statusCode" => 500
        ];
    }

    public function delete($id) {
        $this->denuncia->id_denuncia = $id;

        if ($this->denuncia->delete()) {
            return [
                "success" => true,
                "data" => null,
                "message" => "Denuncia eliminada correctamente.",
                "errors" => [],
                "statusCode" => 200
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "Error al eliminar la denuncia.",
            "errors" => [],
            "statusCode" => 500
        ];
    }
}