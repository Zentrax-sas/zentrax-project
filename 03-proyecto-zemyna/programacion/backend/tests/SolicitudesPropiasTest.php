<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../controllers/SolicitudAdminController.php';
require_once __DIR__.'/../controllers/SolicitudesPropiasController.php';
require_once __DIR__.'/fixtures/asignacion_vehiculo.php';
require_once __DIR__.'/fixtures/solicitud_admin_base.php';
class SolicitudesPropiasTest extends TestCase {
    use SolicitudAdminBase;
    protected PDO $db;
    private array $session;
    protected function connect(): PDO {return new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
    protected function install(): void {
        $this->schema();$this->seed();
        $this->db->exec("CREATE TABLE solicitud(id_solicitud INTEGER PRIMARY KEY,tracking_number TEXT,fecha TEXT,descripcion TEXT,direccion TEXT,estado TEXT,id_tipo_residuo INTEGER,email TEXT,telefono TEXT,tipo_solicitud TEXT,id_cuadrilla INTEGER,fecha_finalizacion TEXT,fecha_cancelacion TEXT,id_usuario_cancela INTEGER,motivo_cancelacion TEXT,fecha_confirmacion_residuo TEXT,id_usuario_confirma_residuo INTEGER);
        CREATE TABLE atencion_solicitud(id_atencion_solicitud INTEGER PRIMARY KEY AUTOINCREMENT,id_solicitud INTEGER,id_cuadrilla INTEGER,id_asignacion_vehiculo INTEGER,estado TEXT,fecha_asignacion TEXT,id_usuario_asigna INTEGER,fecha_aceptacion TEXT,id_usuario_acepta INTEGER,fecha_inicio TEXT,id_usuario_inicia INTEGER,fecha_cierre TEXT,id_usuario_cierra INTEGER,motivo_cierre TEXT);
        CREATE UNIQUE INDEX uq_as_open ON atencion_solicitud(id_solicitud) WHERE fecha_cierre IS NULL;");
    }
    protected function seedSolicitud(): void {
        $this->db->exec("INSERT INTO permiso(id_permiso,nombre) VALUES(21,'solicitud.consultar'),(22,'solicitud.modificar'),(23,'solicitud.operar');INSERT INTO rol_permiso(id_rol,id_permiso) VALUES(1,21),(1,22),(1,23);
        UPDATE vehiculo SET funcion_operativa='APOYO';DELETE FROM participa;
        INSERT INTO solicitud(id_solicitud,tracking_number,fecha,descripcion,direccion,estado,id_tipo_residuo,email,telefono,tipo_solicitud) VALUES(1,'REF-2026-00001','2020-01-01','Test','Test','Pendiente',1,'test@test.invalid','099111111','Reciclables'),(2,'REF-2026-00002','2020-01-01','Test','Test','Programada',1,'test@test.invalid','099111111','Reciclables');");
    }
    protected function setUp(): void {$this->session=$_SESSION??[];$_SESSION=['usuario'=>['id_usuario'=>1]];$this->db=$this->connect();$this->install();$this->seedSolicitud();}
    protected function tearDown(): void {if(isset($this->db)&&$this->db->inTransaction())$this->db->rollBack();$_SESSION=$this->session;}
    protected function c(): SolicitudAdminController {return new SolicitudAdminController($this->db);}
    protected function body(string $action,int $id=1,array $extra=[]): array {$d=$this->c()->get(['id'=>$id])['data'];return array_merge(['accion'=>$action,'id_solicitud'=>$id,'estado_esperado'=>$d['estado_esperado'],'version_esperada'=>$d['version_esperada'],'id_atencion_esperada'=>$d['id_atencion_esperada']],$extra);}
    protected function confirm(int $id=1): void {$this->assertSame(200,$this->c()->put($this->body('confirmar_residuo',$id,['id_tipo_residuo'=>1]))['statusCode']);}
    protected function assign(int $id=1): array {return $this->body('asignar',$id,['id_cuadrilla'=>1,'id_asignacion_vehiculo'=>1,'id_usa'=>1]);}
    protected function state(): array {return [$this->db->query('SELECT * FROM solicitud ORDER BY id_solicitud')->fetchAll(PDO::FETCH_ASSOC),$this->db->query('SELECT * FROM atencion_solicitud ORDER BY id_atencion_solicitud')->fetchAll(PDO::FETCH_ASSOC)];}

    protected function prepared(): void {
        $_SESSION=['usuario'=>['id_usuario'=>1]];$this->confirm();$this->assertSame(200,$this->c()->put($this->assign())['statusCode']);$_SESSION=['usuario'=>['id_usuario'=>2]];
    }
    protected function op(): SolicitudesPropiasController {return new SolicitudesPropiasController($this->db);}
    protected function ownBody(string $action,array $extra=[]): array {
        $d=$this->op()->get(['id_solicitud'=>1]);$this->assertSame(200,$d['statusCode']);$d=$d['data']['items'][0];
        return array_merge(['accion'=>$action,'id_solicitud'=>1,'id_atencion_solicitud'=>$d['atencion']['id_atencion_solicitud'],'estado_esperado'=>$d['estado_esperado'],'estado_operativo_esperado'=>$d['estado_operativo_esperado'],'version_esperada'=>$d['version_esperada']],$extra);
    }
    public function testOwnListDetailIsolationAndNoAdministrativeData(): void {
        $this->prepared();$r=$this->op()->get([]);$this->assertSame(200,$r['statusCode']);$this->assertCount(1,$r['data']['items']);$d=$r['data']['items'][0];
        $this->assertSame(1,(int)$d['id_solicitud']);$this->assertSame('APOYO',$d['vehiculo']['funcion_operativa']);$this->assertTrue($d['capacidades']['aceptar_solicitud']);
        foreach(['email','telefono','id_usuario_confirma_residuo','historial','id_usuario_asigna'] as $key)$this->assertArrayNotHasKey($key,$d);
        $this->assertSame(404,$this->op()->get(['id_solicitud'=>2])['statusCode']);$this->assertSame(404,$this->op()->get(['id_solicitud'=>99])['statusCode']);
        $body=$this->ownBody('aceptar_solicitud');$_SESSION=['usuario'=>['id_usuario'=>3]];$this->assertSame([],$this->op()->get([])['data']['items']);$this->assertSame(404,$this->op()->get(['id_solicitud'=>1])['statusCode']);$this->assertSame(404,$this->op()->put($body)['statusCode']);
    }
    public function testPermissionsMembershipAndNoTiBypass(): void {
        $this->prepared();$_SESSION=['usuario'=>['id_usuario'=>4,'roles'=>['ADMINISTRADOR_TI'],'autorizaciones'=>[['permiso'=>'solicitud.operar','sector'=>'OPERACIONES']]]];$this->assertSame(403,$this->op()->get([])['statusCode']);
        $_SESSION=['usuario'=>['id_usuario'=>2]];$this->db->exec("UPDATE usuario_cuadrilla SET fecha_inicio='2099-01-01' WHERE id_usuario=2");$this->assertSame(409,$this->op()->get([])['statusCode']);
        $this->db->exec("UPDATE usuario_cuadrilla SET fecha_inicio='2020-01-01',fecha_fin='2026-01-01',id_usuario_finaliza=1 WHERE id_usuario=2");$this->assertSame(409,$this->op()->get([])['statusCode']);
        $this->db->exec('DELETE FROM rol_permiso WHERE id_permiso=23');$this->assertSame(403,$this->op()->get([])['statusCode']);
        $this->db->exec("UPDATE usuario SET activo='Inactivo' WHERE id_usuario=2");$this->assertSame(401,$this->op()->get([])['statusCode']);$_SESSION=[];$this->assertSame(401,$this->op()->get([])['statusCode']);
    }
    public function testExpiredRoleWrongSectorAndMissingEligibility(): void {
        $this->prepared();$this->db->exec("UPDATE usuario_rol SET fecha_hasta='2020-01-02' WHERE id_usuario=2");$this->assertSame(403,$this->op()->get([])['statusCode']);
        $this->db->exec("UPDATE usuario_rol SET fecha_hasta=NULL,sector='INSPECCION' WHERE id_usuario=2");$this->assertSame(403,$this->op()->get([])['statusCode']);
        $this->db->exec("UPDATE usuario_rol SET sector='OPERACIONES' WHERE id_usuario=2;DELETE FROM rol_permiso WHERE id_permiso=3");$this->assertSame(403,$this->op()->get([])['statusCode']);
    }
    public function testAcceptanceStartAndFinishRecordServerActorsAndDates(): void {
        $this->prepared();$stale=$this->ownBody('aceptar_solicitud');$this->assertSame(200,$this->op()->put($stale)['statusCode']);$this->assertSame(409,$this->op()->put($stale)['statusCode']);
        $this->assertSame(409,$this->op()->put($this->ownBody('rechazar_solicitud',['motivo'=>'No']))['statusCode']);
        $this->assertSame(200,$this->op()->put($this->ownBody('iniciar_atencion_solicitud'))['statusCode']);$this->assertSame('En atención',$this->state()[0][0]['estado']);
        $_SESSION=['usuario'=>['id_usuario'=>1]];foreach(['cancelar','desasignar'] as $action)$this->assertSame(409,$this->c()->put($this->body($action,1,['motivo'=>'No']))['statusCode']);$_SESSION=['usuario'=>['id_usuario'=>2]];
        $this->assertSame(200,$this->op()->put($this->ownBody('finalizar_atencion_solicitud'))['statusCode']);[$s,$a]=$this->state();$this->assertSame('Finalizada',$s[0]['estado']);$this->assertSame('Finalizada',$a[0]['estado']);$this->assertNotNull($s[0]['fecha_finalizacion']);
        foreach(['id_usuario_acepta','id_usuario_inicia','id_usuario_cierra'] as $k)$this->assertSame(2,(int)$a[0][$k]);foreach(['fecha_aceptacion','fecha_inicio','fecha_cierre'] as $k)$this->assertNotNull($a[0][$k]);$this->assertLessThanOrEqual($a[0]['fecha_cierre'],$a[0]['fecha_inicio']);$this->assertSame([],$this->op()->get([])['data']['items']);
    }
    public function testRejectionRequiresReasonAndReturnsToPending(): void {
        $this->prepared();$body=$this->ownBody('rechazar_solicitud',['motivo'=>'No disponible']);
        foreach([null,'',[],true,str_repeat('x',501)] as $v){$bad=$body;$bad['motivo']=$v;$this->assertSame(400,$this->op()->put($bad)['statusCode']);}
        $this->assertSame(200,$this->op()->put($body)['statusCode']);[$s,$a]=$this->state();$this->assertSame('Pendiente',$s[0]['estado']);$this->assertNull($s[0]['id_cuadrilla']);$this->assertSame('Rechazada',$a[0]['estado']);$this->assertSame(2,(int)$a[0]['id_usuario_cierra']);$this->assertSame('No disponible',$a[0]['motivo_cierre']);$this->assertNotNull($a[0]['fecha_cierre']);$this->assertNull($a[0]['fecha_aceptacion']);$this->assertSame([],$this->op()->get([])['data']['items']);
    }
    /** @dataProvider invalidResources */
    public function testInvalidResourcesRemainVisibleAndBlockMutation(string $sql): void {
        $this->prepared();$body=$this->ownBody('aceptar_solicitud');$this->db->exec($sql);$before=$this->state();$r=$this->op()->get([]);$this->assertSame(200,$r['statusCode']);$this->assertCount(1,$r['data']['items']);$d=$r['data']['items'][0];$this->assertNotNull($d['motivo_bloqueo']);$this->assertNotContains(true,$d['capacidades']);$this->assertSame(409,$this->op()->put($body)['statusCode']);$this->assertSame($before,$this->state());$this->assertFalse($this->db->inTransaction());
    }
    public static function invalidResources(): array {return array_map(fn($s)=>[$s],[
        "UPDATE solicitud SET fecha_confirmacion_residuo=NULL,id_usuario_confirma_residuo=NULL WHERE id_solicitud=1",
        "UPDATE vehiculo SET activo=0 WHERE id_vehiculo=1","UPDATE vehiculo SET estado='En Mantenimiento' WHERE id_vehiculo=1","UPDATE vehiculo SET funcion_operativa='REGULAR' WHERE id_vehiculo=1",
        "INSERT INTO tipo_residuo(id_tipo_residuo,nombre) VALUES(2,'Otro');UPDATE vehiculo SET id_tipo_residuo=2 WHERE id_vehiculo=1","DELETE FROM usa WHERE id_usa=1",
        "UPDATE asignacion_vehiculo_operativa SET fecha_inicio='2099-01-01' WHERE id_asignacion_vehiculo=1",
        "UPDATE asignacion_vehiculo_operativa SET fecha_fin='2026-01-01',id_usuario_finaliza=1,motivo_cierre='Test' WHERE id_asignacion_vehiculo=1",
        "UPDATE asignacion_vehiculo_operativa SET fecha_fin='2026-01-01',id_usuario_finaliza=1,motivo_cierre='Test' WHERE id_asignacion_vehiculo=1;INSERT INTO asignacion_vehiculo_operativa(id_asignacion_vehiculo,id_cuadrilla,id_vehiculo,fecha_inicio,id_usuario_asigna) VALUES(9,1,1,'2026-01-02',1)"
    ]);}
    public function testContractRejectsTamperingMissingExpectationsAndWrongTransitions(): void {
        $this->prepared();$body=$this->ownBody('aceptar_solicitud');$before=$this->state();
        foreach(['id_usuario'=>4,'id_cuadrilla'=>2,'fecha_aceptacion'=>'2099-01-01'] as $k=>$v)$this->assertSame(400,$this->op()->put($body+[$k=>$v])['statusCode']);
        foreach(array_keys($body) as $k){$bad=$body;unset($bad[$k]);$this->assertSame(400,$this->op()->put($bad)['statusCode']);}
        foreach(['id_atencion_solicitud'=>999,'estado_operativo_esperado'=>'Aceptada','estado_esperado'=>'En atención','version_esperada'=>str_repeat('0',64)] as $k=>$v){$bad=$body;$bad[$k]=$v;$this->assertSame(409,$this->op()->put($bad)['statusCode']);}
        $this->assertSame(409,$this->op()->put($this->ownBody('iniciar_atencion_solicitud'))['statusCode']);$this->assertSame($before,$this->state());
        foreach([['page'=>0],['limit'=>101],['id_solicitud'=>[]],['extra'=>1]] as $q)$this->assertSame(400,$this->op()->get($q)['statusCode']);$this->assertSame(503,(new SolicitudesPropiasController(null))->get([])['statusCode']);
    }
    public function testPaginationAndTransferUseCurrentMembership(): void {
        $this->prepared();$_SESSION=['usuario'=>['id_usuario'=>1]];$this->confirm(2);$this->assertSame(200,$this->c()->put($this->assign(2))['statusCode']);$_SESSION=['usuario'=>['id_usuario'=>2]];
        $r=$this->op()->get(['limit'=>1]);$this->assertTrue($r['data']['has_more']);$this->assertSame(2,(int)$r['data']['items'][0]['id_solicitud']);$r=$this->op()->get(['limit'=>1,'page'=>2]);$this->assertFalse($r['data']['has_more']);$this->assertSame(1,(int)$r['data']['items'][0]['id_solicitud']);
        $this->db->exec('UPDATE usuario_cuadrilla SET id_cuadrilla=2 WHERE id_usuario=2');$this->assertSame([],$this->op()->get([])['data']['items']);$this->assertSame(404,$this->op()->get(['id_solicitud'=>1])['statusCode']);
    }
    public function testAmbiguousMembershipRejectedOrPreventedBySchema(): void {
        $this->prepared();
        try{$this->db->exec("INSERT INTO usuario_cuadrilla(id_usuario,id_cuadrilla,fecha_inicio,id_usuario_asigna) VALUES(2,2,'2020-01-01',1)");}
        catch(PDOException $e){$this->assertSame('mysql',$this->db->getAttribute(PDO::ATTR_DRIVER_NAME));$this->assertSame(1062,(int)$e->errorInfo[1]);return;}
        $this->assertSame(409,$this->op()->get([])['statusCode']);
    }
}
