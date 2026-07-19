<?php
require_once __DIR__ . '/../models/Camion.php';

class CamionController {
    private $camion;

    public function __construct($db) {
        $this->camion = new Camion($db);
    }

    public function getAll() {
        $stmt = $this->camion->read();

        if ($stmt) {
            $camiones = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return ["success" => true, "data" => $camiones, "message" => "Camiones cargados correctamente."];
        }

        $camionesMock = [
            [
                "id_camion" => 1,
                "matricula" => "ABC-123",
                "capacidad_toneladas" => 8.5,
                "estado" => "Operativo",
                "id_chofer_asignado" => 3
            ],
            [
                "id_camion" => 2,
                "matricula" => "XYZ-789",
                "capacidad_toneladas" => 6.0,
                "estado" => "En Ruta",
                "id_chofer_asignado" => null
            ]
        ];
        return ["success" => true, "data" => $camionesMock, "message" => "Camiones cargados correctamente."];
    }

    public function create($data) {
        $this->camion->matricula = $data['matricula'] ?? null;
        $this->camion->capacidad_toneladas = $data['capacidad_toneladas'] ?? null;
        $this->camion->estado = $data['estado'] ?? 'Operativo';
        $this->camion->id_chofer_asignado = $data['id_chofer_asignado'] ?? null;

        if ($this->camion->create()) {
            return ["success" => true, "data" => null, "message" => "Camión registrado correctamente."];
        }
        return ["success" => false, "data" => null, "message" => "Error al registrar el camión.", "errors" => ["Datos incompletos"]];
    }

    public function update($data) {
        $this->camion->id_camion = $data['id_camion'] ?? null;
        $this->camion->matricula = $data['matricula'] ?? null;
        $this->camion->capacidad_toneladas = $data['capacidad_toneladas'] ?? null;
        $this->camion->estado = $data['estado'] ?? null;
        $this->camion->id_chofer_asignado = $data['id_chofer_asignado'] ?? null;

        if ($this->camion->update()) {
            return ["success" => true, "data" => null, "message" => "Camión actualizado correctamente."];
        }
        return ["success" => false, "data" => null, "message" => "Error al actualizar el camión.", "errors" => ["No se pudo actualizar"]];
    }

    public function delete($id) {
        $this->camion->id_camion = $id;

        if ($this->camion->delete()) {
            return ["success" => true, "data" => null, "message" => "Camión eliminado del sistema."];
        }
        return ["success" => false, "data" => null, "message" => "Error al eliminar el camión.", "errors" => ["No se pudo borrar"]];
    }
}
?>
