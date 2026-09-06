<?php
require_once __DIR__ . '/../config/bootstrap.php';

$database = new Database();
$db = $database->getConnection();
$controller = new MaquinariaController($db);
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        requirePermission('maquinaria.consultar', ['MANTENIMIENTO', 'OPERACIONES']);
        $response = $controller->getAll();
        break;
    case 'POST':
        requirePermission('maquinaria.crear', ['MANTENIMIENTO', 'OPERACIONES']);
        $data = json_decode(file_get_contents('php://input'), true);
        $response = json_last_error() === JSON_ERROR_NONE
            ? $controller->create($data ?? [])
            : ['success' => false, 'data' => null, 'message' => 'JSON inválido.', 'errors' => ['El cuerpo debe contener JSON válido.'], 'statusCode' => 400];
        break;
    case 'PUT':
        requirePermission('maquinaria.modificar', ['MANTENIMIENTO', 'OPERACIONES']);
        $data = json_decode(file_get_contents('php://input'), true);
        $response = json_last_error() === JSON_ERROR_NONE
            ? $controller->update($data ?? [])
            : ['success' => false, 'data' => null, 'message' => 'JSON inválido.', 'errors' => ['El cuerpo debe contener JSON válido.'], 'statusCode' => 400];
        break;
    case 'DELETE':
        requirePermission('maquinaria.baja', ['MANTENIMIENTO', 'OPERACIONES']);
        $data = json_decode(file_get_contents('php://input'), true);
        $response = json_last_error() === JSON_ERROR_NONE
            ? $controller->delete($data['id_maquinaria'] ?? null)
            : ['success' => false, 'data' => null, 'message' => 'JSON inválido.', 'errors' => ['El cuerpo debe contener JSON válido.'], 'statusCode' => 400];
        break;
    default:
        $response = ['success' => false, 'data' => null, 'message' => 'Método no permitido.', 'errors' => [], 'statusCode' => 405];
        break;
}

http_response_code($response['statusCode']);
echo json_encode($response);
