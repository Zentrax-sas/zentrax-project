<?php

require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../helpers/auth.php';

class LoginController
{
    private Usuario $usuarioModel;

    public function __construct(Usuario $usuarioModel)
    {
        $this->usuarioModel = $usuarioModel;
    }

    public function authenticate(string $email, string $password): array
    {
        $email = trim($email);
        $password = trim($password);

        if ($email === '' || $password === '') {
            return ['success' => false, 'message' => 'Email y contraseña son obligatorios.', 'statusCode' => 400];
        }

        $usuario = $this->usuarioModel->findByEmail($email);

        if (!$usuario || !password_verify($password, $usuario['contrasena'])) {
            return [
                'success' => false,
                'message' => 'Credenciales inválidas.',
                'errors' => ['Email o contraseña incorrectos.'],
                'statusCode' => 401,
            ];
        }

        if (($usuario['activo'] ?? 'Inactivo') !== 'Activo') {
            return ['success' => false, 'message' => 'La cuenta se encuentra inactiva.', 'statusCode' => 403];
        }

        $roles = $this->usuarioModel->getRolesVigentes($usuario['id_usuario']);

        if (empty($roles)) {
            return ['success' => false, 'message' => 'El usuario no posee roles vigentes.', 'statusCode' => 403];
        }

        $nombresRoles = array_map(function ($rol) {
            return normalizeRoleName($rol['nombre'] ?? $rol);
        }, $roles);

        $permisos = array_map('trim', $this->usuarioModel->getPermisosVigentes($usuario['id_usuario']));
        $autorizaciones = array_map(function ($autorizacion) {
            return [
                'rol' => normalizeRoleName($autorizacion['rol'] ?? ''),
                'sector' => strtoupper(trim((string) ($autorizacion['sector'] ?? ''))),
                'permiso' => trim((string) ($autorizacion['permiso'] ?? '')),
            ];
        }, $this->usuarioModel->getAutorizacionesVigentes($usuario['id_usuario']));

        return [
            'success' => true,
            'message' => 'Inicio de sesión correcto.',
            'data' => [
                'id_usuario' => (int)$usuario['id_usuario'],
                'nombre' => $usuario['nombre'],
                'apellido' => $usuario['apellido'],
                'email' => $usuario['email'],
                'id_centro' => (int)$usuario['id_centro'],
                'activo' => $usuario['activo'],
                'roles' => $roles,
                'permisos' => $permisos,
                'autorizaciones' => $autorizaciones,
            ],
            'sessionUser' => [
                'id_usuario' => (int)$usuario['id_usuario'],
                'nombre' => $usuario['nombre'],
                'apellido' => $usuario['apellido'],
                'email' => $usuario['email'],
                'id_centro' => (int)$usuario['id_centro'],
                'activo' => $usuario['activo'],
                'roles' => $nombresRoles,
                'asignaciones' => $roles,
                'permisos' => $permisos,
                'autorizaciones' => $autorizaciones,
            ],
            'statusCode' => 200,
        ];
    }
}
