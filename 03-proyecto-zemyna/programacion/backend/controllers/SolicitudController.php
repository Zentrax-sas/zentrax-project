<?php
require_once __DIR__ . '/../models/Solicitud.php';

class SolicitudController {
    private $solicitud;

    public function __construct($db) {
        $this->solicitud = new Solicitud($db);
    }

    public function getAll() {
        $stmt = $this->solicitud->read();
        if ($stmt) {
            return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC), "message" => "Solicitudes cargadas correctamente."];
        }
        return [
            "success" => true,
            "data" => [
                ["id_solicitud" => 1, "fecha" => "2025-06-05 08:00:00", "descripcion" => "Retiro de electrodomestico viejo.", "direccion" => "Dr. Luis Bonavita 1294", "estado" => "Pendiente", "ci" => "11223344", "id_tipo_residuo" => 1, "email" => "martin@gmail.com", "telefono" => "092-333333", "tipo_solicitud" => "Retiro domiciliario", "tracking_number" => "REF-2025-AB123"],
                ["id_solicitud" => 2, "fecha" => "2025-06-06 09:30:00", "descripcion" => "Gran cantidad de cartones para retirar.", "direccion" => "Paraguay 1450", "estado" => "Programada", "ci" => "12345678", "id_tipo_residuo" => 2, "email" => "carlos@gmail.com", "telefono" => "092-111111", "tipo_solicitud" => "Retiro domiciliario", "tracking_number" => "REF-2025-XY789"]
            ],
            "message" => "Solicitudes cargadas en modo demo."
        ];
    }

    public function create($data) {
        $this->solicitud->fecha           = $data['fecha'] ?? date('Y-m-d H:i:s');
        $this->solicitud->descripcion     = $data['descripcion'] ?? null;
        $this->solicitud->direccion       = $data['direccion'] ?? null;
        $this->solicitud->estado          = $data['estado'] ?? 'Pendiente';
        $this->solicitud->ci              = $data['ci'] ?? '12345678';
        $this->solicitud->id_tipo_residuo = $data['id_tipo_residuo'] ?? $this->inferTipoResiduoId($data['tipo_solicitud'] ?? null, $data['descripcion'] ?? null);
        $this->solicitud->email           = $data['email'] ?? null;
        $this->solicitud->telefono        = $data['telefono'] ?? null;
        $this->solicitud->tipo_solicitud  = $data['tipo_solicitud'] ?? null;

        $estados = ['Pendiente', 'Programada', 'Finalizada', 'Cancelada'];
        $errors = [];
        if (empty($this->solicitud->descripcion)) $errors['descripcion'] = "La descripcion es obligatoria.";
        if (empty($this->solicitud->direccion)) $errors['direccion'] = "La direccion es obligatoria.";
        if (!in_array($this->solicitud->estado, $estados)) $errors['estado'] = "El estado debe ser Pendiente, Programada, Finalizada o Cancelada.";
        if (empty($this->solicitud->ci)) $errors['ci'] = "La CI del vecino es obligatoria.";
        if (empty($this->solicitud->id_tipo_residuo)) $errors['id_tipo_residuo'] = "El tipo de residuo es obligatorio.";
        if (empty($this->solicitud->email)) $errors['email'] = "El email es obligatorio.";
        elseif (!filter_var($this->solicitud->email, FILTER_VALIDATE_EMAIL)) $errors['email'] = "El email debe tener un formato valido.";
        if (empty($this->solicitud->telefono)) $errors['telefono'] = "El telefono es obligatorio.";
        if (empty($this->solicitud->tipo_solicitud)) $errors['tipo_solicitud'] = "El tipo de solicitud es obligatorio.";

        if ($errors) {
            return ["success" => false, "data" => null, "message" => "Datos incompletos o invalidos.", "errors" => $errors];
        }

        $year = date('Y');
        $randomCode = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $this->solicitud->tracking_number = "REF-{$year}-{$randomCode}";

        if ($this->solicitud->create()) {
            return [
                "success" => true,
                "data" => ["tracking_number" => $this->solicitud->tracking_number],
                "message" => "Solicitud de retiro registrada con exito en Zemyna.",
                "tracking_number" => $this->solicitud->tracking_number,
                "errors" => []
            ];
        }
        return ["success" => false, "data" => null, "message" => "Error al registrar la solicitud.", "errors" => []];
    }

    private function inferTipoResiduoId($tipoSolicitud, $descripcion) {
        $texto = strtolower(trim((string)($tipoSolicitud . ' ' . $descripcion)));
        if ($texto === '') return null;

        if (strpos($texto, 'papel') !== false || strpos($texto, 'carton') !== false) return 2;
        if (strpos($texto, 'plast') !== false) return 3;
        if (strpos($texto, 'vidrio') !== false) return 4;
        if (strpos($texto, 'metal') !== false) return 5;
        if (strpos($texto, 'electr') !== false) return 6;
        if (strpos($texto, 'pila') !== false || strpos($texto, 'bateria') !== false) return 7;
        if (strpos($texto, 'escombro') !== false) return 8;
        if (strpos($texto, 'voluminos') !== false) return 9;

        return 1;
    }

    public function update($data) {
        $this->solicitud->id_solicitud    = $data['id_solicitud'] ?? null;
        $this->solicitud->descripcion     = $data['descripcion'] ?? null;
        $this->solicitud->direccion       = $data['direccion'] ?? null;
        $this->solicitud->estado          = $data['estado'] ?? null;
        $this->solicitud->ci              = $data['ci'] ?? null;
        $this->solicitud->id_tipo_residuo = $data['id_tipo_residuo'] ?? null;
        $this->solicitud->email           = $data['email'] ?? null;
        $this->solicitud->telefono        = $data['telefono'] ?? null;
        $this->solicitud->tipo_solicitud  = $data['tipo_solicitud'] ?? null;

        if (empty($this->solicitud->id_solicitud)) {
            return ["success" => false, "data" => null, "message" => "No se pudo actualizar la solicitud.", "errors" => ["El id_solicitud es obligatorio para actualizar."]];
        }

        if ($this->solicitud->update()) {
            return ["success" => true, "data" => null, "message" => "Solicitud actualizada con exito.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al actualizar la solicitud.", "errors" => []];
    }

    public function delete($data) {
        $id = is_array($data) ? ($data['id_solicitud'] ?? null) : $data;
        $this->solicitud->id_solicitud = $id;
        if ($this->solicitud->delete()) {
            return ["success" => true, "data" => null, "message" => "Solicitud eliminada correctamente.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al eliminar la solicitud.", "errors" => []];
    }
}
