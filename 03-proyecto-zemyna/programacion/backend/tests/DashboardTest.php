<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../controllers/DashboardController.php';

final class DashboardTest extends TestCase
{
    private PDO $db;
    private array $session;
    private bool $started = false;
    private string $savePath;
    protected function setUp(): void
    {
        $this->savePath = session_save_path();
        if (session_status() !== PHP_SESSION_ACTIVE) { session_save_path(sys_get_temp_dir()); session_start(); $this->started = true; }
        $this->session = $_SESSION ?? []; $_SESSION = ['usuario' => ['id_usuario' => 1]];
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->sqliteCreateFunction('CURDATE', fn() => date('Y-m-d'));
        $this->db->exec("CREATE TABLE usuario(id_usuario INTEGER,activo TEXT);
            CREATE TABLE rol(id_rol INTEGER,nombre TEXT,descripcion TEXT);
            CREATE TABLE usuario_rol(id_usuario_rol INTEGER,id_usuario INTEGER,id_rol INTEGER,sector TEXT,fecha_desde TEXT,fecha_hasta TEXT);
            CREATE TABLE permiso(id_permiso INTEGER,nombre TEXT); CREATE TABLE rol_permiso(id_rol INTEGER,id_permiso INTEGER);
            INSERT INTO usuario VALUES(1,'Activo'); INSERT INTO rol VALUES(1,'GESTOR','Configurado por permisos');
            INSERT INTO usuario_rol VALUES(1,1,1,'OPERACIONES','2020-01-01',NULL);
            CREATE TABLE incidencia(id_incidencia INTEGER PRIMARY KEY,fecha_reporte TEXT,fecha_resolucion TEXT,estado TEXT,prioridad TEXT,id_contenedor INTEGER,descripcion TEXT DEFAULT 'privado',tracking_number TEXT DEFAULT 'privado',id_usuario INTEGER DEFAULT 1,latitud REAL DEFAULT 10);
            CREATE TABLE contenedor(id_contenedor INTEGER PRIMARY KEY,codigo TEXT,direccion TEXT);
            CREATE TABLE recorrido(id_recorrido INTEGER PRIMARY KEY,fecha_inicio TEXT,fecha_fin TEXT,estado TEXT);
            CREATE TABLE cuadrilla(id_cuadrilla INTEGER PRIMARY KEY);
            CREATE TABLE vehiculo(id_vehiculo INTEGER PRIMARY KEY,activo INTEGER,estado TEXT);
            CREATE TABLE usa(id_usa INTEGER PRIMARY KEY,id_vehiculo INTEGER,id_cuadrilla INTEGER);
            CREATE TABLE participa(id_participa INTEGER PRIMARY KEY,id_usa INTEGER,id_recorrido INTEGER);
            CREATE TABLE atencion_contenedor(id_recorrido INTEGER,id_contenedor INTEGER,fecha_atencion TEXT,UNIQUE(id_recorrido,id_contenedor));");
        foreach (['incidencia.consultar','recorrido.consultar','cuadrilla.consultar','vehiculo.consultar','contenedor.consultar'] as $i => $p) {
            $stmt=$this->db->prepare('INSERT INTO permiso VALUES(?,?)');$stmt->execute([$i+1,$p]);$this->db->exec('INSERT INTO rol_permiso VALUES(1,'.($i+1).')');
        }
    }
    protected function tearDown(): void { $_SESSION=$this->session; if($this->started){session_destroy();session_save_path($this->savePath);} }
    private function get(array $query=[]): array { return (new DashboardController($this->db))->consultar($query); }
    private function fixture(): void
    {
        $this->db->exec("INSERT INTO contenedor VALUES(1,'C01','Referencia pública');
            INSERT INTO incidencia(id_incidencia,fecha_reporte,fecha_resolucion,estado,prioridad,id_contenedor) VALUES
            (1,'2026-01-01 00:00:00',NULL,'Pendiente','Alta',1),
            (2,'2026-01-01 23:59:59',NULL,'En Proceso','Media',1),
            (3,'2025-12-31 23:00:00','2026-01-01 01:00:00','Resuelta','Baja',1),
            (4,'2026-01-01 08:00:00','2026-01-01 12:00:00','Resuelta','Alta',NULL),
            (5,'2026-01-02 00:00:00',NULL,'Resuelta','Media',NULL),
            (6,'2026-01-02 00:00:00',NULL,'Pendiente','Baja',1);
            INSERT INTO recorrido VALUES(1,'2026-01-01 08:00:00',NULL,'En Proceso'),(2,'2026-01-01 09:00:00','2026-01-02 00:00:00','Finalizado'),(3,'2026-01-02 08:00:00',NULL,'Pendiente'),(4,'2026-01-03 08:00:00','2026-01-03 09:00:00','Finalizado');
            INSERT INTO cuadrilla VALUES(1),(2),(3);
            INSERT INTO vehiculo VALUES(1,1,'Disponible'),(2,1,'En Mantenimiento'),(3,1,'Disponible'),(4,0,'En Mantenimiento');
            INSERT INTO usa VALUES(1,1,1),(2,2,2),(3,4,1),(4,1,2);
            INSERT INTO participa VALUES(1,1,1),(2,4,1),(3,2,3),(4,3,1);
            INSERT INTO atencion_contenedor VALUES(1,1,'2026-01-01 23:59:59'),(2,1,'2026-01-02 00:00:00');");
    }
    public function testSesionPermisosVigentesYCuentaActiva(): void
    {
        $_SESSION=[];$this->assertSame(401,$this->get()['statusCode']);$_SESSION=['usuario'=>['id_usuario'=>1]];
        $this->assertSame(200,$this->get(['view'=>'permisos'])['statusCode']);
        $this->db->exec('DELETE FROM rol_permiso WHERE id_permiso=1');$this->assertSame(403,$this->get()['statusCode']);
        $this->db->exec("UPDATE usuario SET activo='Inactivo'");$this->assertSame(401,$this->get()['statusCode']);
    }
    public function testSectoresNoAmplianAccesoNiAdmitenIdentidadDelCliente(): void
    {
        $this->assertSame(403,$this->get(['sector'=>'INSPECCION'])['statusCode']);
        $this->assertSame(200,$this->get(['sector'=>'OPERACIONES'])['statusCode']);
        foreach(['id_usuario','id_cuadrilla','page','t','accion'] as $field)$this->assertSame(400,$this->get([$field=>'1'])['statusCode']);
        $this->db->exec("UPDATE usuario_rol SET sector='INSPECCION'");$this->assertSame(403,$this->get()['statusCode']);
        $this->db->exec("UPDATE usuario_rol SET sector='OPERACIONES',fecha_hasta='2020-01-02'");$this->assertSame(403,$this->get()['statusCode']);
    }
    public function testVacioDistingueCeroYPromedioAusente(): void
    {
        $r=$this->get();$this->assertSame(200,$r['statusCode']);$d=$r['data'];
        $this->assertSame('Todos los registros',$d['periodo']['descripcion']);foreach($d['actual'] as $value)$this->assertSame(0,$value);
        $this->assertSame(0,$d['periodo_resultados']['incidencias_reportadas']);$this->assertNull($d['periodo_resultados']['tiempo_promedio_resolucion_horas']);
        $this->assertNull($d['recorridos']['porcentaje_avance']);$this->assertSame([],$d['requiere_atencion']);$this->assertSame([],$d['contenedores_problematicos']);
    }
    public function testFechasInclusivasUtilizanColumnaCorrespondienteSinMezclarEstadoActual(): void
    {
        $this->fixture();$all=$this->get()['data'];$d=$this->get(['fecha_desde'=>'2026-01-01','fecha_hasta'=>'2026-01-01'])['data'];
        $this->assertSame($all['actual'],$d['actual']);$this->assertSame(3,$d['periodo_resultados']['incidencias_reportadas']);
        $this->assertSame(2,$d['periodo_resultados']['incidencias_resueltas']);$this->assertSame(3.0,$d['periodo_resultados']['tiempo_promedio_resolucion_horas']);
        $this->assertSame(0,$d['periodo_resultados']['recorridos_finalizados']);$this->assertSame(1,$d['periodo_resultados']['contenedores_atendidos']);
        $this->assertSame(1,$d['recorridos']['por_estado']['Finalizado']);$this->assertSame(1,$d['recorridos']['por_estado']['En Proceso']);
        $this->assertSame(5,$this->get(['fecha_desde'=>'2026-01-01'])['data']['periodo_resultados']['incidencias_reportadas']);
        $this->assertSame(4,$this->get(['fecha_hasta'=>'2026-01-01'])['data']['periodo_resultados']['incidencias_reportadas']);
    }
    public function testValidaFechasArraysRangosYMetodo(): void
    {
        foreach(['2026-02-30','2026-2-01','0000-01-01','9999-12-31',[], '2026-01-01 OR 1=1'] as $date)$this->assertSame(400,$this->get(['fecha_desde'=>$date])['statusCode']);
        $this->assertSame(400,$this->get(['fecha_desde'=>'2026-01-02','fecha_hasta'=>'2026-01-01'])['statusCode']);
        $this->assertSame(200,$this->get(['fecha_desde'=>'2024-02-29','fecha_hasta'=>'2024-02-29'])['statusCode']);
        $this->assertSame(405,(new DashboardController($this->db))->consultar([],'POST')['statusCode']);
        $this->assertSame(400,$this->get(['view'=>'otro'])['statusCode']);
    }
    public function testHistoricosSinFechaYSinContenedorConservanTotalesYPrivacidad(): void
    {
        $this->fixture();$d=$this->get()['data'];
        $this->assertSame(6,$d['periodo_resultados']['incidencias_reportadas']);$this->assertSame(3,$d['periodo_resultados']['incidencias_resueltas']);
        $this->assertSame(1,$d['historicas_sin_fecha_resolucion']);$this->assertSame(2,$d['periodo_resultados']['resoluciones_con_duracion']);
        $this->assertSame(6,array_sum(array_column($d['incidencias_por_estado_prioridad'],'cantidad')));
        $this->assertSame(4,$d['contenedores_problematicos'][0]['cantidad']);
        foreach(['tracking_number','id_usuario','latitud','email','telefono','privado'] as $field)$this->assertStringNotContainsString($field,json_encode($d));
        $this->assertSame(['codigo','direccion','cantidad'],array_keys($d['contenedores_problematicos'][0]));
    }
    public function testTopCincoDesempateEstableYTotalesSinPaginar(): void
    {
        for($c=1;$c<=7;$c++) {
            $this->db->exec("INSERT INTO contenedor VALUES($c,'C0$c','Referencia')");
            for($i=1;$i<=10;$i++)$this->db->exec("INSERT INTO incidencia(fecha_reporte,estado,prioridad,id_contenedor) VALUES('2026-01-01','Pendiente','Baja',$c)");
        }
        $d=$this->get()['data'];$this->assertSame(70,$d['periodo_resultados']['incidencias_reportadas']);$this->assertCount(5,$d['contenedores_problematicos']);
        $this->assertSame(['C01','C02','C03','C04','C05'],array_column($d['contenedores_problematicos'],'codigo'));
    }
    public function testFlotaRelacionesDuplicadasYAtencionesNoInflanTotales(): void
    {
        $this->fixture();$d=$this->get()['data'];
        $this->assertSame(1,$d['actual']['camiones_disponibles']);$this->assertSame(2,$d['actual']['camiones_no_disponibles']);
        $this->assertSame(1,$d['actual']['cuadrillas_sin_recorrido']);$this->assertSame(1,$d['actual']['contenedores_prioridad_alta']);
        $this->assertSame(2,$d['periodo_resultados']['contenedores_atendidos']);
        $this->db->exec('INSERT INTO participa VALUES(5,1,1)');$this->assertSame($d['actual'],$this->get()['data']['actual']);
        try{$this->db->exec("INSERT INTO atencion_contenedor VALUES(1,1,'2026-01-01 12:00:00')");$this->fail('Atención duplicada');}catch(PDOException $e){$this->assertSame('23000',$e->getCode());}
    }
    public function testAtencionDerivadaYAvanceHistoricoNoInventado(): void
    {
        $this->fixture();$d=$this->get()['data'];$counts=array_column($d['requiere_atencion'],'cantidad','tipo');
        $this->assertSame(['alta'=>1,'reiteradas'=>1,'sin_recorrido'=>1,'sin_vehiculo'=>1,'vehiculo_invalido'=>2,'compartido'=>1],$counts);
        $this->assertNull($d['recorridos']['contenedores_esperados']);$this->assertNull($d['recorridos']['contenedores_pendientes']);
        $this->assertSame(2,$d['periodo_resultados']['recorridos_finalizados']);
        $this->assertNull($d['recorridos']['porcentaje_avance']); // Incluye finalizado sin contenedores y otro con atención parcial; sin instantánea no se infiere un objetivo.
    }
    public function testErroresControladosSinSQLYPromedioExcluyeDuracionInvalida(): void
    {
        $this->fixture();$this->db->exec("UPDATE incidencia SET fecha_resolucion='2020-01-01' WHERE id_incidencia IN (3,4)");
        $this->assertNull($this->get()['data']['periodo_resultados']['tiempo_promedio_resolucion_horas']);
        $this->db->exec('DROP TABLE recorrido');$r=$this->get();$this->assertSame(503,$r['statusCode']);$this->assertStringNotContainsString('SELECT',json_encode($r));
        $this->assertFalse($this->db->inTransaction());
    }
}
