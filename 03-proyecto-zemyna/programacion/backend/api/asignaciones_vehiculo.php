<?php
require_once __DIR__ . '/../config/bootstrap.php';
requireAuth();
$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
$data=$method==='POST'?json_decode(file_get_contents('php://input'),true):$_GET;
if (!is_array($data) || ($method==='POST' && json_last_error()!==JSON_ERROR_NONE)) {
    $response=['success'=>false,'statusCode'=>400,'message'=>'JSON invalido.'];
} else $response=(new AsignacionVehiculoController((new Database())->getConnection()))->handle($method,$data);
http_response_code($response['statusCode']);
header('Cache-Control: no-store');
if ($response['statusCode']===405) header('Allow: GET, POST');
echo json_encode($response,JSON_UNESCAPED_UNICODE);
