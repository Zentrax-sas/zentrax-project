<?php
require_once __DIR__ . '/../models/Reclamo.php';

class ReclamoController {
    private $reclamo;

    public function __construct($db) {
        $this->reclamo = new Reclamo($db);
    }

    public function getAll() {
        $stmt = $this->reclamo->read();
        if ($stmt) {
            return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC), "message" => "Reclamos cargados correctamente."];
        }
        return [
            "success" => true,
            "data" => [
                ["id_reclamo" => 1, "fecha" => "2025-06-01 10:00:00", "descripcion" => "El contenedor de mi cuadra está roto desde hace días.", "estado" => "Pendiente",  "ci" => "12345678", "id_incidencia" => 1],
                ["id_reclamo" => 2, "fecha" => "2025-06-02 12:00:00", "descripcion" => "Hay residuos en la vereda por desbordamiento.",         "estado" => "En Proceso", "ci" => "87654321", "id_incidencia" => 2],
            ],
            "message" => "Reclamos cargados en modo demo."
        ];
    }

    public function create($data) {
        $this->reclamo->fecha         = $data['fecha']         ?? date('Y-m-d H:i:s');
        $this->reclamo->descripcion   = $data['descripcion']   ?? null;
        $this->reclamo->estado        = $data['estado']        ?? null;
        $this->reclamo->ci            = $data['ci']            ?? null;
        $this->reclamo->id_incidencia = $data['id_incidencia'] ?? null;

        $estados = ['Pendiente', 'En Proceso', 'Resuelto'];
        $errors  = [];
        if (empty($this->reclamo->descripcion))   $errors[] = "La descripción es obligatoria.";
        if (!in_array($this->reclamo->estado, $estados)) $errors[] = "El estado debe ser Pendiente, En Proceso o Resuelto.";
        if (empty($this->reclamo->ci))            $errors[] = "La CI del vecino es obligatoria.";
        if (empty($this->reclamo->id_incidencia)) $errors[] = "El id_incidencia es obligatorio.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo registrar el reclamo.", "errors" => $errors];
        }

        if ($this->reclamo->create()) {
            return ["success" => true, "data" => null, "message" => "Reclamo registrado con éxito en Zemyna.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al registrar el reclamo.", "errors" => []];
    }

    public function update($data) {
        $this->reclamo->id_reclamo    = $data['id_reclamo']    ?? null;
        $this->reclamo->fecha         = $data['fecha']         ?? null;
        $this->reclamo->descripcion   = $data['descripcion']   ?? null;
        $this->reclamo->estado        = $data['estado']        ?? null;
        $this->reclamo->ci            = $data['ci']            ?? null;
        $this->reclamo->id_incidencia = $data['id_incidencia'] ?? null;

        $errors = [];
        if (empty($this->reclamo->id_reclamo)) $errors[] = "El id_reclamo es obligatorio para actualizar.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo actualizar el reclamo.", "errors" => $errors];
        }

        if ($this->reclamo->update()) {
            return ["success" => true, "data" => null, "message" => "Reclamo actualizado con éxito.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al actualizar el reclamo.", "errors" => []];
    }

    public function delete($id) {
        $this->reclamo->id_reclamo = $id;
        if ($this->reclamo->delete()) {
            return ["success" => true, "data" => null, "message" => "Reclamo eliminado correctamente.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al eliminar el reclamo.", "errors" => []];
    }
}
