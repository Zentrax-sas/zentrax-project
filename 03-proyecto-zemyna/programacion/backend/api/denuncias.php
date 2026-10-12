<?php
// API heredada retirada: no cargar bootstrap, sesiones ni acceso a datos.
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// Conservar el preflight 204 del bootstrap compartido, sin sus dependencias.
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$retired = in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true);
$status = $retired ? 410 : 405;
if (!$retired) {
    header('Allow: GET, POST, PUT, DELETE, OPTIONS');
}
http_response_code($status);
echo json_encode([
    'success' => false,
    'data' => null,
    'message' => $retired ? 'Esta operación ya no está disponible.' : 'Método no permitido.',
    'errors' => [],
    'statusCode' => $status,
], JSON_UNESCAPED_UNICODE);
