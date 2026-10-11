<?php
header('Cache-Control: no-store');
// Bootstrap responde OPTIONS antes de los guards de cada API: aquí solo hay GET.
if (($_SERVER['REQUEST_METHOD']??'GET')==='OPTIONS') {
    header('Content-Type: application/json; charset=UTF-8');header('Allow: GET');
    http_response_code(405);
    echo json_encode(['success'=>false,'data'=>null,'message'=>'Método no permitido.','errors'=>[],'statusCode'=>405]);exit;
}
require_once __DIR__.'/../config/bootstrap.php';
require_once __DIR__.'/../controllers/ReporteOperativoController.php';
header('Cache-Control: no-store');
header_remove('Access-Control-Allow-Origin');
header_remove('Access-Control-Allow-Methods');
header_remove('Access-Control-Allow-Headers');
if (empty($_SESSION['usuario']['id_usuario'])) {
    $result = ['success'=>false,'data'=>null,'message'=>'No autenticado.','errors'=>[],'statusCode'=>401];
} else {
    $result = (new ReporteOperativoController((new Database())->getConnection()))->consultar($_GET,$_SERVER['REQUEST_METHOD']??'GET');
}
if ($result['statusCode']===405) header('Allow: GET');
http_response_code($result['statusCode']);
echo json_encode($result,JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
