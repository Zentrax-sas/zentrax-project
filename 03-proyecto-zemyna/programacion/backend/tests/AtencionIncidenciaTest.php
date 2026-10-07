<?php
require_once __DIR__ . '/fixtures/asignacion_vehiculo.php';
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../controllers/RecoleccionController.php';
require_once __DIR__ . '/../controllers/IncidenciaController.php';
require_once __DIR__ . '/../models/Dashboard.php';
require_once __DIR__ . '/fixtures/atencion_incidencia.php';

/** SQL real en memoria; carreras MariaDB se verifican por separado. */
final class AtencionIncidenciaTest extends TestCase
{
    private PDO $db;
    private array $previous;
    private bool $started = false;
    private string $savePath;
    protected function setUp(): void
    {
        $this->savePath = session_save_path();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir()); session_start(); $this->started = true;
        }
        $this->previous = $_SESSION ?? [];
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        createAsignacionVehiculoFixture($this->db);
        $this->db->exec("CREATE TABLE usuario (id_usuario INTEGER PRIMARY KEY, activo TEXT, nombre TEXT, apellido TEXT);
            CREATE TABLE cuadrilla (id_cuadrilla INTEGER PRIMARY KEY, nombre TEXT, turno TEXT);
            CREATE TABLE usuario_cuadrilla (id_usuario_cuadrilla INTEGER PRIMARY KEY, id_usuario INTEGER, id_cuadrilla INTEGER, fecha_inicio TEXT, fecha_fin TEXT);
            CREATE UNIQUE INDEX uq_membership ON usuario_cuadrilla(id_usuario) WHERE fecha_fin IS NULL;
            CREATE TABLE usuario_rol (id_usuario INTEGER, id_rol INTEGER, sector TEXT, fecha_desde TEXT, fecha_hasta TEXT);
            CREATE TABLE rol_permiso (id_rol INTEGER, id_permiso INTEGER);
            CREATE TABLE permiso (id_permiso INTEGER, nombre TEXT);
            CREATE TABLE ruta (id_ruta INTEGER PRIMARY KEY, nombre TEXT, zona TEXT);
            CREATE TABLE contenedor (id_contenedor INTEGER PRIMARY KEY, codigo TEXT, direccion TEXT, id_ruta INTEGER, latitud REAL, longitud REAL, activo INTEGER);
            CREATE TABLE incidencia (id_incidencia INTEGER PRIMARY KEY AUTOINCREMENT, tracking_number TEXT UNIQUE,
                estado TEXT, prioridad TEXT, tipo_problema TEXT, descripcion TEXT, fecha_reporte TEXT, fecha_resolucion TEXT,
                id_contenedor INTEGER, id_ruta INTEGER, id_cuadrilla INTEGER, id_usuario INTEGER, latitud REAL, longitud REAL);
            CREATE TABLE foto (id_foto INTEGER, fecha TEXT, id_incidencia INTEGER);
            INSERT INTO usuario VALUES (1,'Activo','Ana','Operaria'),(2,'Activo','Otra','Persona'),(3,'Inactivo','Inactivo','Test'),(4,'Activo','Sin','Cuadrilla'),(5,'Activo','Admin','TI');
            INSERT INTO cuadrilla VALUES (1,'Primera','Matutino'),(2,'Segunda','Nocturno');
            INSERT INTO usuario_cuadrilla VALUES (1,1,1,'2020-01-01',NULL),(2,2,2,'2020-01-01',NULL),(3,3,1,'2020-01-01',NULL),(5,5,1,'2020-01-01',NULL);
            INSERT INTO permiso VALUES (1,'recorrido.consultar'),(2,'recorrido.operar');
            INSERT INTO rol_permiso VALUES (1,1),(1,2);
            INSERT INTO usuario_rol VALUES (1,1,'OPERACIONES','2020-01-01',NULL),(2,1,'OPERACIONES','2020-01-01',NULL),
                (3,1,'OPERACIONES','2020-01-01',NULL),(4,1,'OPERACIONES','2020-01-01',NULL),(5,1,'TI','2020-01-01',NULL);
            INSERT INTO ruta VALUES (1,'Ruta del contenedor','Centro'),(2,'Ruta directa','Otra zona');
            INSERT INTO contenedor VALUES (1,'C-001','Calle de prueba 123',1,-34.9,-56.1,1);
            INSERT INTO incidencia VALUES
                (1,'INC-2026-00001','Pendiente','Alta','Contenedor Desbordado','Reporte propio','2026-09-01 08:00:00',NULL,1,NULL,1,2,NULL,NULL),
                (2,'INC-2026-00002','En Proceso','Media','Contenedor Desbordado','Otro trabajo propio','2026-09-02 08:00:00',NULL,1,NULL,1,NULL,NULL,NULL),
                (3,'INC-2026-00003','Resuelta','Alta','Contenedor Desbordado','Trabajo resuelto','2026-09-01 09:00:00','2026-09-03 12:00:00',1,NULL,1,NULL,NULL,NULL),
                (4,'INC-2026-00004','Pendiente','Alta','Contenedor Desbordado','Reporte ajeno','2026-08-01 08:00:00',NULL,1,NULL,2,2,NULL,NULL),
                (5,'INC-2026-00005','Pendiente','Alta','Contenedor Desbordado','Sin asignar','2026-08-01 08:00:00',NULL,1,NULL,NULL,NULL,NULL,NULL);");
        createAttentionFixture($this->db);
        $this->db->exec("PRAGMA foreign_keys=ON;
            INSERT INTO permiso VALUES(3,'incidencia.operar'),(4,'incidencia.consultar'),(5,'incidencia.modificar'); INSERT INTO rol_permiso VALUES(1,3),(1,4),(1,5);
            CREATE TABLE vehiculo(id_vehiculo INTEGER PRIMARY KEY,matricula TEXT,activo INTEGER,estado TEXT,funcion_operativa TEXT);
            CREATE TABLE usa(id_usa INTEGER PRIMARY KEY,id_cuadrilla INTEGER,id_vehiculo INTEGER);
            CREATE TABLE recorrido(id_recorrido INTEGER PRIMARY KEY,id_ruta INTEGER,estado TEXT,fecha_inicio TEXT,fecha_fin TEXT);
            CREATE TABLE participa(id_participa INTEGER PRIMARY KEY,id_usa INTEGER,id_recorrido INTEGER,hora_fin TEXT);
            CREATE TABLE atencion_contenedor(id_recorrido INTEGER,id_contenedor INTEGER,fecha_atencion TEXT);
            INSERT INTO vehiculo VALUES(1,'TEST1',1,'Disponible','REGULAR'),(2,'TEST2',1,'Disponible','REGULAR');
            INSERT INTO asignacion_vehiculo_operativa(id_asignacion_vehiculo,id_cuadrilla,id_vehiculo,fecha_inicio,id_usuario_asigna) VALUES(1,1,1,'2020-01-01',1),(2,2,2,'2020-01-01',2);
            INSERT INTO usa VALUES(1,1,1),(2,2,2);
            INSERT INTO recorrido VALUES(1,1,'Pendiente','2026-09-01',NULL),(2,2,'Pendiente','2026-09-01',NULL);
            INSERT INTO participa VALUES(1,1,1,NULL),(2,2,2,NULL);
            INSERT INTO atencion_incidencia(id_incidencia,id_cuadrilla,estado,origen,fecha_registro)
                SELECT id_incidencia,id_cuadrilla,'Asignada','Migracion','2026-09-01 00:00:00'
                FROM incidencia WHERE estado<>'Resuelta' AND id_cuadrilla IS NOT NULL;");
        $this->user();
    }
    protected function tearDown(): void {
        $_SESSION = $this->previous;
        if ($this->started) { session_destroy(); session_save_path($this->savePath); }
    }
    private function user(int $id = 1): void {
        $_SESSION = ['usuario'=>['id_usuario'=>$id,'roles'=>['ADMINISTRADOR_TI'],
            'autorizaciones'=>array_map(fn($p)=>['permiso'=>$p,'sector'=>'OPERACIONES'], ['recorrido.consultar','recorrido.operar','incidencia.operar'])]];
    }
    private function row(int $id = 1): array { return $this->db->query('SELECT * FROM incidencia WHERE id_incidencia='.$id)->fetch(PDO::FETCH_ASSOC); }
    private function history(int $id = 1): array { return (new AtencionIncidencia($this->db))->history($id); }
    private function act(string $action, string $expected = 'Asignada', array $extra = []): array {
        $id = $extra['id_incidencia'] ?? 1;
        $attempt = $this->history((int)$id)[0]['id_atencion_incidencia'] ?? 999;
        return (new RecoleccionController($this->db))->modificar(array_replace([
            'accion'=>$action,'id_incidencia'=>$id,'id_atencion_incidencia'=>$attempt,'estado_operativo_esperado'=>$expected], $extra));
    }
    private function admin(array $change): array { return (new IncidenciaController($this->db))->updateAdministrative(['id_incidencia'=>1]+$change); }
    private function assign(?int $squad, ?int $expected): array {
        $support=$squad!==null && $this->db->query('SELECT funcion_operativa FROM vehiculo WHERE id_vehiculo='.(int)$squad)->fetchColumn()==='APOYO';
        return (new IncidenciaController($this->db))->assignAdministrative(['accion'=>'asignar','id_incidencia'=>1,
            'id_cuadrilla'=>$squad,'id_asignacion_vehiculo'=>$squad,'id_recorrido'=>$support?null:$squad,'id_usa'=>$squad,'cuadrilla_esperada'=>$expected,'estado_esperado'=>$this->row()['estado']]);
    }
    public function testApoyoAsignadoPorF3SinRecorridoSeOperaPorCuadrilla(): void {
        $this->assertSame(200,$this->assign(null,1)['statusCode']);
        $this->db->exec("DELETE FROM participa; DELETE FROM recorrido; UPDATE vehiculo SET funcion_operativa='APOYO'");
        $this->assertSame(200,$this->assign(1,null)['statusCode']);
        $this->assertSame(200,$this->act('aceptar_incidencia')['statusCode']);
        $this->assertSame(200,$this->act('iniciar_atencion_incidencia','Aceptada')['statusCode']);
        $this->assertSame(200,$this->act('finalizar_atencion_incidencia','En atención')['statusCode']);
        $this->assertSame('Resuelta',$this->row()['estado']);
        $this->assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM recorrido')->fetchColumn());
    }
    private function own(array $query=[]): array { return (new RecoleccionController($this->db))->consultar(['view'=>'incidencias_propias']+$query); }
    public function testContextoRegularPropioYRecorridoAmbiguo(): void {
        $context = $this->own()['contexto_operativo'];
        $this->assertTrue($context['puede_operar_incidencias']);
        $this->assertSame('REGULAR', $context['funcion_operativa']);
        $this->assertSame(1, $context['recorrido']['id_recorrido']);
        $this->assertSame('vigente', $context['estado_recorrido']);
        $this->db->exec('INSERT INTO participa VALUES(3,2,1,NULL)');
        $context = $this->own()['contexto_operativo'];
        $this->assertNull($context['recorrido']);
        $this->assertSame('ambiguo', $context['estado_recorrido']);
        $this->assertNotEmpty($this->own()['data']);
    }

    public function testCicloConAutoresServidorResolucionYConsumidores(): void {
        $before = (new Dashboard($this->db))->consultar(null,null);
        $trips = $this->db->query('SELECT * FROM recorrido')->fetchAll(PDO::FETCH_ASSOC);
        $start = AtencionIncidencia::now();
        $this->assertSame(200,$this->act('aceptar_incidencia')['statusCode']);
        $this->assertSame('Pendiente',$this->row()['estado']);
        $this->assertSame(1,$this->history()[0]['id_usuario_acepta']);
        $this->assertGreaterThanOrEqual($start,$this->history()[0]['fecha_aceptacion']);
        $this->assertLessThanOrEqual(AtencionIncidencia::now(),$this->history()[0]['fecha_aceptacion']);
        $this->assertSame(200,$this->act('iniciar_atencion_incidencia','Aceptada')['statusCode']);
        $this->assertSame('En Proceso',$this->row()['estado']);
        $this->assertSame(1,$this->history()[0]['id_usuario_inicia']);
        $this->assertSame(409,$this->act('iniciar_atencion_incidencia','Aceptada')['statusCode']);
        $this->assertSame(200,$this->act('finalizar_atencion_incidencia','En atención')['statusCode']);
        $this->assertSame('Resuelta',$this->row()['estado']);
        $closed = $this->history()[0];
        $this->assertSame(1,$closed['id_usuario_cierra']);
        $this->assertSame('Finalizada',$closed['estado']);
        $this->assertSame($closed['fecha_cierre'],$this->row()['fecha_resolucion']);
        $this->assertSame(409,$this->act('finalizar_atencion_incidencia','En atención')['statusCode']);
        $this->assertSame($closed,$this->history()[0]);
        $this->assertSame($closed['fecha_cierre'],$this->row()['fecha_resolucion']);
        $this->assertNotContains(1,array_column($this->own()['data'],'id_incidencia'));
        $this->assertSame('Finalizada',$this->own(['id_incidencia'=>1])['data'][0]['atencion']['estado']);
        $admin = new IncidenciaController($this->db);
        $public = $admin->getPublicByTracking('INC-2026-00001');
        $this->assertSame('Resuelta',$public['data']['estado']);
        $this->assertArrayNotHasKey('atenciones',$public['data']);
        $model = new Incidencia($this->db);
        $this->assertContains(1,array_column($model->report('cerradas',null,null,1,20)['data'],'id_incidencia'));
        foreach ([false,true] as $administrative) {
            $rows=$model->readForMap(-35,-34,-57,-55,100,null,null,true,$administrative)->fetchAll(PDO::FETCH_ASSOC);
            $this->assertNotContains(1,array_column($rows,'id_incidencia'));
        }
        $after = (new Dashboard($this->db))->consultar(null,null);
        $this->assertSame($before['actual']['incidencias_activas']-1,$after['actual']['incidencias_activas']);
        $this->assertSame($before['periodo_resultados']['incidencias_resueltas']+1,$after['periodo_resultados']['incidencias_resueltas']);
        $this->assertSame($trips,$this->db->query('SELECT * FROM recorrido')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testRechazoDesasignaConservaEstadoYPermiteOtroIntentoF3(): void {
        $this->db->exec("UPDATE incidencia SET estado='En Proceso' WHERE id_incidencia=1");
        $old = $this->history()[0]['id_atencion_incidencia'];
        $this->assertSame(200,$this->act('rechazar_incidencia','Asignada',['motivo'=>'  Falta equipamiento  '])['statusCode']);
        $this->assertSame('En Proceso',$this->row()['estado']);
        $this->assertNull($this->row()['fecha_resolucion']); $this->assertNull($this->row()['id_cuadrilla']);
        $rejected=$this->history()[0];
        $this->assertSame('Falta equipamiento',$rejected['motivo_cierre']);
        $this->assertSame('Rechazada',$rejected['estado']); $this->assertSame(1,$rejected['id_usuario_cierra']);
        $this->assertNotContains(1,array_column($this->own()['data'],'id_incidencia'));
        $this->assertSame(404,$this->own(['id_incidencia'=>1])['statusCode']);
        $this->assertSame(404,$this->act('aceptar_incidencia')['statusCode']);
        $this->assertSame(200,$this->assign(1,null)['statusCode']);
        $this->assertCount(2,$this->history());
        $this->assertSame($rejected,$this->history()[1]);
        $this->assertSame(409,$this->act('aceptar_incidencia','Asignada',['id_atencion_incidencia'=>$old])['statusCode']);
        $this->assertSame(200,$this->act('aceptar_incidencia')['statusCode']);
        $this->assertSame('En Proceso',$this->row()['estado']);
        $detail=(new IncidenciaController($this->db))->getAll(['id'=>1]);
        $this->assertSame('Falta equipamiento',$detail['data'][0]['atenciones'][1]['motivo_cierre']);
    }

    /** @dataProvider invalidReasons */
    public function testMotivoObligatorio(array $extra): void {
        $before=$this->history();
        $this->assertSame(400,$this->act('rechazar_incidencia','Asignada',$extra)['statusCode']);
        $this->assertSame($before,$this->history()); $this->assertSame(1,$this->row()['id_cuadrilla']);
    }
    public static function invalidReasons(): array { return [[[]],[['motivo'=>'']],[['motivo'=>'  ']],[['motivo'=>str_repeat('á',501)]],[['motivo'=>[]]]]; }

    /** @dataProvider deniedActions */
    public function testSeguridadVigente(string $sql, int $user, int $status): void {
        if ($sql !== '') $this->db->exec($sql);
        $this->user($user); $before=$this->history();
        $this->assertSame($status,$this->act('aceptar_incidencia')['statusCode']);
        $this->assertSame($before,$this->history()); $this->assertFalse($this->db->inTransaction());
    }
    public static function deniedActions(): array {
        return [
            ['',3,401], ['',4,409], ['',2,404], ['',5,403],
            ["DELETE FROM rol_permiso WHERE id_permiso=3",1,403],
            ["DELETE FROM rol_permiso WHERE id_permiso=2",1,403],
            ["UPDATE usuario_rol SET fecha_hasta='2020-01-02' WHERE id_usuario=1",1,403],
            ["UPDATE usuario_rol SET sector='TI' WHERE id_usuario=1",1,403],
            ["UPDATE usuario_cuadrilla SET fecha_fin='2026-09-02' WHERE id_usuario=1",1,409],
            ["UPDATE usuario_cuadrilla SET id_cuadrilla=2 WHERE id_usuario=1",1,404],
            ["UPDATE incidencia SET estado='Resuelta' WHERE id_incidencia=1",1,409]
        ];
    }

    public function testSinSesionEIdorIndistinguible(): void {
        $_SESSION=[]; $this->assertSame(401,$this->act('aceptar_incidencia')['statusCode']);
        $this->user();
        $responses=[];
        foreach ([4,5,999] as $id) $responses[]=$this->act('aceptar_incidencia','Asignada',['id_incidencia'=>$id]);
        $this->assertSame(404,$responses[0]['statusCode']);
        $this->assertSame($responses[0],$responses[1]); $this->assertSame($responses[0],$responses[2]);
    }

    /** @dataProvider manipulations */
    public function testRechazaDatosDelCliente(array $extra): void {
        $before=$this->history();
        $this->assertSame(400,$this->act('aceptar_incidencia','Asignada',$extra)['statusCode']);
        $this->assertSame($before,$this->history());
    }
    public static function manipulations(): array {
        return array_map(fn($x)=>[$x],[['id_usuario'=>2],['id_cuadrilla'=>2],['autor'=>2],['fecha'=>'2026-01-01'],
            ['fecha_aceptacion'=>'2026-01-01'],['estado'=>'Resuelta'],['id_incidencia'=>0],['id_atencion_incidencia'=>[]],
            ['estado_operativo_esperado'=>[]],['motivo'=>'No corresponde'],['id_incidencia'=>true],['id_incidencia'=>1.0]]);
    }

    public function testConflictosNoRepitenEfectos(): void {
        $this->assertSame(409,$this->act('iniciar_atencion_incidencia','Asignada')['statusCode']);
        $this->assertSame(409,$this->act('finalizar_atencion_incidencia','Asignada')['statusCode']);
        $this->assertSame(200,$this->act('aceptar_incidencia')['statusCode']);
        $accepted=$this->history();
        $this->assertSame(409,$this->act('aceptar_incidencia')['statusCode']);
        $this->assertSame(409,$this->act('rechazar_incidencia','Asignada',['motivo'=>'Tardío'])['statusCode']);
        $this->assertSame(409,$this->act('finalizar_atencion_incidencia','Aceptada')['statusCode']);
        $this->assertSame($accepted,$this->history());
    }

    public function testReasignacionABANoReutilizaIntento(): void {
        $old=$this->history()[0]['id_atencion_incidencia'];
        $this->assertSame(200,$this->assign(2,1)['statusCode']);
        $this->assertSame(404,$this->act('aceptar_incidencia','Asignada',['id_atencion_incidencia'=>$old])['statusCode']);
        $this->assertSame(200,$this->assign(1,2)['statusCode']);
        $this->assertCount(3,$this->history());
        $this->assertSame(409,$this->act('aceptar_incidencia','Asignada',['id_atencion_incidencia'=>$old])['statusCode']);
        $this->assertSame(['Asignada','Interrumpida','Interrumpida'],array_column($this->history(),'estado'));
        $this->assertSame(200,$this->act('aceptar_incidencia')['statusCode']);
        $this->assertSame(200,$this->assign(null,1)['statusCode']);
        $this->assertSame('Desasignación',$this->history()[0]['motivo_cierre']);
    }

    public function testResolucionReaperturaReinicioYEliminacionAdministrativos(): void {
        $this->assertSame(200,$this->act('aceptar_incidencia')['statusCode']);
        $this->assertSame(200,$this->act('iniciar_atencion_incidencia','Aceptada')['statusCode']);
        $this->assertSame(200,$this->admin(['estado'=>'Pendiente'])['statusCode']);
        $this->assertSame('Reinicio administrativo',$this->history()[0]['origen']);
        $this->assertSame('Interrumpida',$this->history()[1]['estado']);
        $this->assertSame(200,$this->admin(['estado'=>'Resuelta'])['statusCode']);
        $this->assertSame('Resolución administrativa',$this->history()[0]['motivo_cierre']);
        $date=$this->row()['fecha_resolucion']; $this->assertNotNull($date);
        $closed=$this->history();
        $this->assertSame(200,$this->admin(['estado'=>'Resuelta'])['statusCode']);
        $this->assertSame($closed,$this->history()); $this->assertSame($date,$this->row()['fecha_resolucion']);
        $this->assertSame(200,$this->admin(['estado'=>'En Proceso'])['statusCode']);
        $this->assertNull($this->row()['fecha_resolucion']);
        $this->assertSame('Reapertura',$this->history()[0]['origen']);
        $this->assertSame('Asignada',$this->history()[0]['estado']);
        $this->assertSame(409,(new IncidenciaController($this->db))->delete(1)['statusCode']);
        $this->assertSame(200,(new IncidenciaController($this->db))->delete(5)['statusCode']);
    }

    public function testActualizacionLegacyConservaHistoria(): void {
        $admin=new IncidenciaController($this->db);
        $detail=$admin->getAll(['id'=>1])['data'][0]; $detail['estado']='Resuelta';
        $this->assertSame(200,$admin->updateAdministrative($detail)['statusCode']);
        $this->assertSame('Interrumpida',$this->history()[0]['estado']);
        $this->assertSame(1,$this->history()[0]['id_usuario_cierra']);
    }

    public function testRollbackDeTransicionYAsignacion(): void {
        $before=$this->history();
        $this->db->exec("CREATE TRIGGER fail_inc BEFORE UPDATE ON incidencia BEGIN SELECT RAISE(ABORT,'private SQL'); END");
        $response=$this->act('rechazar_incidencia','Asignada',['motivo'=>'Equipo']);
        $this->assertSame(503,$response['statusCode']);
        $this->assertStringNotContainsString('private SQL',json_encode($response));
        $this->assertSame($before,$this->history()); $this->assertSame(1,$this->row()['id_cuadrilla']);
        $this->assertSame(500,$this->assign(2,1)['statusCode']);
        $this->assertSame($before,$this->history());
        $this->assertSame(500,$this->admin(['estado'=>'Resuelta'])['statusCode']);
        $this->assertSame($before,$this->history()); $this->assertFalse($this->db->inTransaction());
    }

    public function testDosAbiertasNoPermitidasYConsultaNoDuplica(): void {
        $this->expectException(PDOException::class);
        $this->assertCount(2,$this->own()['data']);
        $this->db->exec("INSERT INTO atencion_incidencia(id_incidencia,id_cuadrilla,estado,origen,fecha_registro)
            VALUES(1,1,'Asignada','Migracion','2026-09-01')");
    }

    public function testOtroIntegranteActualPuedeContinuarSinRecorrido(): void {
        $this->db->exec('DROP TABLE participa; DROP TABLE recorrido; DROP TABLE usa; DROP TABLE vehiculo');
        $this->assertSame(200,$this->act('aceptar_incidencia')['statusCode']);
        $this->db->exec('UPDATE usuario_cuadrilla SET id_cuadrilla=1 WHERE id_usuario=2');
        $this->user(2);
        $this->assertSame(200,$this->act('iniciar_atencion_incidencia','Aceptada')['statusCode']);
        $this->assertSame(200,$this->act('finalizar_atencion_incidencia','En atención')['statusCode']);
        $this->assertSame(1,$this->history()[0]['id_usuario_acepta']);
        $this->assertSame(2,$this->history()[0]['id_usuario_inicia']);
        $this->assertSame(2,$this->history()[0]['id_usuario_cierra']);
    }

    public function testPermisoRevocadoDespuesDeAceptarNoConservaAccesoDeEscritura(): void {
        $this->assertSame(200,$this->act('aceptar_incidencia')['statusCode']);
        $this->db->exec('DELETE FROM rol_permiso WHERE id_permiso=3');
        $this->assertSame(403,$this->act('iniciar_atencion_incidencia','Aceptada')['statusCode']);
        $this->assertSame('Aceptada',$this->history()[0]['estado']);
        $this->assertSame('Pendiente',$this->row()['estado']);
        $this->assertSame(200,$this->own()['statusCode']); // F4.1 no cambia su permiso de lectura.
    }

    public function testPrioridadNoInterrumpeYMotiveLimiteEsEnCaracteres(): void {
        $before=$this->history();
        $this->assertSame(200,$this->admin(['prioridad'=>'Baja'])['statusCode']);
        $this->assertSame($before,$this->history());
        $reason=str_repeat('á',500);
        $this->assertSame(200,$this->act('rechazar_incidencia','Asignada',['motivo'=>$reason])['statusCode']);
        $this->assertSame($reason,$this->history()[0]['motivo_cierre']);
    }
}
