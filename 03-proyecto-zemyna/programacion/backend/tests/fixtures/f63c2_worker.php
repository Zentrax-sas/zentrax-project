<?php
if(PHP_SAPI!=='cli' || !getenv('ZEMYNA_F63_MARIADB_DSN'))exit(2);
require_once __DIR__.'/../../controllers/SolicitudAdminController.php';
require_once __DIR__.'/../../controllers/SolicitudesPropiasController.php';
require_once __DIR__.'/../../controllers/UsuarioController.php';
require_once __DIR__.'/../../controllers/UsuarioRolController.php';
$r=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
if(!preg_match('/^f63_test_[a-f0-9]{12}$/D',$r['database']??''))exit(2);
$db=new PDO(getenv('ZEMYNA_F63_MARIADB_DSN'),'root',getenv('ZEMYNA_F63_MARIADB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$dir=str_replace('\\','/',$db->query('SELECT @@datadir')->fetchColumn());
if(!str_starts_with(strtolower($dir),strtolower(str_replace('\\','/',sys_get_temp_dir()).'/')) || !preg_match('~/zemyna-f6[123]-[a-f0-9]+/data/$~i',$dir))exit(2);
$db->exec('USE '.$r['database']);$db->exec('SET innodb_lock_wait_timeout=10');$_SESSION=['usuario'=>['id_usuario'=>$r['actor']??2]];

$_SESSION=['usuario'=>['id_usuario'=>($r['actor']??(in_array($r['kind'],['start','finish'],true)?2:1))]];
echo "READY\n";flush();
$result=match($r['kind']) {
 'finish'=>(new SolicitudesPropiasController($db))->put($r['body']),
 'recover'=>(new SolicitudAdminController($db))->put($r['body']),
 'user'=>(new UsuarioController($db))->update($r['body']),
 'suspend'=>(new UsuarioController($db))->delete($r['body']['id_usuario']),
 'role'=>(new UsuarioRolController($db))->finalizar($r['body']),
 'role_create'=>(new UsuarioRolController($db))->create($r['body']),
 'membership'=>membership($db,$r['body']),
 'start'=>(new SolicitudesPropiasController($db))->put($r['body']),
};echo json_encode($result,JSON_THROW_ON_ERROR);

function membership(PDO $db,array $b): array {try {(new RecoleccionOperativa($db))->asignar(1,2,null,'finalizar_pertenencia',$b['pertenencia']);return ['statusCode'=>200];}catch(Throwable $e){return ContinuidadSolicitudOperativa::error($e);}}
