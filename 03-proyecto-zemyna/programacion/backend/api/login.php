<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../models/Usuario.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'JSON inválido.']);
    exit;
}

$email = trim($body['email'] ?? '');
$password = trim($body['password'] ?? '');

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No se pudo conectar a la base de datos.', 'errors' => ['Database unavailable']]);
    exit;
}

$usuarioModel = new Usuario($db);
$usuario = $usuarioModel->findByEmail($email);

if ($usuario && password_verify($password, $usuario['contraseña'])) {
    $_SESSION['usuario'] = [
        'id_usuario' => (int) $usuario['id_usuario'],
        'nombre' => $usuario['nombre'],
        'apellido' => $usuario['apellido'],
        'email' => $usuario['email'],
        'rol' => $usuario['rol'],
        'id_centro' => (int) $usuario['id_centro']
    ];

    echo json_encode([
        'success' => true,
        'message' => 'Inicio de sesión correcto.',
        'data' => [
            'id_usuario' => (int) $usuario['id_usuario'],
            'nombre' => $usuario['nombre'],
            'apellido' => $usuario['apellido'],
            'email' => $usuario['email'],
            'rol' => $usuario['rol'],
            'id_centro' => (int) $usuario['id_centro']
        ]
    ]);
    exit;
}

http_response_code(401);
echo json_encode([
    'success' => false,
    'message' => 'Credenciales inválidas.',
    'errors' => ['Email o contraseña incorrectos.']
]);
