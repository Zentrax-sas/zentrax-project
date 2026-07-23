<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if (($_SERVER["REQUEST_METHOD"] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

session_start();
require_once __DIR__ . '/../helpers/captcha.php';
require_once __DIR__ . '/../controllers/SolicitudController.php';

if (!class_exists('SolicitudController')) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "No se pudo cargar el controlador de solicitudes."]);
    exit;
}

$db = null;
$controller = new SolicitudController($db);

$method = $_SERVER["REQUEST_METHOD"];

switch ($method) {
    case "GET":
        $response = $controller->getAll();
        http_response_code(200);
        echo json_encode($response);
        break;

    case "POST":
        $input = file_get_contents("php://input");

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
            echo json_encode(["success" => false, "message" => "JSON inválido: " . json_last_error_msg()]);
            break;
        }

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
        http_response_code($response['success'] ? 201 : 400);
        echo json_encode($response);
        break;

    case "PUT":
        $input = file_get_contents("php://input");

        if (empty($input)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "El body de la solicitud está vacío."]);
            break;
        }

        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "JSON inválido: " . json_last_error_msg()]);
            break;
        }

        $response = $controller->update($data ?? []);
        http_response_code($response['success'] ? 200 : 400);
        echo json_encode($response);
        break;

    case "DELETE":
        $input = file_get_contents("php://input");

        if (empty($input)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "El body de la solicitud está vacío."]);
            break;
        }

        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "JSON inválido: " . json_last_error_msg()]);
            break;
        }

        $response = $controller->delete($data ?? []);
        http_response_code($response['success'] ? 200 : 400);
        echo json_encode($response);
        break;

    default:
        http_response_code(405);
        echo json_encode(["success" => false, "message" => "Método HTTP no permitido."]);
        break;
}
