<?php
require_once __DIR__.'/../config/bootstrap.php';
require_once __DIR__.'/../helpers/solicitud_admin_http.php';
header('Cache-Control: no-store');
header_remove('Access-Control-Allow-Origin');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
$method=$_SERVER['REQUEST_METHOD'];
$error=fn($code,$message)=>['success'=>false,'data'=>null,'message'=>$message,'errors'=>[],'statusCode'=>$code];
if(empty($_SESSION['usuario']))$result=$error(401,'No autenticado.');
elseif(!in_array($method,['GET','PUT'],true))$result=$error(405,'Método HTTP no permitido.');
elseif($method==='PUT' && !solicitudAdminCsrf($_SERVER,$_SESSION))$result=$error(403,'Validación CSRF fallida. Volvé a consultar.');
else {
    $controller=new SolicitudAdminController((new Database())->getConnection());
    if($method==='GET') {
        $result=$controller->get($_GET);
        if($result['success']) { $_SESSION['solicitud_admin_csrf']??=bin2hex(random_bytes(32));$result['csrf_token']=$_SESSION['solicitud_admin_csrf']; }
    } else {
        $raw=json_decode(file_get_contents('php://input'));
        $result=json_last_error()!==JSON_ERROR_NONE || !is_object($raw)?$error(400,'JSON inválido.'):$controller->put((array)$raw);
    }
}
http_response_code($result['statusCode']);echo json_encode($result,JSON_UNESCAPED_UNICODE);
