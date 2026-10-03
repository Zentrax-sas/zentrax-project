<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../controllers/AsignacionVehiculoController.php';
require_once __DIR__.'/../../controllers/VehiculoController.php';
$request=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
if (getenv('ZEMYNA_V19_MARIADB')!=='1' || !preg_match('/^zemyna_v19_test_[a-f0-9]{12}$/D',$request['database'] ?? '')) exit(2);
$db=new PDO('mysql:host='.(getenv('DB_HOST')?:'localhost').';dbname='.$request['database'].';charset=utf8mb4',getenv('DB_USER')?:'root',getenv('DB_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('SET innodb_lock_wait_timeout=10');
$_SESSION=['usuario'=>['id_usuario'=>1]];
echo "READY\n"; flush();
$result=isset($request['body']['fixture_vehicle_body'])
    ? (new VehiculoController($db))->update($request['body']['fixture_vehicle_body'])
    : (new AsignacionVehiculoController($db))->handle('POST',$request['body']);
echo json_encode($result,JSON_THROW_ON_ERROR);
