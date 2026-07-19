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
            $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return ["success" => true, "data" => $usuarios, "message" => "Usuarios cargados correctamente."];
        }

        $usuariosMock = [
            ["id_usuario" => 1, "nombre" => "Facundo", "email" => "facu@zemyna.com", "id_rol" => 1],
            ["id_usuario" => 2, "nombre" => "Diego Chofer", "email" => "diego@zemyna.com", "id_rol" => 3]
        ];
        return ["success" => true, "data" => $usuariosMock, "message" => "Usuarios cargados correctamente."];
    }

    public function create($data) {
        $this->usuario->nombre = $data['nombre'] ?? null;
        $this->usuario->email = $data['email'] ?? null;
        $this->usuario->password = isset($data['password']) ? password_hash($data['password'], PASSWORD_BCRYPT) : null;
        $this->usuario->id_rol = $data['id_rol'] ?? null;

        if ($this->usuario->create()) {
            return ["success" => true, "data" => null, "message" => "Usuario de Zemyna creado correctamente."];
        }
        return ["success" => false, "data" => null, "message" => "Error al crear el usuario.", "errors" => ["Datos incompletos"]];
    }

    public function update($data) {
        $this->usuario->id_usuario = $data['id_usuario'] ?? null;
        $this->usuario->nombre = $data['nombre'] ?? null;
        $this->usuario->email = $data['email'] ?? null;
        $this->usuario->password = isset($data['password']) ? password_hash($data['password'], PASSWORD_BCRYPT) : null;
        $this->usuario->id_rol = $data['id_rol'] ?? null;

        if ($this->usuario->update()) {
            return ["success" => true, "data" => null, "message" => "Usuario actualizado correctamente."];
        }
        return ["success" => false, "data" => null, "message" => "Error al actualizar el usuario.", "errors" => ["No se pudo actualizar"]];
    }

    public function delete($id) {
        $this->usuario->id_usuario = $id;

        if ($this->usuario->delete()) {
            return ["success" => true, "data" => null, "message" => "Usuario eliminado con éxito."];
        }
        return ["success" => false, "data" => null, "message" => "No se pudo eliminar el usuario.", "errors" => ["No se pudo borrar"]];
    }
}
?>