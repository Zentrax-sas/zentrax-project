<?php
require_once __DIR__ . '/../config/bootstrap.php';

$database   = new Database();
$db         = $database->getConnection();
$controller = new IncidenciaController($db);

$method = $_SERVER["REQUEST_METHOD"];

switch ($method) {
    case "GET":
        $filters = [
            'id' => isset($_GET['id']) ? $_GET['id'] : null,
            'page' => isset($_GET['page']) ? $_GET['page'] : 1,
            'limit' => isset($_GET['limit']) ? $_GET['limit'] : 20,
        ];
        $response = $controller->getAll($filters);
        http_response_code($response['statusCode'] ?? ($response['success'] ? 200 : 400));
        echo json_encode($response);
        break;

    case "POST":
        $data = json_decode(file_get_contents("php://input"), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "JSON inválido.", "errors" => [json_last_error_msg()]]);
            break;
        }
        $response = $controller->create($data ?? []);
        http_response_code($response['statusCode'] ?? ($response['success'] ? 201 : 400));
        echo json_encode($response);
        break;

    case "PUT":
        $data = json_decode(file_get_contents("php://input"), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "JSON inválido.", "errors" => [json_last_error_msg()]]);
            break;
        }
        $response = $controller->update($data ?? []);
        http_response_code($response['statusCode'] ?? ($response['success'] ? 200 : 400));
        echo json_encode($response);
        break;

    case "DELETE":
        $data = json_decode(file_get_contents("php://input"), true) ?? [];
        if (!isset($data['id_incidencia'])) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Falta id_incidencia en el cuerpo de la petición."]);
            break;
        }
        $response = $controller->delete($data['id_incidencia']);
        http_response_code($response['statusCode'] ?? ($response['success'] ? 200 : 400));
        echo json_encode($response);
        break;

    default:
        http_response_code(405);
        echo json_encode(["success" => false, "message" => "Método no permitido."]);
        break;
}
