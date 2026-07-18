<?php
require_once __DIR__ . '/../models/Camion.php';

class CamionController {
    private $camion;

    public function __construct($db) {
        $this->camion = new Camion($db);
    }

    // Obtener todos los camiones
    public function getAll() {
        $stmt = $this->camion->read();

        if ($stmt) {
            $camiones = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($camiones);
        } else {
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
            echo json_encode($camionesMock);
        }
    }

    // Crear un camion nuevo
    public function create($data) {
        $this->camion->matricula = $data['matricula'] ?? null;
        $this->camion->capacidad_toneladas = $data['capacidad_toneladas'] ?? null;
        $this->camion->estado = $data['estado'] ?? 'Operativo';
        $this->camion->id_chofer_asignado = $data['id_chofer_asignado'] ?? null;

        if ($this->camion->create()) {
            echo json_encode(["success" => true, "message" => "Camión registrado correctamente."]);
        } else {
            echo json_encode(["success" => false, "message" => "Error al registrar el camión."]);
        }
    }

    // Actualizar datos del camion
    public function update($data) {
        $this->camion->id_camion = $data['id_camion'] ?? null;
        $this->camion->matricula = $data['matricula'] ?? null;
        $this->camion->capacidad_toneladas = $data['capacidad_toneladas'] ?? null;
        $this->camion->estado = $data['estado'] ?? null;
        $this->camion->id_chofer_asignado = $data['id_chofer_asignado'] ?? null;

        if ($this->camion->update()) {
            echo json_encode(["success" => true, "message" => "Camión actualizado correctamente."]);
        } else {
            echo json_encode(["success" => false, "message" => "Error al actualizar el camión."]);
        }
    }

    // Eliminar un camion
    public function delete($id) {
        $this->camion->id_camion = $id;

        if ($this->camion->delete()) {
            echo json_encode(["success" => true, "message" => "Camión eliminado del sistema."]);
        } else {
            echo json_encode(["success" => false, "message" => "Error al eliminar el camión."]);
        }
    }
}
?>
