<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE");
header("Access-Control-Allow-Headers: Content-Type");

require_once __DIR__ . '/../controllers/RecorreController.php';
require_once __DIR__ . '/../config/database.php';

$database   = new Database();
$db         = $database->getConnection();
$controller = new RecorreController($db);

$method = $_SERVER["REQUEST_METHOD"];

switch ($method) {
    case "GET":
        // GET /api/recorre.php?id_vehiculo=1  o  ?id_ruta=2
        $id_vehiculo = isset($_GET['id_vehiculo']) ? (int)$_GET['id_vehiculo'] : null;
        $id_ruta     = isset($_GET['id_ruta'])     ? (int)$_GET['id_ruta']     : null;
        $response = $controller->getAll($id_vehiculo, $id_ruta);
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
        if (!isset($data['id_vehiculo']) || !isset($data['id_ruta'])) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Faltan id_vehiculo e id_ruta en el cuerpo de la petición."]);
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
