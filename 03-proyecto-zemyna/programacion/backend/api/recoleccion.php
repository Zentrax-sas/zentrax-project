<?php
require_once __DIR__ . '/../config/bootstrap.php';
requireAuth();
$db = (new Database())->getConnection();
// Revalidar roles y permisos: una sesión anterior no conserva habilitaciones revocadas.
if ($db && !empty($_SESSION['usuario']['id_usuario'])) {
    try {
        $usuario = new Usuario($db);
        $_SESSION['usuario']['roles'] = array_column($usuario->getRolesVigentes((int) $_SESSION['usuario']['id_usuario']), 'nombre');
        $_SESSION['usuario']['autorizaciones'] = $usuario->getAutorizacionesVigentes((int) $_SESSION['usuario']['id_usuario']);
    } catch (PDOException $e) {
        http_response_code(503); echo json_encode(['success' => false, 'message' => 'No se pudo validar la autorización.']); exit;
    }
}
$controller = new RecoleccionController($db);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $response = !is_array($body) || json_last_error() !== JSON_ERROR_NONE
        ? ['success' => false, 'statusCode' => 400, 'message' => 'JSON inválido.'] : $controller->modificar($body);
} else $response = $controller->consultar($_GET, $method);
http_response_code($response['statusCode']);
header('Cache-Control: no-store');
if ($response['statusCode'] === 405) header('Allow: GET, POST');
echo json_encode($response, JSON_UNESCAPED_UNICODE);
