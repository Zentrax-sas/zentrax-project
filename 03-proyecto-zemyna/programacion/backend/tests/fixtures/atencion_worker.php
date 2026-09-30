<?php
require_once __DIR__ . '/../../controllers/RecoleccionController.php';
require_once __DIR__ . '/../../controllers/IncidenciaController.php';
$request = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!preg_match('/^f42_test_[a-f0-9]+$/D', $request['database'])) exit(2);
$db = new PDO(getenv('ZEMYNA_F42_MARIADB_DSN') . ';dbname=' . $request['database'], 'root', getenv('ZEMYNA_F42_MARIADB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('SET innodb_lock_wait_timeout=10');
$_SESSION = ['usuario'=>['id_usuario'=>$request['user']]];
echo "READY\n"; flush();
$result = $request['admin']
    ? (new IncidenciaController($db))->assignAdministrative($request['body'])
    : (new RecoleccionController($db))->modificar($request['body']);
echo json_encode($result, JSON_THROW_ON_ERROR);
