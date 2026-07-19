<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$segments = array_values(array_filter(explode('/', $path)));

if (empty($segments) || $segments[0] !== 'api') {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Ruta no encontrada.', 'errors' => ['Ruta inválida']]);
    exit;
}

$resource = $segments[1] ?? null;
$file = __DIR__ . '/api/' . $resource . '.php';

if (!file_exists($file)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Recurso no encontrado.', 'errors' => ['API no disponible']]);
    exit;
}

require_once $file;
