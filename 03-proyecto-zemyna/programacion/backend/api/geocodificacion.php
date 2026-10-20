<?php
require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

$idContenedor = $_GET['id_contenedor'] ?? null;
if ($idContenedor === null || !ctype_digit((string) $idContenedor) || (int) $idContenedor < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'id_contenedor debe ser un entero positivo.']);
    exit;
}

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No se pudo conectar a la base de datos.']);
    exit;
}

$geocodificacion = new Geocodificacion($db);
$contenedor = $geocodificacion->findContenedor((int) $idContenedor);
if (!$contenedor) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Contenedor no encontrado.']);
    exit;
}

try {
    $resultado = $geocodificacion->resolver($contenedor);
} catch (PDOException $exception) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'No se pudo consultar la geocodificacion.']);
    exit;
}
$data = $resultado['data'];
$data['latitud'] = (float) $contenedor['latitud'];
$data['longitud'] = (float) $contenedor['longitud'];
if ($data['direccion'] !== null) {
    echo json_encode(['success' => true, 'source' => $resultado['source'], 'data' => $data]);
    exit;
}

$data['direccion'] = $contenedor['direccion'];
echo json_encode([
    'success' => true,
    'source' => 'fallback',
    'message' => 'No se obtuvo una via valida; se conserva la direccion existente.',
    'data' => $data,
]);
