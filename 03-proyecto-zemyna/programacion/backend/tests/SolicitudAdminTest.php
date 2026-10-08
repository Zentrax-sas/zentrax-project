<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../controllers/SolicitudAdminController.php';
require_once __DIR__.'/fixtures/asignacion_vehiculo.php';
require_once __DIR__.'/fixtures/solicitud_admin_base.php';
class SolicitudAdminTest extends TestCase {
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
    public function testReadingFiltersAndHistory(): void {
        $r=$this->c()->get(['limit'=>1]);$this->assertSame(200,$r['statusCode']);$this->assertTrue($r['data']['has_more']);$this->assertSame(2,(int)$r['data']['items'][0]['id_solicitud']);
        $this->assertCount(1,$this->c()->get(['estado'=>'Programada','desde'=>'2019-01-01','hasta'=>'2020-01-01','tipo_solicitud'=>'Reciclables'])['data']['items']);
        $d=$this->c()->get(['id'=>2])['data'];$this->assertTrue($d['historica_programada_sin_intento']);$this->assertSame([],$d['historial']);
        foreach([['limit'=>101],['page'=>0],['desde'=>'2026-02-30'],['estado'=>'x'],['extra'=>1]] as $q)$this->assertSame(400,$this->c()->get($q)['statusCode']);
        $this->assertSame(404,$this->c()->get(['id'=>99])['statusCode']);
    }
    public function testPermissionsWithoutTiBypass(): void {
        $_SESSION=['usuario'=>['id_usuario'=>4,'roles'=>['ADMINISTRADOR_TI'],'autorizaciones'=>[['permiso'=>'solicitud.modificar','sector'=>'OPERACIONES']]]];$this->assertSame(403,$this->c()->get([])['statusCode']);
        $_SESSION=['usuario'=>['id_usuario'=>1]];$this->db->exec('DELETE FROM rol_permiso WHERE id_permiso=22');$this->assertSame(200,$this->c()->get([])['statusCode']);$this->assertFalse($this->c()->get(['id'=>1])['data']['capacidades']['modificar']);$this->assertSame(403,$this->c()->put([])['statusCode']);
        $this->db->exec("UPDATE usuario_rol SET fecha_hasta='2020-01-02'");$this->assertSame(403,$this->c()->get([])['statusCode']);$this->db->exec("UPDATE usuario SET activo='Inactivo' WHERE id_usuario=1");$this->assertSame(401,$this->c()->get([])['statusCode']);
    }
    public function testExplicitConfirmationAndContract(): void {
        $b=$this->body('confirmar_residuo',1,['id_tipo_residuo'=>1]);
        foreach(['id_usuario'=>9,'fecha_confirmacion_residuo'=>'2099-01-01','extra'=>1] as $k=>$v)$this->assertSame(400,$this->c()->put($b+[$k=>$v])['statusCode']);
        $bad=$b;unset($bad['id_atencion_esperada']);$this->assertSame(400,$this->c()->put($bad)['statusCode']);
        foreach([null,[],true,999] as $v){$bad=$b;$bad['id_tipo_residuo']=$v;$this->assertSame(400,$this->c()->put($bad)['statusCode']);}
        $this->confirm();$s=$this->state()[0][0];$this->assertSame(1,(int)$s['id_usuario_confirma_residuo']);$this->assertNotNull($s['fecha_confirmacion_residuo']);$this->assertSame(409,$this->c()->put($b)['statusCode']);
    }
    public function testTransitions(): void {
        $this->confirm();$b=$this->assign();$this->assertSame(200,$this->c()->put($b)['statusCode']);$this->assertSame(409,$this->c()->put($b)['statusCode']);
        $this->assertSame(409,$this->c()->put($this->body('confirmar_residuo',1,['id_tipo_residuo'=>1]))['statusCode']);
        $this->assertSame(200,$this->c()->put($this->body('reasignar',1,['id_cuadrilla'=>2,'id_asignacion_vehiculo'=>2,'id_usa'=>2,'motivo'=>'Cambio']))['statusCode']);$this->assertSame('Interrumpida',$this->state()[1][0]['estado']);
        $this->assertSame(200,$this->c()->put($this->body('desasignar',1,['motivo'=>'Retiro']))['statusCode']);$this->assertSame('Pendiente',$this->state()[0][0]['estado']);$this->assertNull($this->state()[0][0]['id_cuadrilla']);
        $this->assertSame(200,$this->c()->put($this->assign())['statusCode']);$this->assertSame(200,$this->c()->put($this->body('cancelar',1,['motivo'=>'Cancelacion']))['statusCode']);$this->assertSame('Cancelada',$this->state()[0][0]['estado']);$this->assertCount(3,$this->state()[1]);
        $this->assertSame(409,$this->c()->put($this->body('cancelar',1,['motivo'=>'Otra']))['statusCode']);
    }
    public function testHistoricalRecovery(): void {
        $this->assertSame(409,$this->c()->put($this->assign(2))['statusCode']);$this->confirm(2);
        $this->assertSame(409,$this->c()->put($this->body('reasignar',2,['id_cuadrilla'=>1,'id_asignacion_vehiculo'=>1,'id_usa'=>1,'motivo'=>'Cambio']))['statusCode']);
        $this->assertSame(200,$this->c()->put($this->body('desasignar',2,['motivo'=>'Revision']))['statusCode']);$this->assertSame([],$this->state()[1]);$this->assertSame(200,$this->c()->put($this->assign(2))['statusCode']);
    }
    /** @dataProvider unavailable */
    public function testCandidates(string $sql): void {
        $this->confirm();$b=$this->assign();$this->db->exec($sql);$before=$this->state();$this->assertSame(409,$this->c()->put($b)['statusCode']);$this->assertSame($before,$this->state());
        $this->assertNotContains(1,array_column($this->c()->get(['id'=>1,'opciones'=>'asignacion'])['data']['items'],'id_cuadrilla'));
    }
    public static function unavailable(): array {return array_map(fn($s)=>[$s],["UPDATE vehiculo SET activo=0 WHERE id_vehiculo=1","UPDATE vehiculo SET estado='En Mantenimiento' WHERE id_vehiculo=1","UPDATE vehiculo SET funcion_operativa=NULL WHERE id_vehiculo=1","UPDATE vehiculo SET funcion_operativa='REGULAR' WHERE id_vehiculo=1",
        "UPDATE vehiculo SET funcion_operativa='REGULAR' WHERE id_vehiculo=1;INSERT INTO participa(id_participa,id_usa,id_recorrido,hora_inicio) VALUES(1,1,1,'08:00:00')",
        "INSERT INTO tipo_residuo(id_tipo_residuo,nombre) VALUES(2,'Otro');UPDATE vehiculo SET id_tipo_residuo=2 WHERE id_vehiculo=1","UPDATE asignacion_vehiculo_operativa SET fecha_inicio='2099-01-01' WHERE id_asignacion_vehiculo=1",
        "UPDATE asignacion_vehiculo_operativa SET fecha_fin='2026-01-01',id_usuario_finaliza=1,motivo_cierre='Test' WHERE id_asignacion_vehiculo=1","DELETE FROM usa WHERE id_usa=1","INSERT INTO usa(id_usa,id_cuadrilla,id_vehiculo) VALUES(9,1,1)","DELETE FROM usuario_cuadrilla WHERE id_cuadrilla=1","DELETE FROM rol_permiso WHERE id_permiso=23"]);}
    public function testStartedAttentionBlocked(): void {
        $this->confirm();$this->assertSame(200,$this->c()->put($this->assign())['statusCode']);$now=SolicitudOperativa::now();
        $s=$this->db->prepare("UPDATE atencion_solicitud SET estado='En atención',fecha_aceptacion=?,id_usuario_acepta=2,fecha_inicio=?,id_usuario_inicia=2");$s->execute([$now,$now]);$this->db->exec("UPDATE solicitud SET estado='En atención' WHERE id_solicitud=1");
        foreach(['cancelar','desasignar','reasignar'] as $a){$extra=['motivo'=>'Test'];if($a==='reasignar')$extra+=['id_cuadrilla'=>2,'id_asignacion_vehiculo'=>2,'id_usa'=>2];$this->assertSame(409,$this->c()->put($this->body($a,1,$extra))['statusCode']);}
    }
    public function testOptionsAndHistoricalInitialAssignment(): void {
        $this->assertSame([],$this->c()->get(['id'=>2,'opciones'=>'asignacion'])['data']['items']);$this->confirm(2);
        $options=$this->c()->get(['id'=>2,'opciones'=>'asignacion'])['data']['items'];$this->assertCount(2,$options);foreach($options as $o)$this->assertNull($o['id_recorrido']);
        $this->assertSame(200,$this->c()->put($this->assign(2))['statusCode']);$this->assertCount(1,$this->state()[1]);$this->assertFalse($this->c()->get(['id'=>2])['data']['historica_programada_sin_intento']);
    }
    public function testCancelWithoutAttemptAndInvalidReasons(): void {
        foreach([null,'',str_repeat('x',501),[],true] as $reason)$this->assertSame(400,$this->c()->put($this->body('cancelar',1,['motivo'=>$reason]))['statusCode']);
        $this->assertSame(200,$this->c()->put($this->body('cancelar',1,['motivo'=>'Revision']))['statusCode']);$this->assertSame([],$this->state()[1]);
        $s=$this->state()[0][0];$this->assertNotNull($s['fecha_cancelacion']);$this->assertSame(1,(int)$s['id_usuario_cancela']);$this->assertSame('Revision',$s['motivo_cancelacion']);
        $this->assertCount(1,$this->c()->get(['estado'=>'Cerradas'])['data']['items']);
    }
    public function testFinalizedAndWrongSectorAndInfrastructure(): void {
        $this->db->exec("UPDATE solicitud SET estado='Finalizada' WHERE id_solicitud=1");$this->assertSame(409,$this->c()->put($this->body('confirmar_residuo',1,['id_tipo_residuo'=>1]))['statusCode']);
        $this->db->exec("UPDATE usuario_rol SET sector='INSPECCION'");$this->assertSame(403,$this->c()->get([])['statusCode']);
        $this->assertSame(503,(new SolicitudAdminController(null))->get([])['statusCode']);
        $_SESSION=[];$this->assertSame(401,$this->c()->get([])['statusCode']);
    }
}
