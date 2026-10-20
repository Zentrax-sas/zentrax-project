<?php
require_once __DIR__ . '/../models/Usa.php';

class UsaController {
    private $usa;

    public function __construct($db) {
        $this->usa = new Usa($db);
    }

    public function getAll($filters = []) {
        $id = $filters['id'] ?? null;
        $idCuadrilla = $filters['id_cuadrilla'] ?? null;
        $idVehiculo = $filters['id_vehiculo'] ?? null;

        $stmt = $this->usa->read($id, $idCuadrilla, $idVehiculo);

        if (!$stmt) {
            return [
                "success" => false,
                "data" => [],
                "message" => "No se pudieron cargar las asignaciones.",
                "statusCode" => 500
            ];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($id !== null && empty($rows)) {
            return [
                "success" => false,
                "data" => [],
                "message" => "Asignación no encontrada.",
                "statusCode" => 404
            ];
        }

        return [
            "success" => true,
            "data" => $rows,
            "message" => "Asignaciones cuadrilla-vehículo cargadas correctamente.",
            "statusCode" => 200
        ];
    }

    private function mutation(callable $fn): array {
        try { return $fn(); }
        catch (DomainException $e) { return ['success'=>false,'data'=>null,'message'=>$e->getMessage(),'errors'=>[],'statusCode'=>in_array($e->getCode(),[400,401,403,404,409,503],true)?$e->getCode():409]; }
        catch (PDOException $e) {
            $conflict = in_array((int)($e->errorInfo[1] ?? 0),[1062,1205,1213,1451],true);
            $unavailable=in_array((int)($e->errorInfo[1] ?? 0),[2002,2006,2013],true);
            return ['success'=>false,'data'=>null,'message'=>$conflict?'Conflicto con la relación operativa. Volvé a consultar.':'No se pudo modificar la relación.','errors'=>[],'statusCode'=>$conflict?409:($unavailable?503:500)];
        }
        catch (Throwable $e) { return ['success'=>false,'data'=>null,'message'=>'No se pudo modificar la relación.','errors'=>[],'statusCode'=>500]; }
    }
    public function create($data) { return $this->mutation(fn()=>$this->createInternal($data)); }
    public function update($data) { return $this->mutation(fn()=>$this->updateInternal($data)); }
    public function delete($id) { return $this->mutation(fn()=>$this->deleteInternal($id)); }

    private function createInternal($data) {
        $data = $data ?? [];

        $this->usa->id_cuadrilla = $data['id_cuadrilla'] ?? null;
        $this->usa->id_vehiculo = $data['id_vehiculo'] ?? null;

        $errors = [];

        if (empty($this->usa->id_cuadrilla)) {
            $errors[] = "El id_cuadrilla es obligatorio.";
        }

        if (empty($this->usa->id_vehiculo)) {
            $errors[] = "El id_vehiculo es obligatorio.";
        }

        if ($errors) {
            return [
                "success" => false,
                "data" => null,
                "message" => "No se pudo registrar la asignación.",
                "errors" => $errors,
                "statusCode" => 400
            ];
        }

        if ($this->usa->create()) {
            return [
                "success" => true,
                "data" => ["id_usa" => $this->usa->id_usa],
                "message" => "Vehículo asignado a la cuadrilla correctamente.",
                "errors" => [],
                "statusCode" => 201
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "Error al registrar la asignación.",
            "errors" => [],
            "statusCode" => 500
        ];
    }

    private function updateInternal($data) {
        $data = $data ?? [];

        $this->usa->id_usa = $data['id_usa'] ?? null;
        $this->usa->id_cuadrilla = $data['id_cuadrilla'] ?? null;
        $this->usa->id_vehiculo = $data['id_vehiculo'] ?? null;

        $errors = [];

        if (empty($this->usa->id_usa)) {
            $errors[] = "El id_usa es obligatorio.";
        }

        if (empty($this->usa->id_cuadrilla)) {
            $errors[] = "El id_cuadrilla es obligatorio.";
        }

        if (empty($this->usa->id_vehiculo)) {
            $errors[] = "El id_vehiculo es obligatorio.";
        }

        if ($errors) {
            return [
                "success" => false,
                "data" => null,
                "message" => "No se pudo actualizar la asignación.",
                "errors" => $errors,
                "statusCode" => 400
            ];
        }

        if ($this->usa->update()) {
            return [
                "success" => true,
                "data" => null,
                "message" => "Asignación actualizada correctamente.",
                "errors" => [],
                "statusCode" => 200
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "Error al actualizar la asignación.",
            "errors" => [],
            "statusCode" => 500
        ];
    }

    private function deleteInternal($id) {
        $this->usa->id_usa = (int)$id;

        if ($this->usa->delete()) {
            return [
                "success" => true,
                "data" => null,
                "message" => "Asignación eliminada correctamente.",
                "errors" => [],
                "statusCode" => 200
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "Error al eliminar la asignación.",
            "errors" => [],
            "statusCode" => 500
        ];
    }
}
