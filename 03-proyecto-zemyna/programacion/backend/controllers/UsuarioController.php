<?php
require_once __DIR__ . '/../models/Usuario.php';

class UsuarioController {
    private $usuario;

    public function __construct($db) {
        $this->usuario = new Usuario($db);
    }

    public function getAll() {
        $stmt = $this->usuario->read();
        if ($stmt) {
            return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC), "message" => "Usuarios cargados correctamente."];
        }
        return [
            "success" => true,
            "data" => [
                ["id_usuario" => 1, "nombre" => "Facundo", "apellido" => "Pérez",  "email" => "facu@zemyna.com",   "telefono" => "091-001001", "fecha_registro" => "2025-01-10", "rol" => "Administrador", "id_centro" => 1],
                ["id_usuario" => 2, "nombre" => "Diego",   "apellido" => "Suárez", "email" => "diego@zemyna.com",  "telefono" => "091-002002", "fecha_registro" => "2025-02-15", "rol" => "Operario",      "id_centro" => 1],
                ["id_usuario" => 3, "nombre" => "Andrea",  "apellido" => "Méndez", "email" => "andrea@zemyna.com", "telefono" => "091-003003", "fecha_registro" => "2025-03-20", "rol" => "Operario",      "id_centro" => 2],
            ],
            "message" => "Usuarios cargados en modo demo."
        ];
    }

    public function create($data) {
        $this->usuario->nombre          = $data['nombre']    ?? null;
        $this->usuario->apellido        = $data['apellido']  ?? null;
        $this->usuario->email           = $data['email']     ?? null;
        $rawPassword = $data['contraseña'] ?? ($data['contrasena'] ?? null);
        $this->usuario->contrasena      = isset($rawPassword) ? password_hash($rawPassword, PASSWORD_BCRYPT) : null;
        $this->usuario->telefono        = $data['telefono']  ?? null;
        $this->usuario->fecha_registro  = $data['fecha_registro'] ?? date('Y-m-d');
        $this->usuario->rol             = $data['rol']       ?? null;
        $this->usuario->id_centro       = $data['id_centro'] ?? null;

        $roles  = ['Administrador', 'Operario'];
        $errors = [];
        if (empty($this->usuario->nombre))    $errors[] = "El nombre es obligatorio.";
        if (empty($this->usuario->apellido))  $errors[] = "El apellido es obligatorio.";
        if (empty($this->usuario->email))     $errors[] = "El email es obligatorio.";
        elseif (!filter_var($this->usuario->email, FILTER_VALIDATE_EMAIL)) $errors[] = "El email no tiene un formato válido.";
        if (empty($rawPassword))       $errors[] = "La contraseña es obligatoria.";
        if (empty($this->usuario->telefono))  $errors[] = "El teléfono es obligatorio.";
        if (!in_array($this->usuario->rol, $roles)) $errors[] = "El rol debe ser Administrador u Operario.";
        if (empty($this->usuario->id_centro)) $errors[] = "El id_centro es obligatorio.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo registrar el usuario.", "errors" => $errors];
        }

        if ($this->usuario->create()) {
            return ["success" => true, "data" => null, "message" => "Usuario de Zemyna registrado con éxito.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al registrar el usuario.", "errors" => []];
    }

    public function update($data) {
        $this->usuario->id_usuario  = $data['id_usuario']  ?? null;
        $this->usuario->nombre      = $data['nombre']      ?? null;
        $this->usuario->apellido    = $data['apellido']    ?? null;
        $this->usuario->email       = $data['email']       ?? null;
        $this->usuario->telefono    = $data['telefono']    ?? null;
        $this->usuario->rol         = $data['rol']         ?? null;
        $this->usuario->id_centro   = $data['id_centro']   ?? null;

        $errors = [];
        if (empty($this->usuario->id_usuario)) $errors[] = "El id_usuario es obligatorio para actualizar.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo actualizar el usuario.", "errors" => $errors];
        }

        if ($this->usuario->update()) {
            return ["success" => true, "data" => null, "message" => "Usuario actualizado con éxito.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al actualizar el usuario.", "errors" => []];
    }

    public function delete($id) {
        $this->usuario->id_usuario = $id;
        if ($this->usuario->delete()) {
            return ["success" => true, "data" => null, "message" => "Usuario eliminado con éxito.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "No se pudo eliminar el usuario.", "errors" => []];
    }
}
