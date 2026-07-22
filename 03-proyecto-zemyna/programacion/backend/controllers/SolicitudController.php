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
            $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return ["success" => true, "data" => $solicitudes, "message" => "Solicitudes cargadas correctamente."];
        }

        $solicitudesMock = [
            [
                "id" => 1,
                "direccion" => "Calle Principal 123",
                "email" => "usuario1@example.com",
                "telefono" => "+598 99 123 456",
                "descripcion" => "Retiro de materiales reciclables",
                "tipo_solicitud" => "Materiales Reciclables",
                "tracking_number" => "REF-2026-ABC12"
            ],
            [
                "id" => 2,
                "direccion" => "Avenida Central 456",
                "email" => "usuario2@example.com",
                "telefono" => "+598 98 789 012",
                "descripcion" => "Retiro de gran volumen de residuos",
                "tipo_solicitud" => "Gran Volumen",
                "tracking_number" => "REF-2026-XYZ78"
            ]
        ];
        return ["success" => true, "data" => $solicitudesMock, "message" => "Solicitudes cargadas correctamente."];
    }

    public function create($data) {
        // Validar campos requeridos
        $errors = [];

        $this->solicitud->direccion = $data['direccion'] ?? null;
        $this->solicitud->email = $data['email'] ?? null;
        $this->solicitud->telefono = $data['telefono'] ?? null;
        $this->solicitud->tipo_solicitud = $data['tipo_solicitud'] ?? null;
        $this->solicitud->descripcion = $data['descripcion'] ?? null;

        // Validar cada campo
        if (empty($this->solicitud->direccion)) {
            $errors['direccion'] = "La dirección es obligatoria.";
        }

        if (empty($this->solicitud->email)) {
            $errors['email'] = "El email es obligatorio.";
        } elseif (!filter_var($this->solicitud->email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "El email debe tener un formato válido.";
        }

        if (empty($this->solicitud->telefono)) {
            $errors['telefono'] = "El teléfono es obligatorio.";
        }

        if (empty($this->solicitud->tipo_solicitud)) {
            $errors['tipo_solicitud'] = "El tipo de solicitud es obligatorio.";
        }

        if (empty($this->solicitud->descripcion)) {
            $errors['descripcion'] = "La descripción es obligatoria.";
        }

        // Si hay errores, devolver sin generar tracking_number
        if (!empty($errors)) {
            return [
                "success" => false,
                "data" => null,
                "message" => "Datos incompletos o inválidos.",
                "errors" => $errors
            ];
        }

        // Validar con el modelo
        if (!$this->solicitud->create()) {
            return [
                "success" => false,
                "data" => null,
                "message" => "Error en la validación de datos.",
                "errors" => ["general" => "No se pudo validar la solicitud."]
            ];
        }

        // Generar tracking_number único: REF-{año}-{5 caracteres alfanuméricos mayúsculas}
        $year = date('Y');
        $randomCode = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $this->solicitud->tracking_number = "REF-{$year}-{$randomCode}";

        // Preparar respuesta
        $responseData = [
            "id" => null,
            "direccion" => $this->solicitud->direccion,
            "email" => $this->solicitud->email,
            "telefono" => $this->solicitud->telefono,
            "tipo_solicitud" => $this->solicitud->tipo_solicitud,
            "descripcion" => $this->solicitud->descripcion,
            "tracking_number" => $this->solicitud->tracking_number
        ];

        return [
            "success" => true,
            "data" => $responseData,
            "message" => "Solicitud de retiro especial registrada correctamente.",
            "tracking_number" => $this->solicitud->tracking_number
        ];
    }

    public function update($data) {
        $this->solicitud->id = $data['id'] ?? null;
        $this->solicitud->direccion = $data['direccion'] ?? null;
        $this->solicitud->email = $data['email'] ?? null;
        $this->solicitud->telefono = $data['telefono'] ?? null;
        $this->solicitud->tipo_solicitud = $data['tipo_solicitud'] ?? null;
        $this->solicitud->descripcion = $data['descripcion'] ?? null;

        if ($this->solicitud->update()) {
            return ["success" => true, "data" => null, "message" => "Solicitud actualizada correctamente."];
        }
        return ["success" => false, "data" => null, "message" => "Error al actualizar la solicitud.", "errors" => ["Datos incompletos"]];
    }

    public function delete($data) {
        $this->solicitud->id = $data['id'] ?? null;

        if ($this->solicitud->delete()) {
            return ["success" => true, "data" => null, "message" => "Solicitud eliminada correctamente."];
        }
        return ["success" => false, "data" => null, "message" => "Error al eliminar la solicitud."];
    }
}
