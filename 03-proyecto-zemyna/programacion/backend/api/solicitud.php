<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/captcha.php';
require_once __DIR__ . '/../controllers/SolicitudController.php';

if (!class_exists('SolicitudController')) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "No se pudo cargar el controlador de solicitudes."]);
    exit;
}

$method = $_SERVER["REQUEST_METHOD"];

// La creación es pública; no hay consumidores vigentes para operaciones administrativas.
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'data' => null,
        'message' => 'Método HTTP no permitido.',
        'errors' => [],
        'statusCode' => 405
    ]);
    exit;
}

$database = new Database();
$db = $database->getConnection();
$controller = new SolicitudController($db);

$input = file_get_contents("php://input");

do {
    // Verificar si el input está vacío
    if (empty($input)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "El body de la solicitud está vacío."]);
        break;
    }

    $data = json_decode($input, true);

    // Verificar si el JSON es válido
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "JSON inválido."]);
        break;
    }

    if (!is_array($data)) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'JSON inválido.']); break; }

    if (!validarCaptcha($data['captcha_respuesta'] ?? null)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Captcha incorrecto, intentá de nuevo.',
            'errors' => ['captcha_respuesta' => 'Captcha incorrecto o expirado.']
        ]);
        break;
    }

    $response = $controller->create($data ?? []);
    http_response_code($response['statusCode'] ?? ($response['success'] ? 201 : 400));
    echo json_encode($response);
} while (false);
