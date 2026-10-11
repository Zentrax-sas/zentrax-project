<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
// Independiente del bootstrap CORS compartido: solo consulta same-origin GET.
session_start();
// session_start puede reemplazar Cache-Control con el cache limiter de PHP.
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../controllers/RutaApoyoController.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$crossSite = ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site';
if ($method !== 'GET') {
    header('Allow: GET');
    $result = ['success' => false, 'data' => null, 'message' => 'Método no permitido.', 'errors' => [], 'statusCode' => 405];
} elseif ($crossSite) {
    $result = ['success' => false, 'data' => null, 'message' => 'Consulta no permitida.', 'errors' => [], 'statusCode' => 403];
} else {
    // Liberar lock de sesión permite otra petición concurrente; identidad se captura de sesión.
    session_write_close();
    $db = empty($_SESSION['usuario']['id_usuario']) ? null : (new Database())->getConnection();
    $result = (new RutaApoyoController($db))->get($_GET, $method);
}
http_response_code($result['statusCode']);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
