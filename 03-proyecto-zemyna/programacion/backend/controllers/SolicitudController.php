<?php
require_once __DIR__ . '/../models/Solicitud.php';

class SolicitudController {
    private $solicitud;

    public function __construct($db) {
        $this->solicitud = new Solicitud($db);
    }

    private function generateTrackingNumber(array $attempted): string {
        $base = hexdec(substr(bin2hex(random_bytes(3)), 0, 5));
        for ($offset = 0; $offset <= count($attempted); $offset++) {
            $code = strtoupper(str_pad(dechex(($base + $offset) % 0x100000), 5, '0', STR_PAD_LEFT));
            $trackingNumber = 'REF-' . date('Y') . '-' . $code;
            if (!isset($attempted[$trackingNumber])) {
                return $trackingNumber;
            }
        }
        throw new RuntimeException('No se pudo generar un tracking único en memoria.');
    }

    private function isTrackingDuplicate(PDOException $exception): bool {
        $errorInfo = $exception->errorInfo ?? null;
        return is_array($errorInfo)
            && (string)($errorInfo[0] ?? '') === '23000'
            && (int)($errorInfo[1] ?? 0) === 1062;
    }

    public function getAll() {
        $stmt = $this->solicitud->read();
        if (!$stmt) {
            return [
                "success" => false,
                "data" => [],
                "message" => "No se pudo conectar con la base de datos de solicitudes.",
                "statusCode" => 500
            ];
        }

        return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC), "message" => "Solicitudes cargadas correctamente.", "statusCode" => 200];
    }

    public function create($data) {
        $this->solicitud->fecha           = $data['fecha'] ?? date('Y-m-d H:i:s');
        $this->solicitud->descripcion     = $data['descripcion'] ?? null;
        $this->solicitud->direccion       = $data['direccion'] ?? null;
        $this->solicitud->estado          = $data['estado'] ?? 'Pendiente';
        $this->solicitud->id_tipo_residuo = $data['id_tipo_residuo'] ?? $this->inferTipoResiduoId($data['tipo_solicitud'] ?? null, $data['descripcion'] ?? null);
        $this->solicitud->email           = $data['email'] ?? null;
        $this->solicitud->telefono        = $data['telefono'] ?? null;
        $this->solicitud->tipo_solicitud  = $data['tipo_solicitud'] ?? null;

        $estados = ['Pendiente', 'Programada', 'Finalizada', 'Cancelada'];
        $errors = [];
        if (empty($this->solicitud->descripcion)) $errors['descripcion'] = "La descripcion es obligatoria.";
        elseif (mb_strlen($this->solicitud->descripcion) > 1000) $errors['descripcion'] = "La descripcion no puede superar los 1000 caracteres.";
        if (empty($this->solicitud->direccion)) $errors['direccion'] = "La direccion es obligatoria.";
        elseif (mb_strlen($this->solicitud->direccion) > 150) $errors['direccion'] = "La direccion no puede superar los 150 caracteres.";
        if (!in_array($this->solicitud->estado, $estados, true)) $errors['estado'] = "El estado debe ser Pendiente, Programada, Finalizada o Cancelada.";
        if (empty($this->solicitud->id_tipo_residuo)) $errors['id_tipo_residuo'] = "El tipo de residuo es obligatorio.";
        if (empty($this->solicitud->email)) $errors['email'] = "El email es obligatorio.";
        elseif (!filter_var($this->solicitud->email, FILTER_VALIDATE_EMAIL)) $errors['email'] = "El email debe tener un formato valido.";
        elseif (mb_strlen($this->solicitud->email) > 100) $errors['email'] = "El email no puede superar los 100 caracteres.";
        if (empty($this->solicitud->telefono)) $errors['telefono'] = "El telefono es obligatorio.";
        elseif (mb_strlen($this->solicitud->telefono) > 20) $errors['telefono'] = "El telefono no puede superar los 20 caracteres.";
        if (empty($this->solicitud->tipo_solicitud)) $errors['tipo_solicitud'] = "El tipo de solicitud es obligatorio.";
        elseif (!in_array($this->solicitud->tipo_solicitud, ['Gran volumen', 'Reciclables'], true)) $errors['tipo_solicitud'] = "El tipo de solicitud no es válido.";

        if ($errors) {
            return ["success" => false, "data" => null, "message" => "Datos incompletos o invalidos.", "errors" => $errors, "statusCode" => 400];
        }

        $attemptedTrackingNumbers = [];
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->solicitud->tracking_number = $this->generateTrackingNumber($attemptedTrackingNumbers);
            $attemptedTrackingNumbers[$this->solicitud->tracking_number] = true;

            try {
                $created = $this->solicitud->create();
            } catch (PDOException $exception) {
                if ($this->isTrackingDuplicate($exception) && $attempt < 3) {
                    continue;
                }
                return ["success" => false, "data" => null, "message" => "Error al registrar la solicitud.", "errors" => [], "statusCode" => 500];
            } catch (PersistenceException $exception) {
                return ["success" => false, "data" => null, "message" => "Error al registrar la solicitud.", "errors" => [], "statusCode" => 500];
            }

            if ($created) {
                return [
                    "success" => true,
                    "data" => ["tracking_number" => $this->solicitud->tracking_number],
                    "message" => "Solicitud de retiro registrada con exito en Zemyna.",
                    "tracking_number" => $this->solicitud->tracking_number,
                    "errors" => [],
                    "statusCode" => 201
                ];
            }

            return ["success" => false, "data" => null, "message" => "Error al registrar la solicitud.", "errors" => [], "statusCode" => 500];
        }

        return ["success" => false, "data" => null, "message" => "Error al registrar la solicitud.", "errors" => [], "statusCode" => 500];
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
