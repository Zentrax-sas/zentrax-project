<?php
require_once __DIR__ . '/../models/Usuario.php';

class UsuarioController {
    private $usuario;

    public function __construct($db) {
        // El controlador "contrata" al modelo al nacer
        $this->usuario = new Usuario($db);
    }

    // Obtener todos los usuarios (para las tablas del Admin)
    public function getAll() {
        $stmt = $this->usuario->read();
        
        if ($stmt) {
            $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($usuarios);
        } else {
            // Si todavía no hay BD, devolvemos los datos mockeados de prueba
            $usuariosMock = [
                ["id_usuario" => 1, "nombre" => "Facundo", "email" => "facu@zemyna.com", "id_rol" => 1],
                ["id_usuario" => 2, "nombre" => "Diego Chofer", "email" => "diego@zemyna.com", "id_rol" => 3]
            ];
            echo json_encode($usuariosMock);
        }
    }

    // Crear un nuevo empleado (el formulario del Admin)
    public function create($data) {
        // El controlador recibe los datos limpios y se los setea al modelo
        $this->usuario->nombre = $data['nombre'] ?? null;
        $this->usuario->email = $data['email'] ?? null;
        // El controlador se encarga de la seguridad antes de mandar al modelo
        $this->usuario->password = isset($data['password']) ? password_hash($data['password'], PASSWORD_BCRYPT) : null;
        $this->usuario->id_rol = $data['id_rol'] ?? null;

        // Le pide al modelo que lo cree y decide qué mensaje responder
        if($this->usuario->create()) {
            echo json_encode(["success" => true, "message" => "Usuario de Zemyna creado correctamente."]);
        } else {
            echo json_encode(["success" => false, "message" => "Error al crear el usuario."]);
        }
    }

    // Eliminar un empleado
    public function delete($id) {
        $this->usuario->id_usuario = $id;

        if($this->usuario->delete()) {
            echo json_encode(["success" => true, "message" => "Usuario eliminado con éxito."]);
        } else {
            echo json_encode(["success" => false, "message" => "No se pudo eliminar el usuario."]);
        }
    }
}
?>