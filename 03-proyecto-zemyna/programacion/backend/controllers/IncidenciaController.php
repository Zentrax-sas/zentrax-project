<?php
require_once __DIR__ . '/../models/Incidencia.php';

class IncidenciaController {
    private $incidencia;

    public function __construct($db) {
        $this->incidencia = new Incidencia($db);
    }

    public function getAll() {
        $stmt = $this->incidencia->read();
        if ($stmt) {
            return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC), "message" => "Incidencias cargadas correctamente."];
        }
        return [
            "success" => true,
            "data" => [
                ["id_incidencia" => 1, "descripcion" => "Contenedor dañado, tapa rota.",            "fecha_reporte" => "2025-06-01 09:00:00", "estado" => "Pendiente",   "prioridad" => "Alta",  "tipo_problema" => "Daño físico",    "id_contenedor" => 1, "id_cuadrilla" => 1, "id_usuario" => 2],
                ["id_incidencia" => 2, "descripcion" => "Contenedor desbordado, necesita vaciado.", "fecha_reporte" => "2025-06-02 11:30:00", "estado" => "En Proceso",  "prioridad" => "Media", "tipo_problema" => "Desbordamiento", "id_contenedor" => 2, "id_cuadrilla" => 2, "id_usuario" => 3],
            ],
            "message" => "Incidencias cargadas en modo demo."
        ];
    }

    public function create($data) {
        $this->incidencia->descripcion   = $data['descripcion']   ?? null;
        $this->incidencia->fecha_reporte = $data['fecha_reporte'] ?? date('Y-m-d H:i:s');
        $this->incidencia->estado        = $data['estado']        ?? null;
        $this->incidencia->prioridad     = $data['prioridad']     ?? null;
        $this->incidencia->tipo_problema = $data['tipo_problema'] ?? null;
        $this->incidencia->id_contenedor = $data['id_contenedor'] ?? null;
        $this->incidencia->id_cuadrilla  = $data['id_cuadrilla']  ?? null;
        $this->incidencia->id_usuario    = $data['id_usuario']    ?? null;

        $estados   = ['Pendiente', 'En Proceso', 'Resuelta'];
        $prioridades = ['Baja', 'Media', 'Alta'];
        $errors = [];
        if (empty($this->incidencia->descripcion))   $errors[] = "La descripción es obligatoria.";
        if (!in_array($this->incidencia->estado, $estados))       $errors[] = "El estado debe ser Pendiente, En Proceso o Resuelta.";
        if (!in_array($this->incidencia->prioridad, $prioridades)) $errors[] = "La prioridad debe ser Baja, Media o Alta.";
        if (empty($this->incidencia->tipo_problema)) $errors[] = "El tipo de problema es obligatorio.";
        if (empty($this->incidencia->id_contenedor)) $errors[] = "El id_contenedor es obligatorio.";
        if (empty($this->incidencia->id_cuadrilla))  $errors[] = "El id_cuadrilla es obligatorio.";
        if (empty($this->incidencia->id_usuario))    $errors[] = "El id_usuario es obligatorio.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo registrar la incidencia.", "errors" => $errors];
        }

        if ($this->incidencia->create()) {
            return ["success" => true, "data" => null, "message" => "Incidencia registrada con éxito en Zemyna.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al registrar la incidencia.", "errors" => []];
    }

    public function update($data) {
        $this->incidencia->id_incidencia = $data['id_incidencia'] ?? null;
        $this->incidencia->descripcion   = $data['descripcion']   ?? null;
        $this->incidencia->fecha_reporte = $data['fecha_reporte'] ?? null;
        $this->incidencia->estado        = $data['estado']        ?? null;
        $this->incidencia->prioridad     = $data['prioridad']     ?? null;
        $this->incidencia->tipo_problema = $data['tipo_problema'] ?? null;
        $this->incidencia->id_contenedor = $data['id_contenedor'] ?? null;
        $this->incidencia->id_cuadrilla  = $data['id_cuadrilla']  ?? null;
        $this->incidencia->id_usuario    = $data['id_usuario']    ?? null;

        $errors = [];
        if (empty($this->incidencia->id_incidencia)) $errors[] = "El id_incidencia es obligatorio para actualizar.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo actualizar la incidencia.", "errors" => $errors];
        }

        if ($this->incidencia->update()) {
            return ["success" => true, "data" => null, "message" => "Incidencia actualizada con éxito.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al actualizar la incidencia.", "errors" => []];
    }

    public function delete($id) {
        $this->incidencia->id_incidencia = $id;
        if ($this->incidencia->delete()) {
            return ["success" => true, "data" => null, "message" => "Incidencia eliminada correctamente.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al eliminar la incidencia.", "errors" => []];
    }
}
