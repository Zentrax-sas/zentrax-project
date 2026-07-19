<?php
require_once __DIR__ . '/../models/Contenedor.php';

class ContenedorController {
    private $contenedor;

    public function __construct($db) {
        $this->contenedor = new Contenedor($db);
    }

    public function getAll() {
        $stmt = $this->contenedor->read();

        if ($stmt) {
            $contenedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return ["success" => true, "data" => $contenedores, "message" => "Contenedores cargados correctamente."];
        }

        $contenedoresMock = [
            [
                "id_contenedor" => 101,
                "codigo" => "CH-101",
                "direccion" => "Av. Brasil y Lázaro Gadea",
                "capacidad_litros" => 3200,
                "tipo_residuo" => "Reciclable",
                "estado_llenado" => "Medio",
                "municipio" => "CH",
                "latitud" => -34.9142,
                "longitud" => -56.1495,
                "estado" => "verde"
            ],
            [
                "id_contenedor" => 102,
                "codigo" => "CH-102",
                "direccion" => "Brito del Pino y Chana",
                "capacidad_litros" => 2400,
                "tipo_residuo" => "Orgánico",
                "estado_llenado" => "Saturado",
                "municipio" => "CH",
                "latitud" => -34.9210,
                "longitud" => -56.1585,
                "estado" => "rojo"
            ]
        ];
        return ["success" => true, "data" => $contenedoresMock, "message" => "Contenedores cargados correctamente."];
    }

    public function create($data) {
        $this->contenedor->codigo = $data['codigo'] ?? null;
        $this->contenedor->direccion = $data['direccion'] ?? null;
        $this->contenedor->capacidad_litros = $data['capacidad_litros'] ?? 2400;
        $this->contenedor->tipo_residuo = $data['tipo_residuo'] ?? null;
        $this->contenedor->estado_llenado = $data['estado_llenado'] ?? 'Vacio';
        $this->contenedor->municipio = $data['municipio'] ?? null;
        $this->contenedor->latitud = $data['latitud'] ?? null;
        $this->contenedor->longitud = $data['longitud'] ?? null;
        $this->contenedor->estado = $data['estado'] ?? 'verde';

        if ($this->contenedor->create()) {
            return ["success" => true, "data" => null, "message" => "Contenedor urbano registrado con éxito en Zemyna."];
        }
        return ["success" => false, "data" => null, "message" => "Error al registrar el contenedor.", "errors" => ["Datos incompletos"]];
    }

    public function update($data) {
        $this->contenedor->id_contenedor = $data['id_contenedor'] ?? null;
        $this->contenedor->direccion = $data['direccion'] ?? null;
        $this->contenedor->capacidad_litros = $data['capacidad_litros'] ?? null;
        $this->contenedor->tipo_residuo = $data['tipo_residuo'] ?? null;
        $this->contenedor->estado_llenado = $data['estado_llenado'] ?? null;
        $this->contenedor->municipio = $data['municipio'] ?? null;
        $this->contenedor->latitud = $data['latitud'] ?? null;
        $this->contenedor->longitud = $data['longitud'] ?? null;
        $this->contenedor->estado = $data['estado'] ?? null;

        if ($this->contenedor->update()) {
            return ["success" => true, "data" => null, "message" => "Contenedor actualizado correctamente."];
        }
        return ["success" => false, "data" => null, "message" => "Error al actualizar el contenedor.", "errors" => ["No se pudo actualizar"]];
    }

    public function delete($id) {
        $this->contenedor->id_contenedor = $id;

        if ($this->contenedor->delete()) {
            return ["success" => true, "data" => null, "message" => "Contenedor removido del sistema."];
        }
        return ["success" => false, "data" => null, "message" => "Error al eliminar el contenedor.", "errors" => ["No se pudo borrar"]];
    }
}
?>