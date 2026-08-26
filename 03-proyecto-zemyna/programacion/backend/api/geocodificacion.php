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
$cache = $geocodificacion->findCache((int) $idContenedor);
if ($cache) {
    echo json_encode(['success' => true, 'source' => 'cache', 'data' => $cache]);
    exit;
}

$contenedor = $geocodificacion->findContenedor((int) $idContenedor);
if (!$contenedor) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Contenedor no encontrado.']);
    exit;
}

$fallback = [
    'direccion' => $contenedor['direccion'],
    'barrio' => null,
    'localidad' => null,
    'latitud' => (float) $contenedor['latitud'],
    'longitud' => (float) $contenedor['longitud']
];

$url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat='
    . rawurlencode($contenedor['latitud'])
    . '&lon=' . rawurlencode($contenedor['longitud'])
    . '&zoom=18&addressdetails=1';

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 4,
        'header' => "User-Agent: Zemyna/1.0 (geocodificacion@zemyna.local)\r\nAccept: application/json\r\n"
    ]
]);
$externalResponse = @file_get_contents($url, false, $context);
$externalData = $externalResponse !== false ? json_decode($externalResponse, true) : null;

if (is_array($externalData) && !empty($externalData['display_name'])) {
    $address = $externalData['address'] ?? [];
    $fallback['direccion'] = $externalData['display_name'];
    $fallback['barrio'] = $address['neighbourhood'] ?? $address['suburb'] ?? null;
    $fallback['localidad'] = $address['city'] ?? $address['town'] ?? $address['village'] ?? null;
    $geocodificacion->saveCache(
        (int) $idContenedor,
        $fallback['direccion'],
        $fallback['barrio'],
        $fallback['localidad']
    );
    echo json_encode(['success' => true, 'source' => 'external', 'data' => $fallback]);
    exit;
}

echo json_encode([
    'success' => true,
    'source' => 'fallback',
    'message' => 'No se pudo consultar el servicio de direcciones; se conservan las coordenadas.',
    'data' => $fallback
]);