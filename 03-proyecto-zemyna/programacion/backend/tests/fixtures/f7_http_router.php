<?php
// Solo servidor y base F7 descartables; nunca toma la conexión habitual.
$dsn=getenv('ZEMYNA_F7_MARIADB_DSN');$name=getenv('ZEMYNA_F7_HTTP_DATABASE');
if (!is_string($dsn) || !preg_match('/^mysql:host=([^;]+);port=([0-9]+);charset=utf8mb4$/D',$dsn,$parts) || !preg_match('/^f7_test_[a-f0-9]{12}$/D',$name?:'')) exit(2);
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$dir=str_replace('\\','/',$db->query('SELECT @@datadir')->fetchColumn());
if (!str_starts_with(strtolower($dir),strtolower(str_replace('\\','/',sys_get_temp_dir()).'/')) || !preg_match('~/zemyna-f7-[a-f0-9]+/data/$~i',$dir)) exit(2);
require_once __DIR__.'/../../config/database.php';
putenv('DB_HOST='.$parts[1].';port='.$parts[2]);putenv('DB_NAME='.$name);putenv('DB_USER=root');putenv('DB_PASS=');
session_save_path(sys_get_temp_dir());session_start();
$actor=(int)($_SERVER['HTTP_X_TEST_ACTOR']??0);$_SESSION=$actor>0?['usuario'=>['id_usuario'=>$actor]]:[];
register_shutdown_function(function(){if(session_status()===PHP_SESSION_ACTIVE)session_destroy();});
require __DIR__.'/../../api/reportes_operativos.php';
