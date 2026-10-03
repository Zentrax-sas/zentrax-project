<?php
if(PHP_SAPI!=='cli' || getenv('ZEMYNA_F3_V19_MARIADB')!=='1') exit(2);
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../controllers/IncidenciaController.php';
require_once __DIR__.'/../../controllers/VehiculoController.php';
$request=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
if(!preg_match('/^zemyna_f3_v19_test_[a-f0-9]{12}$/D',$request['database']??''))exit(2);
$db=new PDO('mysql:host='.(getenv('DB_HOST')?:'localhost').';dbname='.$request['database'].';charset=utf8mb4',getenv('DB_USER')?:'root',getenv('DB_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('SET innodb_lock_wait_timeout=10');$_SESSION=['usuario'=>['id_usuario'=>$request['actor']??1]];
echo "READY\n";flush();
if(isset($request['vehicle'])){
    $controller=new VehiculoController($db);
    $result=!empty($request['vehicle']['__delete'])?$controller->delete($request['vehicle']['id_vehiculo']):$controller->update($request['vehicle']);
}else $result=(new IncidenciaController($db))->assignAdministrative($request['body']);
echo json_encode($result,JSON_THROW_ON_ERROR);
