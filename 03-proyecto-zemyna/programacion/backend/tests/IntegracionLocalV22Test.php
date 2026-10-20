<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../controllers/CancelacionController.php';
require_once __DIR__.'/../controllers/EdicionTramiteController.php';
require_once __DIR__.'/../models/SolicitudOperativa.php';
require_once __DIR__.'/../controllers/SolicitudRetiroController.php';
require_once __DIR__.'/../controllers/IncidenciaController.php';

/** Pruebas contra la copia restaurada; nunca acepta el nombre de la base real. */
final class IntegracionLocalV22Test extends TestCase {
    private PDO $db;
    private string $database;
    private array $tracks=[];
    private function connection(): PDO {
        return new PDO('mysql:host=127.0.0.1;port=3310;dbname='.$this->database.';charset=utf8mb4',getenv('DB_USER')?:'root',getenv('DB_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    }
    protected function setUp(): void {
        $name=getenv('ZEMYNA_LOCAL_V22_DATABASE');
        if (!$name) $this->markTestSkipped('Ensayo V22 no configurado.');
        if (!preg_match('/^zemyna_integracion_test_[a-z0-9_]{1,32}$/D',$name)) $this->fail('Base no autorizada.');
        $this->database=$name; $this->db=$this->connection();
    }
    protected function tearDown(): void {
        if (!isset($this->db)) return;
        if ($this->db->inTransaction()) $this->db->rollBack();
        foreach ($this->tracks as [$track,$table]) {
            $this->db->prepare('DELETE FROM tramite_edicion WHERE tracking_number=?')->execute([$track]);
            if ($table === 'solicitud') $this->db->prepare('DELETE FROM atencion_solicitud WHERE id_solicitud=(SELECT id_solicitud FROM solicitud WHERE tracking_number=?)')->execute([$track]);
            $this->db->prepare("DELETE FROM $table WHERE tracking_number=?")->execute([$track]);
        }
    }
    private function solicitud(): array {
        $type=$this->db->query('SELECT id_tipo_residuo,nombre FROM tipo_residuo ORDER BY id_tipo_residuo LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $track='RET-2026-'.strtoupper(bin2hex(random_bytes(3)));
        $track=substr($track,0,14);
        $this->db->prepare("INSERT INTO solicitud(tracking_number,fecha,descripcion,direccion,estado,id_tipo_residuo,tipo_solicitud,tipo_residuo_solicitado,fecha_confirmacion_residuo) VALUES(?,'2020-01-01','Ensayo V22','Ensayo','Pendiente',?,'Gran volumen',?,'2020-01-01')")->execute([$track,$type['id_tipo_residuo'],$type['nombre']]);
        $id=(int)$this->db->lastInsertId();$this->tracks[]=[$track,'solicitud'];
        $token=(new EdicionTramite($this->db))->emitirClave($track);
        return [$track,$id,$token,(int)$type['id_tipo_residuo']];
    }
    private function row(int $id): array {
        $s=$this->db->prepare('SELECT * FROM solicitud WHERE id_solicitud=?');$s->execute([$id]);return $s->fetch(PDO::FETCH_ASSOC);
    }
    public function testCancelacionCiudadanaSinAutorFicticioYRevision(): void {
        [$track,$id,$token]=$this->solicitud();$c=new CancelacionController(new Cancelacion($this->db),new EdicionTramite($this->db));
        self::assertSame(403,$c->cancelar($track,['revision'=>0],'',true)['statusCode']);
        self::assertSame(409,$c->cancelar($track,['revision'=>1],$token)['statusCode']);
        self::assertSame(200,$c->cancelar($track,['revision'=>0],$token)['statusCode']);
        $r=$this->row($id);self::assertSame('CIUDADANA',$r['origen_cancelacion']);self::assertNull($r['id_usuario_cancela']);self::assertNull($r['motivo_cancelacion']);self::assertNotNull($r['fecha_cancelacion']);
        self::assertSame(409,$c->cancelar($track,['revision'=>1],$token)['statusCode']);
    }
    public function testEdicionInvalidaClasificacionSoloSiCambiaResiduo(): void {
        [$track,$id,$token,$type]=$this->solicitud();$c=new EdicionTramiteController(new EdicionTramite($this->db));
        self::assertSame(200,$c->ejecutar('PUT',$track,['descripcion'=>'Corregida','id_tipo_residuo'=>$type,'revision'=>0],$token)['statusCode']);
        self::assertNotNull($this->row($id)['fecha_confirmacion_residuo']);
        $other=(int)$this->db->query('SELECT id_tipo_residuo FROM tipo_residuo WHERE id_tipo_residuo<>'.$type.' LIMIT 1')->fetchColumn();
        self::assertSame(200,$c->ejecutar('PUT',$track,['descripcion'=>'Otro residuo','id_tipo_residuo'=>$other,'revision'=>1],$token)['statusCode']);
        self::assertNull($this->row($id)['fecha_confirmacion_residuo']);self::assertNull($this->row($id)['id_usuario_confirma_residuo']);
        self::assertSame(409,$c->ejecutar('PUT',$track,['descripcion'=>'Vieja revision','id_tipo_residuo'=>$other,'revision'=>1],$token)['statusCode']);
    }
    public function testProgramadaAsignadaNoPermiteEdicionNiCancelacionPublica(): void {
        [$track,$id,$token,$type]=$this->solicitud();$crew=(int)$this->db->query('SELECT id_cuadrilla FROM cuadrilla LIMIT 1')->fetchColumn();
        $this->db->prepare("UPDATE solicitud SET estado='Programada',id_cuadrilla=? WHERE id_solicitud=?")->execute([$crew,$id]);
        $c=new EdicionTramiteController(new EdicionTramite($this->db));self::assertFalse($c->ejecutar('GET',$track,[],$token)['data']['editable']);
        self::assertSame(409,$c->ejecutar('PUT',$track,['descripcion'=>'No guardar','id_tipo_residuo'=>$type,'revision'=>0],$token)['statusCode']);
        self::assertSame(409,(new CancelacionController(new Cancelacion($this->db),new EdicionTramite($this->db)))->cancelar($track,['revision'=>0],$token)['statusCode']);
    }
    public function testRestriccionesRechazanCancelacionOperativaIncompletaYOrigenDesconocidoNuevo(): void {
        [$track,$id]=$this->solicitud();
        foreach (["estado='Cancelada',fecha_cancelacion=NOW(6)","estado='Cancelada',fecha_cancelacion=NOW(6),origen_cancelacion='OPERATIVA'"] as $set) {
            try {$this->db->exec("UPDATE solicitud SET $set WHERE id_solicitud=$id");self::fail('Cancelación incompleta aceptada.');}
            catch (PDOException $e) {self::assertContains((int)$e->errorInfo[1],[1644,4025]);}
        }
    }
    public function testCancelacionOperativaMantieneActorYMotivo(): void {
        [$track,$id]=$this->solicitud();
        $actor=(int)$this->db->query("SELECT DISTINCT u.id_usuario FROM usuario u JOIN usuario_rol ur ON ur.id_usuario=u.id_usuario JOIN rol_permiso rp ON rp.id_rol=ur.id_rol JOIN permiso p ON p.id_permiso=rp.id_permiso WHERE u.activo='Activo' AND ur.sector='OPERACIONES' AND ur.fecha_desde<=CURDATE() AND (ur.fecha_hasta IS NULL OR ur.fecha_hasta>=CURDATE()) AND p.nombre='solicitud.modificar' LIMIT 1")->fetchColumn();
        self::assertGreaterThan(0,$actor,'Se requiere actor vigente en la copia de ensayo.');
        $m=new SolicitudOperativa($this->db);$d=$m->detail($actor,$id);
        $result=$m->mutate($actor,['accion'=>'cancelar','id_solicitud'=>$id,'estado_esperado'=>$d['estado_esperado'],'version_esperada'=>$d['version_esperada'],'id_atencion_esperada'=>null,'motivo'=>'Cancelación de ensayo']);
        $r=$result['solicitud'];self::assertSame('OPERATIVA',$r['origen_cancelacion']);self::assertSame($actor,(int)$r['id_usuario_cancela']);self::assertSame('Cancelación de ensayo',$r['motivo_cancelacion']);
    }
    public function testBloqueoDeSolicitudSerializaConOtraConexion(): void {
        [$track,$id]=$this->solicitud();$other=$this->connection();$other->exec('SET SESSION innodb_lock_wait_timeout=1');
        $this->db->beginTransaction();TramitePublico::gate($this->db);
        $this->db->query("SELECT id_solicitud FROM solicitud WHERE id_solicitud=$id FOR UPDATE")->fetchAll();
        try {$other->exec("UPDATE solicitud SET descripcion='Carrera' WHERE id_solicitud=$id");self::fail('El segundo escritor no esperó.');}
        catch (PDOException $e) {self::assertSame(1205,(int)$e->errorInfo[1]);}
        finally {$this->db->rollBack();}
        self::assertSame('Ensayo V22',$this->row($id)['descripcion']);
    }
    public function testRetiroMovilYAltaIncidenciaConConsentimientoConservados(): void {
        $type=(int)$this->db->query('SELECT id_tipo_residuo FROM tipo_residuo LIMIT 1')->fetchColumn();
        $r=(new SolicitudRetiroController($this->db))->crear(['id_tipo_residuo'=>$type,'descripcion'=>'Retiro de ensayo','direccion'=>'Ensayo 123','calle'=>'Ensayo','numero_referencia'=>'123','latitud'=>-34.9,'longitud'=>-56.1,'celular'=>'099123456','consentimiento_whatsapp'=>true]);
        self::assertSame(201,$r['statusCode'],json_encode($r));$this->tracks[]=[$r['data']['tracking_number'],'solicitud'];self::assertSame(1,(int)$this->row((int)$r['data']['id_solicitud'])['consentimiento_whatsapp']);
    }
    public function testIntentoIniciadoAunqueCerradoImpideVolverAEdicionPublica(): void {
        [$track,$id,$token,$type]=$this->solicitud();
        $crew=(int)$this->db->query('SELECT id_cuadrilla FROM cuadrilla LIMIT 1')->fetchColumn();
        $actor=(int)$this->db->query('SELECT id_usuario FROM usuario LIMIT 1')->fetchColumn();
        $vehicle=(int)$this->db->query('SELECT id_vehiculo FROM vehiculo LIMIT 1')->fetchColumn();
        $this->db->prepare("INSERT INTO asignacion_vehiculo_operativa(id_cuadrilla,id_vehiculo,fecha_inicio,fecha_fin,id_usuario_asigna,id_usuario_finaliza,motivo_cierre) VALUES(?,?,'2020-01-01','2020-01-02',?,?,'Ensayo')")->execute([$crew,$vehicle,$actor,$actor]);
        $assignment=(int)$this->db->lastInsertId();
        try {
            $this->db->prepare("INSERT INTO atencion_solicitud(id_solicitud,id_cuadrilla,id_asignacion_vehiculo,estado,fecha_asignacion,id_usuario_asigna,fecha_aceptacion,id_usuario_acepta,fecha_inicio,id_usuario_inicia,fecha_cierre,id_usuario_cierra,motivo_cierre) VALUES(?,?,?,'Interrumpida','2020-01-01',?,'2020-01-01',?,'2020-01-01',?,'2020-01-02',?,'Ensayo')")->execute([$id,$crew,$assignment,$actor,$actor,$actor,$actor]);
            $c=new EdicionTramiteController(new EdicionTramite($this->db));
            self::assertFalse($c->ejecutar('GET',$track,[],$token)['data']['editable']);
            self::assertSame(409,$c->ejecutar('PUT',$track,['descripcion'=>'No guardar','id_tipo_residuo'=>$type,'revision'=>0],$token)['statusCode']);
        } finally {
            $this->db->prepare('DELETE FROM atencion_solicitud WHERE id_solicitud=?')->execute([$id]);
            $this->db->prepare('DELETE FROM asignacion_vehiculo_operativa WHERE id_asignacion_vehiculo=?')->execute([$assignment]);
        }
    }

}
