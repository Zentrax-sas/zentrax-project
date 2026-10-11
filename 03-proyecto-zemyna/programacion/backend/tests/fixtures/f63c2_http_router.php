<?php
// Endpoint real y conexión real, exclusivamente servidor/base de prueba explícitos.
$dsn=getenv('ZEMYNA_F63_MARIADB_DSN');$name=getenv('ZEMYNA_F63_HTTP_DATABASE');
if(!is_string($dsn) || !preg_match('/^mysql:host=([^;]+);port=([0-9]+);charset=utf8mb4$/D',$dsn,$parts) || !preg_match('/^f63_test_[a-f0-9]{12}$/D',$name?:''))exit(2);
$db=new PDO($dsn,'root',getenv('ZEMYNA_F63_MARIADB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$dir=str_replace('\\','/',$db->query('SELECT @@datadir')->fetchColumn());
if(!str_starts_with(strtolower($dir),strtolower(str_replace('\\','/',sys_get_temp_dir()).'/')) || !preg_match('~/zemyna-f6[123]-[a-f0-9]+/data/$~i',$dir))exit(2);
$db->exec('USE '.$name);
require_once __DIR__.'/../../config/database.php';
putenv('DB_HOST='.$parts[1].';port='.$parts[2]);putenv('DB_NAME='.$name);putenv('DB_USER=root');putenv('DB_PASS='.(getenv('ZEMYNA_F63_MARIADB_PASSWORD')?:''));
require_once __DIR__.'/../../models/Usuario.php';
session_save_path(sys_get_temp_dir());session_start();
$actor=(int)($_SERVER['HTTP_X_TEST_ACTOR']??0);$user=new Usuario($db);
$_SESSION=$actor>0?['usuario'=>['id_usuario'=>$actor,'roles'=>array_column($user->getRolesVigentes($actor),'nombre'),'autorizaciones'=>$user->getAutorizacionesVigentes($actor)],'solicitud_admin_csrf'=>'test-token']:[];
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$endpoint=match($path){'/usuarios'=>'usuarios.php','/roles'=>'usuario_roles.php','/solicitudes'=>'solicitudes_admin.php',default=>null};
if(!$endpoint){http_response_code(404);exit;}
require __DIR__.'/../../api/'.$endpoint;
