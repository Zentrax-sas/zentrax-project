<?php
require_once __DIR__ . '/../models/Vehiculo.php';

class VehiculoController {
    private $vehiculo;

    public function __construct($db) {
        $this->vehiculo = new Vehiculo($db);
    }

    public function getAll() {
        $stmt = $this->vehiculo->read();
        if ($stmt) {
            return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC), "message" => "Vehículos cargados correctamente."];
        }
        return [
            "success" => true,
            "data" => [
                ["id_vehiculo" => 1, "matricula" => "ABC1234", "marca" => "Mercedes-Benz", "modelo" => "Atego 1725", "capacidad_carga" => 8.50,  "estado" => "Disponible",  "id_tipo_residuo" => 1],
                ["id_vehiculo" => 2, "matricula" => "XYZ5678", "marca" => "Volvo",         "modelo" => "FE 280",     "capacidad_carga" => 6.00,  "estado" => "En Servicio", "id_tipo_residuo" => 2],
                ["id_vehiculo" => 3, "matricula" => "MNO9012", "marca" => "Scania",        "modelo" => "P 360",      "capacidad_carga" => 10.00, "estado" => "Disponible",  "id_tipo_residuo" => 3],
            ],
            "message" => "Vehículos cargados en modo demo."
        ];
    }

    public function create($data) {
        $this->vehiculo->matricula       = $data['matricula']       ?? null;
        $this->vehiculo->marca           = $data['marca']           ?? null;
        $this->vehiculo->modelo          = $data['modelo']          ?? null;
        $this->vehiculo->capacidad_carga = $data['capacidad_carga'] ?? null;
        $this->vehiculo->estado          = $data['estado']          ?? null;
        $this->vehiculo->id_tipo_residuo = $data['id_tipo_residuo'] ?? null;

        $estados = ['Disponible', 'En Servicio', 'En Mantenimiento'];
        $errors  = [];
        if (empty($this->vehiculo->matricula))       $errors[] = "La matrícula es obligatoria.";
        if (empty($this->vehiculo->marca))           $errors[] = "La marca es obligatoria.";
        if (empty($this->vehiculo->modelo))          $errors[] = "El modelo es obligatorio.";
        if (!isset($data['capacidad_carga']) || $data['capacidad_carga'] <= 0) $errors[] = "La capacidad de carga debe ser un número positivo.";
        if (!in_array($this->vehiculo->estado, $estados)) $errors[] = "El estado debe ser Disponible, En Servicio o En Mantenimiento.";
        if (empty($this->vehiculo->id_tipo_residuo)) $errors[] = "El id_tipo_residuo es obligatorio.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo registrar el vehículo.", "errors" => $errors];
        }

        if ($this->vehiculo->create()) {
            return ["success" => true, "data" => null, "message" => "Vehículo registrado con éxito en Zemyna.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al registrar el vehículo.", "errors" => []];
    }

    public function update($data) {
        $this->vehiculo->id_vehiculo     = $data['id_vehiculo']     ?? null;
        $this->vehiculo->matricula       = $data['matricula']       ?? null;
        $this->vehiculo->marca           = $data['marca']           ?? null;
        $this->vehiculo->modelo          = $data['modelo']          ?? null;
        $this->vehiculo->capacidad_carga = $data['capacidad_carga'] ?? null;
        $this->vehiculo->estado          = $data['estado']          ?? null;
        $this->vehiculo->id_tipo_residuo = $data['id_tipo_residuo'] ?? null;

        $errors = [];
        if (empty($this->vehiculo->id_vehiculo)) $errors[] = "El id_vehiculo es obligatorio para actualizar.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo actualizar el vehículo.", "errors" => $errors];
        }

        if ($this->vehiculo->update()) {
            return ["success" => true, "data" => null, "message" => "Vehículo actualizado con éxito.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al actualizar el vehículo.", "errors" => []];
    }

    public function delete($id) {
        $this->vehiculo->id_vehiculo = $id;
        if ($this->vehiculo->delete()) {
            return ["success" => true, "data" => null, "message" => "Vehículo eliminado correctamente.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al eliminar el vehículo.", "errors" => []];
    }
}
