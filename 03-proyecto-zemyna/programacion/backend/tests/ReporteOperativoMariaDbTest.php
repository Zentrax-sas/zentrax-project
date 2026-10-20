<?php
require_once __DIR__.'/fixtures/mariadb_sql.php';
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../controllers/ReporteOperativoController.php';
require_once __DIR__.'/../models/Dashboard.php';
require_once __DIR__.'/../models/Incidencia.php';
require_once __DIR__.'/fixtures/solicitud_admin_base.php';

final class ReporteOperativoMariaDbTest extends TestCase
{
    use SolicitudAdminBase;
    protected PDO $db;
    private PDO $server;
    private string $name;
    private array $session;
    private static ?PDO $shared = null;
    private static ?PDO $admin = null;
    private static string $database;
    protected function setUp(): void {
        $dsn = getenv('ZEMYNA_F7_MARIADB_DSN');
        if (!$dsn) $this->markTestSkipped('MariaDB F7 descartable no configurada.');
        $this->server = new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $dir = str_replace('\\','/',$this->server->query('SELECT @@datadir')->fetchColumn());
        $this->assertStringStartsWith(strtolower(str_replace('\\','/',sys_get_temp_dir()).'/'),strtolower($dir));
        $this->assertMatchesRegularExpression('~/zemyna-f7-[a-f0-9]+/data/$~i',$dir);
        if (self::$shared) {
            $this->db=self::$shared;$this->name=self::$database;
            $this->db->exec('SET FOREIGN_KEY_CHECKS=0');
            try { foreach ($this->db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
                if (!preg_match('/^[a-z_]+$/D',$table)) throw new RuntimeException('Tabla inesperada.');
                $this->db->exec('DELETE FROM `'.$table.'`');
            }} finally { $this->db->exec('SET FOREIGN_KEY_CHECKS=1'); }
        } else {
        $this->name = 'f7_test_'.bin2hex(random_bytes(6));
        $this->server->exec('CREATE DATABASE '.$this->name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->db = new PDO($dsn.';dbname='.$this->name,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->db->exec(mariaDbSqlForPdo(file_get_contents(__DIR__.'/../../base-datos/database/sql/schema.sql')));
        self::$shared=$this->db;self::$admin=$this->server;self::$database=$this->name;
        }
        $this->seed();
        $this->db->exec("INSERT INTO permiso(id_permiso,nombre) VALUES(21,'solicitud.consultar'),(22,'contenedor.consultar');INSERT INTO rol_permiso VALUES(1,21),(1,22);
            INSERT INTO solicitud(id_solicitud,tracking_number,fecha,descripcion,direccion,estado,id_tipo_residuo,email,telefono,tipo_solicitud) VALUES
            (1,'REF-TEST1','2026-10-01 00:00:00','PRIVADO','PRIVADO','Pendiente',1,'fixture@test.invalid','000','Reciclables'),
            (2,'REF-TEST2','2026-09-30 00:00:00','PRIVADO','PRIVADO','Finalizada',1,'fixture@test.invalid','000','Reciclables'),
            (3,'REF-TEST3','2026-10-01 23:59:59','PRIVADO','PRIVADO','Programada',1,'fixture@test.invalid','000','Gran volumen'),
            (4,'REF-TEST4','2026-10-02 00:00:00','PRIVADO','PRIVADO','Pendiente',1,'fixture@test.invalid','000','Reciclables');
            UPDATE solicitud SET fecha_finalizacion='2026-10-01 23:59:59',fecha_confirmacion_residuo='2026-09-30 00:00:00' WHERE id_solicitud=2;
            INSERT INTO atencion_solicitud(id_solicitud,id_cuadrilla,id_asignacion_vehiculo,estado,fecha_asignacion,id_usuario_asigna,fecha_aceptacion,id_usuario_acepta,fecha_inicio,id_usuario_inicia,fecha_cierre,id_usuario_cierra,motivo_cierre) VALUES
            (2,1,1,'Interrumpida','2026-09-30',1,'2026-09-30',2,'2026-10-01 00:00:00',2,'2026-10-01 00:10:00',1,'Test'),
            (2,1,1,'Finalizada','2026-10-01 01:00:00',1,'2026-10-01 01:10:00',2,'2026-10-01 01:20:00',2,'2026-10-01 01:40:00',2,NULL);
            INSERT INTO atencion_incidencia(id_incidencia,id_cuadrilla,estado,origen,fecha_registro,id_usuario_registra,fecha_cierre,id_usuario_cierra,motivo_cierre) VALUES(1,1,'Rechazada','Asignacion','2026-10-01',1,'2026-10-01 23:59:59',2,'Test');
            UPDATE recorrido SET estado='Finalizado',fecha_inicio='2026-10-01',fecha_fin='2026-10-01 23:59:59',id_usuario_inicio=2,id_usuario_fin=2 WHERE id_recorrido=1;
            INSERT INTO contenedor(id_contenedor,codigo,capacidad,direccion,latitud,longitud,estado,id_tipo_residuo,id_ruta) VALUES(1,'F7TEST',1,'Test',0,0,'Disponible',1,1);
            INSERT INTO atencion_contenedor(id_recorrido,id_contenedor,id_usuario,fecha_atencion) VALUES(1,1,2,'2026-10-01 23:59:59');");
        $this->session = $_SESSION ?? []; $_SESSION = ['usuario'=>['id_usuario'=>1]];
    }
    protected function tearDown(): void {
        if (isset($this->db) && $this->db->inTransaction()) $this->db->rollBack();
        if (isset($this->session)) $_SESSION = $this->session;
    }
    public static function tearDownAfterClass(): void {
        if (self::$admin && isset(self::$database) && preg_match('/^f7_test_[a-f0-9]{12}$/D',self::$database)) self::$admin->exec('DROP DATABASE '.self::$database);
        self::$shared=null;self::$admin=null;
    }
    private function get(array $q=[],string $method='GET'): array { return (new ReporteOperativoController($this->db))->consultar($q,$method); }
    public function testCountsEventsBoundariesAndPrivacy(): void {
        $r = $this->get(['desde'=>'2026-10-01','hasta'=>'2026-10-01']); $this->assertSame(200,$r['statusCode']); $d=$r['data'];
        $this->assertSame('America/Montevideo',$d['zona_horaria']);
        $this->assertSame(4,array_sum($d['solicitudes']['estados_actuales']));
        $this->assertSame(['creadas'=>2,'finalizadas'=>1],$d['solicitudes']['periodo']);
        $this->assertSame(3,$d['solicitudes']['pendientes_clasificacion_historica_actual']);
        $this->assertSame(['aceptados'=>1,'iniciados'=>2,'finalizados'=>1,'rechazados'=>0,'interrumpidos'=>1],$d['atencion']['solicitudes']['hitos_periodo']);
        $this->assertSame(['muestras'=>1,'promedio_segundos'=>1200.0],$d['atencion']['solicitudes']['duracion_atencion']['finalizados']);
        $this->assertSame(['muestras'=>1,'promedio_segundos'=>600.0],$d['atencion']['solicitudes']['duracion_atencion']['interrumpidos']);
        $this->assertSame(1,$d['atencion']['incidencias']['hitos_periodo']['rechazados']);
        foreach (['iniciados','finalizados','atenciones_contenedores'] as $key) $this->assertSame(1,$d['recorridos'][$key]);
        foreach (['PRIVADO','fixture@','telefono','tracking','id_usuario','matricula'] as $private) $this->assertStringNotContainsString($private,json_encode($d));
        $this->assertFalse($this->db->inTransaction());
        $next=$this->get(['desde'=>'2026-10-02','hasta'=>'2026-10-02'])['data'];$this->assertSame(1,$next['solicitudes']['periodo']['creadas']);$this->assertSame(0,$next['recorridos']['atenciones_contenedores']);
    }
    public function testHistoricalMissingDatesAndPlannedStarts(): void {
        $this->db->exec("UPDATE solicitud SET fecha_finalizacion=NULL WHERE id_solicitud=2;UPDATE recorrido SET id_usuario_inicio=NULL WHERE id_recorrido=1;");
        $this->db->exec("INSERT INTO atencion_solicitud(id_solicitud,id_cuadrilla,id_asignacion_vehiculo,estado,fecha_asignacion,id_usuario_asigna,fecha_cierre,id_usuario_cierra,motivo_cierre) VALUES(1,1,1,'Interrumpida','2026-10-01',1,'2026-10-01 00:10:00',1,'Test')");
        $d=$this->get()['data'];$this->assertSame(1,$d['solicitudes']['finalizadas_sin_fecha_actual']);$this->assertSame(0,$d['solicitudes']['periodo']['finalizadas']);
        $this->assertSame(2,$d['atencion']['solicitudes']['hitos_periodo']['interrumpidos']);$this->assertSame(1,$d['atencion']['solicitudes']['duracion_atencion']['interrumpidos']['muestras']);
        $this->assertSame(0,$d['recorridos']['iniciados']);$this->assertSame(1,$d['recorridos']['sin_autor_inicio_actual']);
        $this->assertSame(['muestras'=>0,'promedio_segundos'=>null],$d['atencion']['incidencias']['duracion_atencion']['finalizados']);
    }
    public function testPermissionsAreSpecificCurrentAndNoTiBypass(): void {
        $_SESSION=['usuario'=>['id_usuario'=>4,'roles'=>['ADMINISTRADOR_TI']]];$this->assertSame(403,$this->get()['statusCode']);
        $_SESSION=['usuario'=>['id_usuario'=>1]];$this->db->exec('DELETE FROM rol_permiso WHERE id_permiso=21');
        $this->assertSame(403,$this->get()['statusCode']);$this->assertSame(403,$this->get(['informe'=>'atencion'])['statusCode']);$this->assertSame(200,$this->get(['informe'=>'recorridos'])['statusCode']);
        $this->db->exec("UPDATE usuario_rol SET fecha_hasta='2020-01-02' WHERE id_usuario=1");$this->assertSame(403,$this->get(['informe'=>'recorridos'])['statusCode']);
        $this->db->exec("UPDATE usuario_rol SET fecha_hasta=NULL,sector='TI' WHERE id_usuario=1");$this->assertSame(403,$this->get(['informe'=>'recorridos'])['statusCode']);
        $this->db->exec("UPDATE usuario SET activo='Inactivo' WHERE id_usuario=1");$this->assertSame(401,$this->get()['statusCode']);$_SESSION=[];$this->assertSame(401,$this->get()['statusCode']);
    }
    public function testInvalidContractAndSafeInfrastructureFailure(): void {
        $this->assertSame(503,(new ReporteOperativoController(null))->consultar([])['statusCode']);
        foreach ([['desde'=>'2026-02-30'],['hasta'=>'9999-12-31'],['desde'=>[]],['informe'=>[]],['page'=>1],['desde'=>'2026-10-02','hasta'=>'2026-10-01'],['desde'=>"2026-01-01' OR 1=1"]] as $q) $this->assertSame(400,$this->get($q)['statusCode']);
        $this->assertSame(405,$this->get([],'PUT')['statusCode']);
        $this->db->exec('RENAME TABLE atencion_solicitud TO unavailable_attempts');$r=$this->get();$this->assertSame(500,$r['statusCode']);$this->assertStringNotContainsString('SQL',json_encode($r));$this->assertFalse($this->db->inTransaction());
        $this->db->exec('RENAME TABLE unavailable_attempts TO atencion_solicitud');
    }
    public function testEmptyPeriodAndSourceIsolation(): void {
        $r=$this->get(['informe'=>'solicitudes','desde'=>'2099-01-01','hasta'=>'2099-01-02']);$this->assertSame(200,$r['statusCode']);$this->assertArrayNotHasKey('atencion',$r['data']);$this->assertSame(['creadas'=>0,'finalizadas'=>0],$r['data']['solicitudes']['periodo']);$this->assertSame(4,array_sum($r['data']['solicitudes']['estados_actuales']));
    }
    public function testMontevideoInclusiveRoleDatesAndEmptySources(): void {
        $previous=date_default_timezone_get();date_default_timezone_set('UTC');
        try {
            $today=(new DateTimeImmutable('now',new DateTimeZone('America/Montevideo')))->format('Y-m-d');
            $s=$this->db->prepare('UPDATE usuario_rol SET fecha_desde=?,fecha_hasta=? WHERE id_usuario=1');$s->execute([$today,$today]);
            $r=$this->get();$this->assertSame(200,$r['statusCode']);$this->assertStringStartsWith($today,$r['data']['generado_en']);
            $this->db->exec('DELETE FROM atencion_solicitud;DELETE FROM solicitud;DELETE FROM atencion_incidencia;DELETE FROM atencion_contenedor;DELETE FROM participa;DELETE FROM recorrido;DELETE FROM incidencia');
            $d=$this->get()['data'];$this->assertSame(0,array_sum($d['solicitudes']['estados_actuales']));$this->assertSame(0,array_sum($d['atencion']['solicitudes']['hitos_periodo']));$this->assertSame(['muestras'=>0,'promedio_segundos'=>null],$d['atencion']['solicitudes']['duracion_atencion']['finalizados']);$this->assertSame(0,$d['recorridos']['iniciados']);
        } finally {date_default_timezone_set($previous);}
    }
    public function testExistingDashboardAndIncidenceReportRegression(): void {
        $dashboard=(new Dashboard($this->db))->consultar('2026-10-01','2026-10-01');$this->assertSame(1,$dashboard['periodo_resultados']['recorridos_finalizados']);$this->assertSame(1,$dashboard['periodo_resultados']['contenedores_atendidos']);
        $report=(new Incidencia($this->db))->report('todos',null,null,1,20);$this->assertSame(1,$report['totals']['total']);$this->assertSame(1,$report['totals']['abiertas']);
    }
    public function testHttpContractHeadersAndSafeErrors(): void {
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);
        $environment=getenv();$environment['ZEMYNA_F7_HTTP_DATABASE']=$this->name;
        $process=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/fixtures/f7_http_router.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$environment);
        $this->assertIsResource($process);fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        try {
            $ready=false;$deadline=microtime(true)+8;
            do {$probe=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($probe){fclose($probe);$ready=true;break;}usleep(20000);}while(microtime(true)<$deadline);
            $this->assertTrue($ready);
            foreach ([['',1,'GET',200],['',0,'GET',401],['',4,'GET',403],['?desde=invalid',1,'GET',400],['',1,'PUT',405],['',0,'OPTIONS',405]] as [$query,$actor,$method,$code]) {
                $context=stream_context_create(['http'=>['method'=>$method,'header'=>'X-Test-Actor: '.$actor,'ignore_errors'=>true,'timeout'=>10]]);
                $raw=file_get_contents('http://'.$address.'/'.$query,false,$context);
                $this->assertStringContainsString(' '.$code.' ',$http_response_header[0]);$this->assertContains('Cache-Control: no-store',$http_response_header);
                $this->assertContains('Content-Type: application/json; charset=UTF-8',$http_response_header);
                $this->assertNotContains('Access-Control-Allow-Origin: *',$http_response_header);
                if($code===405)$this->assertContains('Allow: GET',$http_response_header);
                $r=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$this->assertSame($code,$r['statusCode']);$this->assertSame($code===200,$r['success']);
                if($code!==200)$this->assertNull($r['data']);$this->assertStringNotContainsString('SQL', $raw);
            }
        } finally {proc_terminate($process);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);}
    }
}
