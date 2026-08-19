<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../controllers/UsuarioRolController.php';

$database = new Database();
$db = $database->getConnection();
$controller = new UsuarioRolController($db);

$method = $_SERVER["REQUEST_METHOD"];

switch ($method) {

    case "GET":
        requireRole(['Superusuario']);

        $idUsuario = $_GET['id_usuario'] ?? null;
        $response = $controller->getByUsuario($idUsuario);

        http_response_code(
            $response['statusCode'] ?? ($response['success'] ? 200 : 400)
        );

        echo json_encode($response);
        break;

    case "POST":
        requireRole(['Superusuario']);

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

        $response = $controller->create($data ?? []);

        http_response_code(
            $response['statusCode'] ?? ($response['success'] ? 201 : 400)
        );

        echo json_encode($response);
        break;

    case "PUT":
        requireRole(['Superusuario']);

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

        $response = $controller->finalizar($data ?? []);

        http_response_code(
            $response['statusCode'] ?? ($response['success'] ? 200 : 400)
        );

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