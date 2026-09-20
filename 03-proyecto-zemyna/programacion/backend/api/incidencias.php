<?php
require_once __DIR__ . '/../config/bootstrap.php';

$database = new Database();
$db = $database->getConnection();
$controller = new IncidenciaController($db);

$method = $_SERVER["REQUEST_METHOD"];

switch ($method) {
    case "GET":
        $filters = [
            'id' => $_GET['id'] ?? null,
            'tracking_number' => $_GET['tracking_number'] ?? null,
            'page' => $_GET['page'] ?? 1,
            'limit' => $_GET['limit'] ?? 20,
            'estado' => $_GET['estado'] ?? null,
            'prioridad' => $_GET['prioridad'] ?? null,
        ];

        if (!in_array($_GET['view'] ?? null, ['map', 'report', 'location'], true) && array_key_exists('tracking_number', $_GET) && !array_key_exists('admin', $_GET)) {
            $response = $controller->getPublicByTracking($filters['tracking_number']);
        } elseif (($_GET['view'] ?? null) === 'location') {
            requirePermission('incidencia.consultar', ['OPERACIONES', 'INSPECCION', 'PUNTOS_Y_DESTINOS']);
            $response = $controller->getLocation($_GET['id'] ?? null);
        } elseif (($_GET['view'] ?? null) === 'report') {
            requirePermission('incidencia.consultar', ['OPERACIONES', 'INSPECCION', 'PUNTOS_Y_DESTINOS']);
            $response = $controller->getReport($_GET);
        } elseif (($_GET['view'] ?? null) === 'map') {
            if (array_key_exists('admin', $_GET)) {
                requirePermission('incidencia.consultar', ['OPERACIONES', 'INSPECCION', 'PUNTOS_Y_DESTINOS']);
                $_GET['activas'] = '1';
            }
            $response = $controller->getMap($_GET);
        } else {
            requirePermission('incidencia.consultar', ['OPERACIONES', 'INSPECCION', 'PUNTOS_Y_DESTINOS']);
            $response = ($_GET['opciones'] ?? null) === 'cuadrillas'
                ? $controller->getCuadrillas() : $controller->getAll($filters);
            $response['can_update'] = hasEffectivePermission('incidencia.modificar', ['OPERACIONES', 'PUNTOS_Y_DESTINOS']);
        }
        http_response_code($response['statusCode'] ?? ($response['success'] ? 200 : 400));
        echo json_encode($response);
        break;

    case "POST":
        $data = json_decode(file_get_contents("php://input"), true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "JSON inválido.",
                "errors" => [json_last_error_msg()]
            ]);
            break;
        }

        $response = $controller->create($data ?? []);
        http_response_code($response['statusCode'] ?? ($response['success'] ? 201 : 400));
        echo json_encode($response);
        break;

    case "PUT":
        requirePermission('incidencia.modificar', ['OPERACIONES', 'PUNTOS_Y_DESTINOS']);

        $data = json_decode(file_get_contents("php://input"), true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "JSON inválido.",
                "errors" => [json_last_error_msg()]
            ]);
            break;
        }

        $response = $controller->updateAdministrative($data ?? []);
        http_response_code($response['statusCode'] ?? ($response['success'] ? 200 : 400));
        echo json_encode($response);
        break;

    case "DELETE":
        requirePermission('incidencia.modificar', ['OPERACIONES', 'PUNTOS_Y_DESTINOS']);

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
