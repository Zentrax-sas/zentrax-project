<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../controllers/FotoUploadController.php';
$request=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
if(!preg_match('/^zemyna_f1_test_[a-f0-9]{12}$/D',$request['database']??''))exit(2);
$db=new PDO('mysql:host='.(getenv('DB_HOST')?:'localhost').';dbname='.$request['database'].';charset=utf8mb4',getenv('DB_USER')?:'root',getenv('DB_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
echo "READY\n";flush();
$result=(new FotoUploadController($db,$request['directory'],static fn($a,$b)=>copy($a,$b)))->upload($request['body'],$request['file'],null);
echo json_encode($result);
