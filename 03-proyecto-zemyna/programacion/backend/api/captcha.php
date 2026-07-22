<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido.',
        'errors' => ['Metodo' => 'Solo GET está permitido.']
    ]);
    exit;
}

require_once __DIR__ . '/../helpers/captcha.php';

$pregunta = generarCaptcha();

echo json_encode([
    'success' => true,
    'data' => ['pregunta' => $pregunta]
]);
