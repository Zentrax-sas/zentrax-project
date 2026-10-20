<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../models/DireccionRetiro.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405); echo json_encode(['success' => false, 'message' => 'Método no permitido.']); exit;
}
$vista = $_GET['view'] ?? 'streets';
$q = $_GET['q'] ?? ''; $detalle = $_GET['detalle'] ?? '';
if (!is_string($q) || mb_strlen(trim($q)) < 2 || mb_strlen($q) > 150 || !is_string($detalle) || mb_strlen($detalle) > 150 ||
    !in_array($vista, ['streets', 'resolve'], true) || ($vista === 'resolve' && trim($detalle) === '')) {
    http_response_code(400); echo json_encode(['success' => false, 'message' => 'Ingresá una calle y el número o referencia para ubicar el retiro.']); exit;
}
try {
    $modelo = new DireccionRetiro();
    $data = $vista === 'streets' ? $modelo->sugerir(trim($q)) : $modelo->resolver(trim($q), trim($detalle));
    echo json_encode(['success' => true, 'data' => $data]);
} catch (Throwable $error) {
    http_response_code(503); echo json_encode(['success' => false, 'message' => 'No se pudo consultar la dirección. Intentá nuevamente.']);
}
