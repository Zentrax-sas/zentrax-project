<?php
require_once __DIR__.'/../config/bootstrap.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'message'=>'Método no permitido.']);exit;}
$tracking=$_GET['tracking_number']??'';
$data=json_decode(file_get_contents('php://input'),true);
if(!is_string($tracking)||!is_array($data)||array_is_list($data)||json_last_error()!==JSON_ERROR_NONE){http_response_code(400);echo json_encode(['success'=>false,'message'=>'Datos inválidos.']);exit;}
$tracking=strtoupper(trim($tracking));
$db=(new Database())->getConnection();
if(!$db){http_response_code(503);echo json_encode(['success'=>false,'message'=>'No pudimos conectarnos al sistema.']);exit;}
$permiso=str_starts_with($tracking,'INC-')?hasEffectivePermission('incidencia.modificar',['OPERACIONES','PUNTOS_Y_DESTINOS']):hasEffectivePermission('solicitud.modificar',['OPERACIONES','PUNTOS_Y_DESTINOS']);
$token=preg_replace('/^Bearer /','',$_SERVER['HTTP_AUTHORIZATION']??'');
$respuesta=(new CancelacionController(new Cancelacion($db),new EdicionTramite($db)))->cancelar($tracking,$data,$token,$permiso);
http_response_code($respuesta['statusCode']);echo json_encode($respuesta);
