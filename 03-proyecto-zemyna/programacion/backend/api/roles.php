<?php
require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'data' => null, 'message' => 'Método no permitido.', 'errors' => [], 'statusCode' => 405]);
    exit;
}

requirePermission('usuario.asignar_rol', ['TI']);
$controller = new UsuarioController((new Database())->getConnection());
$response = $controller->getRoleOptions();
http_response_code($response['statusCode']);
echo json_encode($response);
