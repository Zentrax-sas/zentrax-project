<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type");

// require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../controllers/UsuarioController.php';

$db = null;
$controller = new UsuarioController($db);

$method = $_SERVER["REQUEST_METHOD"];

switch ($method) {
    case "GET":
        $controller->getAll();
        break;

    case "POST":
        $data = json_decode(file_get_contents("php://input"), true) ?? [];
        $controller->create($data);
        break;

    case "PUT":
        $data = json_decode(file_get_contents("php://input"), true) ?? [];
        $controller->update($data);
        break;

    case "DELETE":
        $data = json_decode(file_get_contents("php://input"), true) ?? [];
        if (!isset($data['id_usuario'])) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Falta id_usuario en el cuerpo de la petición."]);
            break;
        }
        $controller->delete($data['id_usuario']);
        break;
}