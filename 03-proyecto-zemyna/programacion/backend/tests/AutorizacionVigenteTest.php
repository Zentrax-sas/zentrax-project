<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../helpers/auth.php';

final class AutorizacionVigenteTest extends TestCase
{
    private string $root;
    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/zemyna_f82_' . bin2hex(random_bytes(8));
        mkdir($this->root); mkdir($this->root . '/sessions');
        foreach (['api','config','controllers','models','helpers','exceptions'] as $directory) {
            mkdir($this->root . '/' . $directory);
            foreach (glob(__DIR__ . '/../' . $directory . '/*.php') ?: [] as $file) {
                copy($file, $this->root . '/' . $directory . '/' . basename($file));
            }
        }
        // Las APIs CRUD crean Database antes del guard. Este doble nunca conecta a MariaDB.
        file_put_contents($this->root . '/config/database.php',
            '<?php class Database { public function getConnection() { return $GLOBALS["fixtureDb"]; } }');
    }
    protected function tearDown(): void {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root,
            FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if ($item->isDir()) rmdir($item->getPathname()); else unlink($item->getPathname());
        }
        rmdir($this->root);
    }
    private function request(string $operation, string $changes = '', string $method = 'GET'): array {
        $script = 'session_save_path(' . var_export($this->root . '/sessions', true) . '); session_start();'
            . 'require ' . var_export($this->root . '/helpers/auth.php', true) . ';'
            . '$db=new PDO("sqlite::memory:",null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);'
            . '$db->exec("CREATE TABLE usuario(id_usuario INTEGER,nombre TEXT,apellido TEXT,activo TEXT);'
            . 'CREATE TABLE rol(id_rol INTEGER,nombre TEXT); CREATE TABLE permiso(id_permiso INTEGER,nombre TEXT);'
            . 'CREATE TABLE rol_permiso(id_rol INTEGER,id_permiso INTEGER);'
            . 'CREATE TABLE usuario_rol(id_usuario INTEGER,id_rol INTEGER,sector TEXT,fecha_desde TEXT,fecha_hasta TEXT);'
            . "INSERT INTO usuario VALUES(1,'Actual','Prueba','Activo'); INSERT INTO rol VALUES(1,'INSPECTOR');"
            . "INSERT INTO permiso VALUES(1,'vehiculo.consultar'); INSERT INTO rol_permiso VALUES(1,1);"
            . "INSERT INTO usuario_rol VALUES(1,1,'INSPECCION','2020-01-01',NULL);\");"
            . '$GLOBALS["fixtureDb"]=$db; AuthRequestContext::$connection=$db;'
            . '$_SESSION=["usuario"=>["id_usuario"=>1,"nombre"=>"Obsoleto","roles"=>["ADMINISTRADOR_TI"],'
            . '"autorizaciones"=>[["permiso"=>"vehiculo.modificar","sector"=>"OPERACIONES"]]]];'
            . '$_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . ';'
            . $changes
            . 'http_response_code(200); ob_start(); register_shutdown_function(function(){ $body=ob_get_clean();'
            . 'echo json_encode(["status"=>http_response_code(),"body"=>$body,"session"=>$_SESSION]); });'
            . $operation;
        $process = proc_open([PHP_BINARY, '-r', $script],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        $this->assertIsResource($process); fclose($pipes[0]);
        $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $this->assertSame(0,proc_close($process),$errors);
        return json_decode($output,true,512,JSON_THROW_ON_ERROR);
    }
    private function guard(): string {
        return 'requirePermission("vehiculo.consultar",["INSPECCION"]); http_response_code(200); echo "permitido";';
    }
    public function testActivoRolesYPermisosSeActualizan(): void {
        $result=$this->request($this->guard());
        $this->assertSame(200,$result['status']);
        $this->assertSame(['INSPECTOR'],$result['session']['usuario']['roles']);
        $this->assertSame('Actual',$result['session']['usuario']['nombre']);
        $this->assertSame(['vehiculo.consultar'],array_column($result['session']['usuario']['autorizaciones'],'permiso'));
    }
    /** @dataProvider rejectionCases */
    public function testRechazosSinPermisosObsoletos(string $changes,int $status): void {
        $result=$this->request($this->guard(),$changes);
        $this->assertSame($status,$result['status']);
        $body=json_decode($result['body'],true,512,JSON_THROW_ON_ERROR);
        $this->assertFalse($body['success']);
        $this->assertStringNotContainsString('SQL',$result['body']);
        $this->assertStringNotContainsString('Obsoleto',$result['body']);
    }
    public static function rejectionCases(): array {
        return [
            ['$_SESSION["usuario"]["id_usuario"]="1 OR 1=1";',401],
            ['$db->exec("UPDATE usuario SET activo=\'Inactivo\'");',401],
            ['$db->exec("DELETE FROM usuario");',401],
            ['$db->exec("DELETE FROM rol_permiso");',403],
            ['$db->exec("UPDATE usuario_rol SET fecha_hasta=\'2020-01-01\'");',403],
            ['$db->exec("UPDATE usuario_rol SET fecha_desde=\'2999-01-01\'");',403],
            ['$db->exec("UPDATE usuario_rol SET sector=\'OPERACIONES\'");',403],
            ['$db->exec("DROP TABLE permiso");',503],
            ['AuthRequestContext::$connection=null; $GLOBALS["fixtureDb"]=null;',503],
        ];
    }
    public function testSinCacheInclusivoYCambioDeRol(): void {
        $operation='requireAuth(); $today=(new DateTimeImmutable("now",new DateTimeZone("America/Montevideo")))->format("Y-m-d");'
            . '$db->prepare("UPDATE usuario_rol SET fecha_desde=?,fecha_hasta=?")->execute([$today,$today]);'
            . 'requireAuth(); if(!hasEffectivePermission("vehiculo.consultar",["INSPECCION"])) exit(9);'
            . '$db->exec("UPDATE rol SET nombre=\'OPERARIO\'; DELETE FROM rol_permiso");'
            . 'requirePermission("vehiculo.consultar",["INSPECCION"]);';
        $result=$this->request($operation);
        $this->assertSame(403,$result['status']);
        $this->assertSame(['OPERARIO'],$result['session']['usuario']['roles']);
    }
    public function testTiSinPermisoExplicitoEsRechazado(): void {
        $result=$this->request('requirePermission("permiso.no.asignado",["OPERACIONES"]); http_response_code(200);',
            '$db->exec("UPDATE rol SET nombre=\'ADMINISTRADOR_TI\'; DELETE FROM rol_permiso");');
        $this->assertSame(403,$result['status']);
    }
    public function testSessionDevuelveIdentidadActualSinPermisosPublicos(): void {
        $result=$this->request('require ' . var_export($this->root.'/api/session.php',true) . ';');
        $this->assertSame(200,$result['status']);
        $body=json_decode($result['body'],true,512,JSON_THROW_ON_ERROR);
        $this->assertSame(['id_usuario','nombre','apellido','roles'],array_keys($body['data']));
        $this->assertSame(['INSPECTOR'],$body['data']['roles']);
        $this->assertSame('Actual',$body['data']['nombre']);
    }
    public function testLogoutNoDependeDeCuentaNiDeBd(): void {
        foreach (['$db->exec("UPDATE usuario SET activo=\'Inactivo\'");',
            'AuthRequestContext::$connection=null; $GLOBALS["fixtureDb"]=null;'] as $changes) {
            $result=$this->request('require '.var_export($this->root.'/api/logout.php',true).';',$changes,'POST');
            $this->assertSame(200,$result['status']);
            $this->assertSame([],$result['session']);
        }
    }
    public function testCrudRechazaAntesDeConsultarDatosPrivados(): void {
        foreach (['usuarios.php','vehiculos.php','cuadrillas.php'] as $endpoint) {
            $result=$this->request('require '.var_export($this->root.'/api/'.$endpoint,true).';');
            $this->assertSame(403,$result['status']);
        }
    }
}
