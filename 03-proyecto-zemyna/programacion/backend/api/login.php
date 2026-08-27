<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../models/Usuario.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido.'
    ]);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'JSON inválido.'
    ]);
    exit;
}

$email = trim($body['email'] ?? '');
$password = trim($body['password'] ?? '');

if ($email === '' || $password === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Email y contraseña son obligatorios.'
    ]);
    exit;
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'No se pudo conectar a la base de datos.'
    ]);
    exit;
}

$usuarioModel = new Usuario($db);
$usuario = $usuarioModel->findByEmail($email);

if (!$usuario || !password_verify($password, $usuario['contrasena'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Credenciales inválidas.',
        'errors' => ['Email o contraseña incorrectos.']
    ]);
    exit;
}

if (($usuario['activo'] ?? 'Inactivo') !== 'Activo') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'La cuenta se encuentra inactiva.'
    ]);
    exit;
}

$roles = $usuarioModel->getRolesVigentes($usuario['id_usuario']);

if (empty($roles)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'El usuario no posee roles vigentes.'
    ]);
    exit;
}

$nombresRoles = array_map(function ($rol) {
    return normalizeRoleName($rol['nombre'] ?? $rol);
}, $roles);

$permisos = array_map('trim', $usuarioModel->getPermisosVigentes($usuario['id_usuario']));
$autorizaciones = array_map(function ($autorizacion) {
    return [
        'rol' => normalizeRoleName($autorizacion['rol'] ?? ''),
        'sector' => strtoupper(trim((string) ($autorizacion['sector'] ?? ''))),
        'permiso' => trim((string) ($autorizacion['permiso'] ?? '')),
    ];
}, $usuarioModel->getAutorizacionesVigentes($usuario['id_usuario']));

session_regenerate_id(true);

$_SESSION['usuario'] = [
    'id_usuario' => (int)$usuario['id_usuario'],
    'nombre' => $usuario['nombre'],
    'apellido' => $usuario['apellido'],
    'email' => $usuario['email'],
    'id_centro' => (int)$usuario['id_centro'],
    'activo' => $usuario['activo'],
    'roles' => $nombresRoles,
    'asignaciones' => $roles,
    'permisos' => $permisos,
    'autorizaciones' => $autorizaciones
];

echo json_encode([
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
        'autorizaciones' => $autorizaciones
    ]
]);