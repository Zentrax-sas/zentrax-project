<?php
require_once __DIR__ . '/../models/Participa.php';

class ParticipaController {
    private $participa;

    public function __construct($db) {
        $this->participa = new Participa($db);
    }

    public function getAll($filters = []) {
        $id = $filters['id'] ?? null;
        $idRecorrido = $filters['id_recorrido'] ?? null;

        $stmt = $this->participa->read($id, $idRecorrido);

        if (!$stmt) {
            return [
                "success" => false,
                "data" => [],
                "message" => "No se pudieron cargar las participaciones.",
                "statusCode" => 500
            ];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($id !== null && empty($rows)) {
            return [
                "success" => false,
                "data" => [],
                "message" => "Participación no encontrada.",
                "statusCode" => 404
            ];
        }

        return [
            "success" => true,
            "data" => $rows,
            "message" => "Participaciones cargadas correctamente.",
            "statusCode" => 200
        ];
    }

    private function mutation(callable $fn): array {
        try { return $fn(); }
        catch (DomainException $e) { return ['success'=>false,'data'=>null,'message'=>$e->getMessage(),'errors'=>[],'statusCode'=>in_array($e->getCode(),[400,401,403,404,409,503],true)?$e->getCode():409]; }
        catch (PDOException $e) {
            $code=(int)($e->errorInfo[1] ?? 0);
            return ['success'=>false,'data'=>null,'message'=>'No se pudo modificar la participación. Volvé a consultar.','errors'=>[],'statusCode'=>in_array($code,[1062,1205,1213,1451],true)?409:(in_array($code,[2002,2006,2013],true)?503:500)];
        }
        catch (Throwable $e) { return ['success'=>false,'data'=>null,'message'=>'No se pudo modificar la participación.','errors'=>[],'statusCode'=>500]; }
    }
    public function create($data) { return $this->mutation(fn()=>$this->createInternal($data)); }
    public function update($data) { return $this->mutation(fn()=>$this->updateInternal($data)); }
    public function delete($id) { return $this->mutation(fn()=>$this->deleteInternal($id)); }

    private function createInternal($data) {
        $data = $data ?? [];

        $this->participa->id_usa = $data['id_usa'] ?? null;
        $this->participa->id_recorrido = $data['id_recorrido'] ?? null;
        $this->participa->hora_inicio = $data['hora_inicio'] ?? null;
        $this->participa->hora_fin = $data['hora_fin'] ?? null;
        $this->participa->motivo_fin = isset($data['motivo_fin']) ? trim($data['motivo_fin']) : null;

        $errors = [];

        if (empty($this->participa->id_usa)) {
            $errors[] = "El id_usa es obligatorio.";
        }

        if (empty($this->participa->id_recorrido)) {
            $errors[] = "El id_recorrido es obligatorio.";
        }

        if (empty($this->participa->hora_inicio)) {
            $errors[] = "La hora de inicio es obligatoria.";
        }

        if (!empty($this->participa->hora_fin) &&
            $this->participa->hora_fin < $this->participa->hora_inicio) {
            $errors[] = "La hora de finalización no puede ser anterior a la hora de inicio.";
        }

        if ($errors) {
            return [
                "success" => false,
                "data" => null,
                "message" => "No se pudo registrar la participación.",
                "errors" => $errors,
                "statusCode" => 400
            ];
        }

        if ($this->participa->create()) {
            return [
                "success" => true,
                "data" => ["id_participa" => $this->participa->id_participa],
                "message" => "Participación registrada correctamente.",
                "errors" => [],
                "statusCode" => 201
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "Error al registrar la participación.",
            "errors" => [],
            "statusCode" => 500
        ];
    }

    private function updateInternal($data) {
        $data = $data ?? [];

        $this->participa->id_participa = $data['id_participa'] ?? null;
        $this->participa->id_usa = $data['id_usa'] ?? null;
        $this->participa->id_recorrido = $data['id_recorrido'] ?? null;
        $this->participa->hora_inicio = $data['hora_inicio'] ?? null;
        $this->participa->hora_fin = $data['hora_fin'] ?? null;
        $this->participa->motivo_fin = isset($data['motivo_fin']) ? trim($data['motivo_fin']) : null;

        $errors = [];

        if (empty($this->participa->id_participa)) {
            $errors[] = "El id_participa es obligatorio.";
        }

        if (empty($this->participa->id_usa)) {
            $errors[] = "El id_usa es obligatorio.";
        }

        if (empty($this->participa->id_recorrido)) {
            $errors[] = "El id_recorrido es obligatorio.";
        }

        if (empty($this->participa->hora_inicio)) {
            $errors[] = "La hora de inicio es obligatoria.";
        }

        if (!empty($this->participa->hora_fin) &&
            $this->participa->hora_fin < $this->participa->hora_inicio) {
            $errors[] = "La hora de finalización no puede ser anterior a la hora de inicio.";
        }

        if ($errors) {
            return [
                "success" => false,
                "data" => null,
                "message" => "No se pudo actualizar la participación.",
                "errors" => $errors,
                "statusCode" => 400
            ];
        }

        if ($this->participa->update()) {
            return [
                "success" => true,
                "data" => null,
                "message" => "Participación actualizada correctamente.",
                "errors" => [],
                "statusCode" => 200
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "Error al actualizar la participación.",
            "errors" => [],
            "statusCode" => 500
        ];
    }

    private function deleteInternal($id) {
        $this->participa->id_participa = (int)$id;

        if ($this->participa->delete()) {
            return [
                "success" => true,
                "data" => null,
                "message" => "Participación eliminada correctamente.",
                "errors" => [],
                "statusCode" => 200
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "Error al eliminar la participación.",
            "errors" => [],
            "statusCode" => 500
        ];
    }
}
