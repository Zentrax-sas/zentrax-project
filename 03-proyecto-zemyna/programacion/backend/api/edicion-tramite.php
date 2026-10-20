<?php
require_once __DIR__ . '/../config/bootstrap.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET','PUT'], true)) {
    http_response_code(405); echo json_encode(['success'=>false,'message'=>'Método no permitido.']); exit;
}
$valorTracking = $_GET['tracking_number'] ?? '';
if (!is_string($valorTracking)) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Código inválido.']); exit; }
$tracking = strtoupper(trim($valorTracking));
$data = $method === 'PUT' ? json_decode(file_get_contents('php://input'), true) : [];
if (!is_array($data) || ($method === 'PUT' && (json_last_error() !== JSON_ERROR_NONE || array_is_list($data)))) {
    http_response_code(400); echo json_encode(['success'=>false,'message'=>'Datos inválidos.']); exit;
}
$token = preg_replace('/^Bearer /', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
$permiso = str_starts_with($tracking,'INC-')
    ? hasEffectivePermission('incidencia.modificar', ['OPERACIONES','PUNTOS_Y_DESTINOS'])
    : hasEffectivePermission('solicitud.modificar', ['OPERACIONES','PUNTOS_Y_DESTINOS']);
$db = (new Database())->getConnection();
if (!$db) { http_response_code(503); echo json_encode(['success'=>false,'message'=>'No pudimos conectarnos al sistema.']); exit; }
$response = (new EdicionTramiteController(new EdicionTramite($db)))->ejecutar($method,$tracking,$data,$token,$permiso);
http_response_code($response['statusCode']);
echo json_encode($response);
