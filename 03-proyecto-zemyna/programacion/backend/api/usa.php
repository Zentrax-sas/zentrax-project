<?php
require_once __DIR__ . '/../config/bootstrap.php';

$database   = new Database();
$db         = $database->getConnection();
$controller = new UsaController($db);

$method = $_SERVER["REQUEST_METHOD"];

switch ($method) {
    case "GET":
        // GET /api/usa.php?id_cuadrilla=1  o  ?id_vehiculo=2
        $id_cuadrilla = isset($_GET['id_cuadrilla']) ? (int)$_GET['id_cuadrilla'] : null;
        $id_vehiculo  = isset($_GET['id_vehiculo'])  ? (int)$_GET['id_vehiculo']  : null;
        $response = $controller->getAll($id_cuadrilla, $id_vehiculo);
        http_response_code(200);
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
        http_response_code($response['success'] ? 201 : 400);
        echo json_encode($response);
        break;

    case "DELETE":
        $data = json_decode(file_get_contents("php://input"), true) ?? [];
        if (!isset($data['id_cuadrilla']) || !isset($data['id_vehiculo'])) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Faltan id_cuadrilla e id_vehiculo en el cuerpo de la petición."]);
            break;
        }
        $response = $controller->delete($data);
        http_response_code($response['success'] ? 200 : 400);
        echo json_encode($response);
        break;

    default:
        http_response_code(405);
        echo json_encode(["success" => false, "message" => "Método no permitido."]);
        break;
}
