<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type");

// Cargamos la configuración simulada o real y el controlador correspondiente
// require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../controllers/ContenedorController.php';

$db = null; // Cambiar por (new Database())->getConnection() cuando configuren MySQL
$controller = new ContenedorController($db);

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
        // Espera recibir {"id_contenedor": 101} en el cuerpo de la petición
        if (!isset($data['id_contenedor'])) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Falta id_contenedor en el cuerpo de la petición."]);
            break;
        }
        $controller->delete($data['id_contenedor']);
        break;
}
?>