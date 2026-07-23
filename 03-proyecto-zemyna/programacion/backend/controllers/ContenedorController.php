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
                "id_contenedor" => "CH_RM_CL_101",
                "codigo" => "CH_RM_CL_101",
                "direccion" => "Av. Brasil y Lázaro Gadea",
                "capacidad_litros" => 3200,
                "tipo_residuo" => "Reciclable",
                "estado_llenado" => "Medio",
                "municipio" => "CH",
                "latitud" => -34.9011,
                "longitud" => -56.1645,
                "estado" => "verde"
            ],
            [
                "id_contenedor" => "CH_RS_CL_102",
                "codigo" => "CH_RS_CL_102",
                "direccion" => "Brito del Pino y Charrúa",
                "capacidad_litros" => 2400,
                "tipo_residuo" => "Orgánico",
                "estado_llenado" => "Saturado",
                "municipio" => "CH",
                "latitud" => -34.9034,
                "longitud" => -56.1682,
                "estado" => "rojo"
            ],
            [
                "id_contenedor" => "CH_RM_CL_103",
                "codigo" => "CH_RM_CL_103",
                "direccion" => "Pocitos y Av. Francisco Soca",
                "capacidad_litros" => 3200,
                "tipo_residuo" => "Reciclable",
                "estado_llenado" => "Bajo",
                "municipio" => "CH",
                "latitud" => -34.8985,
                "longitud" => -56.1610,
                "estado" => "verde"
            ],
            [
                "id_contenedor" => "CH_RS_CL_104",
                "codigo" => "CH_RS_CL_104",
                "direccion" => "Benito Blanco y Gabriel A. Pereira",
                "capacidad_litros" => 2400,
                "tipo_residuo" => "Orgánico",
                "estado_llenado" => "Medio",
                "municipio" => "CH",
                "latitud" => -34.9051,
                "longitud" => -56.1554,
                "estado" => "amarillo"
            ],
            [
                "id_contenedor" => "CH_RM_CL_105",
                "codigo" => "CH_RM_CL_105",
                "direccion" => "Juan Benito Blanco y Echevarriarza",
                "capacidad_litros" => 3200,
                "tipo_residuo" => "Reciclable",
                "estado_llenado" => "Alto",
                "municipio" => "CH",
                "latitud" => -34.9122,
                "longitud" => -56.1598,
                "estado" => "amarillo"
            ],
            [
                "id_contenedor" => "B_RM_CL_201",
                "codigo" => "B_RM_CL_201",
                "direccion" => "Av. 18 de Julio y Tacuarí",
                "capacidad_litros" => 3200,
                "tipo_residuo" => "Reciclable",
                "estado_llenado" => "Medio",
                "municipio" => "B",
                "latitud" => -34.9065,
                "longitud" => -56.1852,
                "estado" => "verde"
            ],
            [
                "id_contenedor" => "B_RS_CL_202",
                "codigo" => "B_RS_CL_202",
                "direccion" => "San José y Zelmar Michelini",
                "capacidad_litros" => 2400,
                "tipo_residuo" => "Orgánico",
                "estado_llenado" => "Saturado",
                "municipio" => "B",
                "latitud" => -34.9102,
                "longitud" => -56.1920,
                "estado" => "rojo"
            ],
            [
                "id_contenedor" => "B_RM_CL_203",
                "codigo" => "B_RM_CL_203",
                "direccion" => "Canelones y Juan Paullier",
                "capacidad_litros" => 3200,
                "tipo_residuo" => "Reciclable",
                "estado_llenado" => "Bajo",
                "municipio" => "B",
                "latitud" => -34.9021,
                "longitud" => -56.1789,
                "estado" => "verde"
            ],
            [
                "id_contenedor" => "B_RS_CL_204",
                "codigo" => "B_RS_CL_204",
                "direccion" => "Soriano y Ciudadela",
                "capacidad_litros" => 2400,
                "tipo_residuo" => "Orgánico",
                "estado_llenado" => "Alto",
                "municipio" => "B",
                "latitud" => -34.9088,
                "longitud" => -56.2014,
                "estado" => "amarillo"
            ],
            [
                "id_contenedor" => "B_RM_CL_205",
                "codigo" => "B_RM_CL_205",
                "direccion" => "Colonia y Arenal Grande",
                "capacidad_litros" => 3200,
                "tipo_residuo" => "Reciclable",
                "estado_llenado" => "Saturado",
                "municipio" => "B",
                "latitud" => -34.8994,
                "longitud" => -56.1895,
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