<?php
if(PHP_SAPI!=='cli' || !getenv('ZEMYNA_F63_MARIADB_DSN'))exit(2);
require_once __DIR__.'/../../controllers/SolicitudAdminController.php';
require_once __DIR__.'/../../controllers/SolicitudesPropiasController.php';
require_once __DIR__.'/../../controllers/AsignacionVehiculoController.php';
require_once __DIR__.'/../../controllers/VehiculoController.php';
require_once __DIR__.'/../../controllers/UsaController.php';
$r=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
if(!preg_match('/^f63_test_[a-f0-9]{12}$/D',$r['database']??''))exit(2);
$db=new PDO(getenv('ZEMYNA_F63_MARIADB_DSN'),'root',getenv('ZEMYNA_F63_MARIADB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$dir=str_replace('\\','/',$db->query('SELECT @@datadir')->fetchColumn());
if(!str_starts_with(strtolower($dir),strtolower(str_replace('\\','/',sys_get_temp_dir()).'/')) || !preg_match('~/zemyna-f6[123]-[a-f0-9]+/data/$~i',$dir))exit(2);
$db->exec('USE '.$r['database']);$db->exec('SET innodb_lock_wait_timeout=10');$_SESSION=['usuario'=>['id_usuario'=>$r['actor']??2]];

$_SESSION=['usuario'=>['id_usuario'=>($r['kind']==='start'?2:1)]];
echo "READY\n";flush();
$result=match($r['kind']) {
 'start'=>(new SolicitudesPropiasController($db))->put($r['body']),
 'v19'=>(new AsignacionVehiculoController($db))->handle('POST',$r['body']),
 'vehicle'=>(new VehiculoController($db))->update($r['body']),
 'use_update'=>(new UsaController($db))->update($r['body'])
};echo json_encode($result,JSON_THROW_ON_ERROR);
