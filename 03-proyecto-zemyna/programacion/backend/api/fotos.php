<?php
require_once __DIR__ . '/../config/bootstrap.php';

$database   = new Database();
$db         = $database->getConnection();
$controller = new FotoController($db);

$method = $_SERVER["REQUEST_METHOD"];

switch ($method) {
    case "GET":
        requirePermission('incidencia.consultar', ['OPERACIONES', 'INSPECCION', 'PUNTOS_Y_DESTINOS']);
        $response = $controller->getAll();
        if ($response['success']) {
            foreach ($response['data'] as &$row) {
                $row['url'] = 'foto.php?id=' . (int)$row['id_foto'];
            }
            unset($row);
        }
        http_response_code($response['statusCode'] ?? 500);
        echo json_encode($response);
        break;

    case "POST":
        requirePermission('incidencia.adjuntar_evidencia', ['OPERACIONES', 'INSPECCION', 'PUNTOS_Y_DESTINOS']);
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
        requirePermission('incidencia.adjuntar_evidencia', ['OPERACIONES', 'INSPECCION', 'PUNTOS_Y_DESTINOS']);
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
        requirePermission('incidencia.modificar', ['OPERACIONES', 'PUNTOS_Y_DESTINOS']);
        $data = json_decode(file_get_contents("php://input"), true) ?? [];
        if (!isset($data['id_foto'])) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Falta id_foto en el cuerpo de la petición."]);
            break;
        }
        $response = $controller->delete($data['id_foto']);
        http_response_code($response['success'] ? 200 : 400);
        echo json_encode($response);
        break;

    default:
        http_response_code(405);
        echo json_encode(["success" => false, "message" => "Método no permitido."]);
        break;
}
