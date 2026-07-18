<?php
require_once __DIR__ . '/../models/Contenedor.php';

class ContenedorController {
    private $contenedor;

    public function __construct($db) {
        $this->contenedor = new Contenedor($db);
    }

    // Obtener todos los contenedores
    public function getAll() {
        $stmt = $this->contenedor->read();
        
        if ($stmt) {
            $contenedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($contenedores);
        } else {
            // Datos de prueba para simular el mapa y las tablas del Municipio CH
            $contenedoresMock = [
                [
                    "id_contenedor" => 101,
                    "direccion" => "Av. Brasil y Lazaro Gadea",
                    "capacidad_litros" => 3200,
                    "tipo_residuo" => "Reciclable",
                    "estado_llenado" => "Medio",
                    "municipio" => "CH"
                ],
                [
                    "id_contenedor" => 102,
                    "direccion" => "Brito del Pino y Chana",
                    "capacidad_litros" => 2400,
                    "tipo_residuo" => "Organico",
                    "estado_llenado" => "Saturado",
                    "municipio" => "CH"
                ]
            ];
            echo json_encode($contenedoresMock);
        }
    }

    // Crear un contenedor nuevo (Formulario del Administrador)
    public function create($data) {
        $this->contenedor->direccion = $data['direccion'] ?? null;
        $this->contenedor->capacidad_litros = $data['capacidad_litros'] ?? 2400; // Valor por defecto si no viene
        $this->contenedor->tipo_residuo = $data['tipo_residuo'] ?? null;
        $this->contenedor->estado_llenado = $data['estado_llenado'] ?? 'Vacio'; // Todo contenedor nuevo arranca vacío
        $this->contenedor->municipio = $data['municipio'] ?? null;

        if($this->contenedor->create()) {
            echo json_encode(["success" => true, "message" => "Contenedor urbano registrado con éxito en Zemyna."]);
        } else {
            echo json_encode(["success" => false, "message" => "Error al registrar el contenedor."]);
        }
    }

    // Actualizar datos o estado de llenado (Ej: cuando el recolector pasa o un sensor avisa)
    public function update($data) {
        $this->contenedor->id_contenedor = $data['id_contenedor'] ?? null;
        $this->contenedor->direccion = $data['direccion'] ?? null;
        $this->contenedor->capacidad_litros = $data['capacidad_litros'] ?? null;
        $this->contenedor->tipo_residuo = $data['tipo_residuo'] ?? null;
        $this->contenedor->estado_llenado = $data['estado_llenado'] ?? null;
        $this->contenedor->municipio = $data['municipio'] ?? null;

        if($this->contenedor->update()) {
            echo json_encode(["success" => true, "message" => "Contenedor actualizado correctamente."]);
        } else {
            echo json_encode(["success" => false, "message" => "Error al actualizar el contenedor."]);
        }
    }

    // Dar de baja un contenedor roto o removido
    public function delete($id) {
        $this->contenedor->id_contenedor = $id;

        if($this->contenedor->delete()) {
            echo json_encode(["success" => true, "message" => "Contenedor removido del sistema."]);
        } else {
            echo json_encode(["success" => false, "message" => "Error al eliminar el contenedor."]);
        }
    }
}
?>