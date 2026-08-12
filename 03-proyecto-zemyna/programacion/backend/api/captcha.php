<?php
require_once __DIR__ . '/../config/bootstrap.php';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod !== 'GET') {
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
