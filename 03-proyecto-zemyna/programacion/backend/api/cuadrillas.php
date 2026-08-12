<?php
require_once __DIR__ . '/../config/bootstrap.php';

$database   = new Database();
$db         = $database->getConnection();
$controller = new CuadrillaController($db);

$method = $_SERVER["REQUEST_METHOD"];

switch ($method) {
    case "GET":
        requireAuth();
        $response = $controller->getAll();
        http_response_code(200);
        echo json_encode($response);
        break;

    case "POST":
        requireRole(['Administrador']);
        $data = json_decode(file_get_contents("php://input"), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "JSON inválido.", "errors" => [json_last_error_msg()]]);
            break;
        }
        $response = $controller->create($data ?? []);
        http_response_code($response['success'] ? 201 : 400);
        echo json_encode($response);
        break;

    case "PUT":
        requireRole(['Administrador']);
        $data = json_decode(file_get_contents("php://input"), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "JSON inválido.", "errors" => [json_last_error_msg()]]);
            break;
        }
        $response = $controller->update($data ?? []);
        http_response_code($response['success'] ? 200 : 400);
        echo json_encode($response);
        break;

    case "DELETE":
        requireRole(['Administrador']);
        $data = json_decode(file_get_contents("php://input"), true) ?? [];
        if (!isset($data['id_cuadrilla'])) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Falta id_cuadrilla en el cuerpo de la petición."]);
            break;
        }
        $response = $controller->delete($data['id_cuadrilla']);
        http_response_code($response['success'] ? 200 : 400);
        echo json_encode($response);
        break;

    default:
        http_response_code(405);
        echo json_encode(["success" => false, "message" => "Método no permitido."]);
        break;
}
