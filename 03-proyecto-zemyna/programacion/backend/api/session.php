<?php
require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'data' => null, 'message' => 'Método no permitido.', 'errors' => [], 'statusCode' => 405]);
    exit;
}

requireAuth();
$usuario = $_SESSION['usuario'];
echo json_encode([
    'success' => true,
    'data' => [
        'id_usuario' => (int)$usuario['id_usuario'],
        'nombre' => (string)($usuario['nombre'] ?? ''),
        'apellido' => (string)($usuario['apellido'] ?? ''),
        'roles' => array_values($usuario['roles'] ?? []),
    ],
    'message' => 'Sesión activa.',
    'errors' => [],
    'statusCode' => 200,
]);
