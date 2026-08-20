<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/captcha.php';

$database = new Database();
$db = $database->getConnection();
$controller = new IncidenciaController($db);

$method = $_SERVER["REQUEST_METHOD"];

switch ($method) {
    case "GET":
        requireAuth();

        $filters = [
            'id' => $_GET['id'] ?? null,
            'page' => $_GET['page'] ?? 1,
            'limit' => $_GET['limit'] ?? 20,
        ];

        $response = $controller->getAll($filters);
        http_response_code($response['statusCode'] ?? ($response['success'] ? 200 : 400));
        echo json_encode($response);
        break;

    case "POST":
        $data = json_decode(file_get_contents("php://input"), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "JSON inválido.",
                "errors" => [json_last_error_msg()]
            ]);
            break;
        }

        if (!validarCaptcha($data['captcha_respuesta'] ?? null)) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Captcha incorrecto, intentá de nuevo.",
                "errors" => [
                    "captcha_respuesta" => "Captcha incorrecto o expirado."
                ]
            ]);
            break;
        }

        $response = $controller->create($data ?? []);
        http_response_code($response['statusCode'] ?? ($response['success'] ? 201 : 400));
        echo json_encode($response);
        break;

    case "PUT":
        requireRole(['Superusuario', 'Administrador']);

        $data = json_decode(file_get_contents("php://input"), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "JSON inválido.",
                "errors" => [json_last_error_msg()]
            ]);
            break;
        }

        $response = $controller->update($data ?? []);
        http_response_code($response['statusCode'] ?? ($response['success'] ? 200 : 400));
        echo json_encode($response);
        break;

    case "DELETE":
        requireRole(['Superusuario', 'Administrador']);

        $data = json_decode(file_get_contents("php://input"), true) ?? [];

        if (!isset($data['id_incidencia'])) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Falta id_incidencia en el cuerpo de la petición."
            ]);
            break;
        }

        $response = $controller->delete($data['id_incidencia']);
        http_response_code($response['statusCode'] ?? ($response['success'] ? 200 : 400));
        echo json_encode($response);
        break;

    default:
        http_response_code(405);
        echo json_encode([
            "success" => false,
            "message" => "Método no permitido."
        ]);
        break;
}