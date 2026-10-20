<?php
require_once __DIR__ . '/../config/bootstrap.php';

// Público y de solo lectura: contrato documentado en backend/NOVEDADES.md.
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET, OPTIONS');
    http_response_code(405);
    echo json_encode(['success' => false, 'data' => [], 'message' => 'Método HTTP no permitido.', 'statusCode' => 405]);
    exit;
}

$database = new Database();
$controller = new NovedadController($database->getConnection());
$response = $controller->listar();
http_response_code($response['statusCode']);
echo json_encode($response);
