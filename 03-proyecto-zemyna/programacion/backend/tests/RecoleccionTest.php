<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../controllers/RecoleccionController.php';

final class RecoleccionTest extends TestCase
{
    private PDO $db;
    private array $previous;
    private bool $started = false;
    private string $savePath;
    protected function setUp(): void
    {
        $this->savePath = session_save_path();
        if (session_status() !== PHP_SESSION_ACTIVE) { session_save_path(sys_get_temp_dir()); session_start(); $this->started = true; }
        $this->previous = $_SESSION ?? [];
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE usuario (id_usuario INTEGER, activo TEXT);
            CREATE TABLE cuadrilla (id_cuadrilla INTEGER, nombre TEXT, turno TEXT);
            CREATE TABLE ruta (id_ruta INTEGER, nombre TEXT, zona TEXT);
            CREATE TABLE vehiculo (id_vehiculo INTEGER, matricula TEXT, estado TEXT, activo INTEGER);
            CREATE TABLE usa (id_usa INTEGER, id_cuadrilla INTEGER, id_vehiculo INTEGER);
            CREATE TABLE recorrido (id_recorrido INTEGER, id_ruta INTEGER, fecha_inicio TEXT, fecha_fin TEXT, estado TEXT, id_usuario_inicio INTEGER, id_usuario_fin INTEGER);
            CREATE TABLE participa (id_participa INTEGER, id_usa INTEGER, id_recorrido INTEGER);
            CREATE TABLE contenedor (id_contenedor INTEGER, id_ruta INTEGER, codigo TEXT, direccion TEXT, latitud REAL, longitud REAL, estado TEXT, activo INTEGER);
            INSERT INTO usuario VALUES (1, 'Activo'), (2, 'Inactivo');
            INSERT INTO cuadrilla VALUES (1, 'Primera', 'Matutino'), (2, 'Otra', 'Nocturno'), (3, 'Sin relaciones', 'Vespertino');
            INSERT INTO ruta VALUES (1, 'Ruta propia', 'Centro'), (2, 'Ruta ajena', 'Otra');
            INSERT INTO vehiculo VALUES (1, 'TEST1', 'Disponible', 1), (2, 'TEST2', 'En Mantenimiento', 0);
            INSERT INTO usa VALUES (1, 1, 1), (2, 1, 2), (3, 2, 2);
            INSERT INTO recorrido VALUES (1, 1, '2026-09-01 08:00:00', NULL, 'En Proceso', NULL, NULL), (2, 2, '2026-09-02 08:00:00', '2026-09-02 12:00:00', 'Finalizado', NULL, NULL);
            INSERT INTO participa VALUES (1, 1, 1), (2, 2, 1), (3, 3, 2);
            INSERT INTO contenedor VALUES (1, 1, 'TEST-C1', NULL, NULL, NULL, 'Disponible', 1), (2, 2, 'TEST-C2', 'Otra', -34.9, -56.1, 'Lleno', 1);");
        $this->db->exec("CREATE TABLE usuario_cuadrilla (id_usuario_cuadrilla INTEGER PRIMARY KEY AUTOINCREMENT, id_usuario INTEGER, id_cuadrilla INTEGER, fecha_inicio TEXT, fecha_fin TEXT, id_usuario_asigna INTEGER, id_usuario_finaliza INTEGER);
            CREATE UNIQUE INDEX uq_uc ON usuario_cuadrilla(id_usuario) WHERE fecha_fin IS NULL;
            CREATE TABLE atencion_contenedor (id_atencion_contenedor INTEGER PRIMARY KEY AUTOINCREMENT, id_recorrido INTEGER, id_contenedor INTEGER, id_usuario INTEGER, fecha_atencion TEXT, UNIQUE(id_recorrido,id_contenedor));
            ALTER TABLE usuario ADD nombre TEXT DEFAULT 'Prueba'; ALTER TABLE usuario ADD apellido TEXT DEFAULT 'Test';
            CREATE TABLE usuario_rol (id_usuario INTEGER, id_rol INTEGER, sector TEXT, fecha_desde TEXT, fecha_hasta TEXT);
            CREATE TABLE rol_permiso (id_rol INTEGER, id_permiso INTEGER);
            CREATE TABLE permiso (id_permiso INTEGER, nombre TEXT);
            INSERT INTO usuario(id_usuario,activo) VALUES (3,'Activo'),(4,'Activo'),(5,'Activo');
            INSERT INTO permiso VALUES (1,'recorrido.consultar'),(2,'recorrido.operar');
            INSERT INTO rol_permiso VALUES (1,1),(1,2);
            INSERT INTO usuario_rol VALUES (3,1,'OPERACIONES','2020-01-01',NULL),(4,1,'OPERACIONES','2020-01-01',NULL);");
        $this->user(true);
    }
    protected function tearDown(): void {
        $_SESSION = $this->previous;
        if ($this->started) { session_destroy(); session_save_path($this->savePath); }
    }
    private function user(bool $admin = false): void
    {
        $_SESSION = ['usuario' => ['id_usuario' => 1, 'roles' => [], 'autorizaciones' => array_map(
            fn($p) => ['permiso' => $p, 'sector' => 'OPERACIONES'], $admin ? ['recorrido.consultar', 'cuadrilla.consultar', 'cuadrilla.modificar', 'usuario.consultar'] : ['recorrido.consultar'])]];
    }
    private function get(array $query = []): array { return (new RecoleccionController($this->db))->consultar($query); }
    public function testSesionYPermisos(): void
    {
        $_SESSION = []; $this->assertSame(401, $this->get()['statusCode']);
        $this->user(); $_SESSION['usuario']['autorizaciones'] = []; $this->assertSame(403, $this->get()['statusCode']);
        $this->user(); $this->assertSame(403, $this->get(['view' => 'administracion'])['statusCode']);
        $_SESSION['usuario']['autorizaciones'][0]['sector'] = 'INSPECCION'; $this->assertSame(403, $this->get()['statusCode']);
        $this->user(true); $_SESSION['usuario']['id_usuario'] = 2; $this->assertSame(401, $this->get()['statusCode']);
    }
    public function testNoInfierePertenenciaDesdeSesionNiCliente(): void
    {
        $this->user(); $_SESSION['usuario']['id_cuadrilla'] = 1;
        $response = $this->get();
        $this->assertSame(409, $response['statusCode']);
        $this->assertSame('sin_pertenencia', $response['code']);
        $this->assertArrayNotHasKey('puede_consultar_administracion', $response);
        foreach ([1, 2, 999] as $id) $this->assertSame(400, $this->get(['id_cuadrilla' => $id])['statusCode']);
        $this->assertSame(400, $this->get(['id_usuario' => 1])['statusCode']);
    }
    public function testConsultaRealDeduplicaYConservaEstadoDeVehiculos(): void
    {
        $data = $this->get(['view' => 'administracion', 'id_cuadrilla' => 1])['data'];
        $this->assertSame(1, $data['lista']['total']);
        $this->assertCount(1, $data['lista']['items']);
        $this->assertCount(2, $data['lista']['items'][0]['vehiculos']);
        $this->assertSame(0, $data['vehiculos'][1]['activo']);
        $this->assertSame('En Mantenimiento', $data['vehiculos'][1]['estado']);
        $this->assertSame('En Proceso', $data['lista']['items'][0]['estado']);
        $empty = $this->get(['view' => 'administracion', 'id_cuadrilla' => 3])['data'];
        $this->assertSame([], $empty['vehiculos']); $this->assertSame(0, $empty['lista']['total']);
    }
    public function testDetalleConRelacionRealYPuntosSinDatosInventados(): void
    {
        $data = $this->get(['view' => 'administracion', 'id_cuadrilla' => 1, 'id_ruta' => 1])['data'];
        $this->assertSame('Ruta propia', $data['ruta']['nombre']);
        $this->assertSame('TEST-C1', $data['lista']['items'][0]['codigo']);
        $this->assertNull($data['lista']['items'][0]['latitud']);
        foreach ([2, 999] as $id) $this->assertSame(404, $this->get(['view' => 'administracion', 'id_cuadrilla' => 1, 'id_ruta' => $id])['statusCode']);
        $this->assertSame(404, $this->get(['view' => 'administracion', 'id_cuadrilla' => 999])['statusCode']);
    }
    public function testFiltrosYLimites(): void
    {
        foreach ([0, -1, '1 OR 1=1', [], '1.5', '2147483648'] as $value) $this->assertSame(400, $this->get(['view' => 'administracion', 'id_cuadrilla' => $value])['statusCode']);
        $this->assertSame(400, $this->get(['view' => 'administracion', 'id_ruta' => 1])['statusCode']);
        $this->assertSame(400, $this->get(['view' => 'administracion', 'id_cuadrilla' => 1, 'estado' => 'Resuelta'])['statusCode']);
        $this->assertSame(400, $this->get(['view' => []])['statusCode']);
        $this->assertSame(0, $this->get(['view' => 'administracion', 'id_cuadrilla' => 1, 'estado' => 'Finalizado'])['data']['lista']['total']);
        $this->assertSame(1, $this->get(['view' => 'administracion', 'id_cuadrilla' => 1, 'estado' => 'En Proceso'])['data']['lista']['total']);
        for ($i = 4; $i <= 30; $i++) $this->db->exec("INSERT INTO cuadrilla VALUES ($i, 'Prueba', 'Matutino')");
        $first = $this->get(['view' => 'administracion'])['data']['lista'];
        $second = $this->get(['view' => 'administracion', 'page' => 2])['data']['lista'];
        $this->assertCount(25, $first['items']); $this->assertCount(5, $second['items']);
        $this->assertSame(30, $second['total']);
        $this->assertSame([], array_intersect(array_column($first['items'], 'id_cuadrilla'), array_column($second['items'], 'id_cuadrilla')));
    }
    public function testNoEscribeNiExponeErroresInternos(): void
    {
        foreach (['POST', 'PUT', 'DELETE'] as $method) $this->assertSame(405, (new RecoleccionController($this->db))->consultar([], $method)['statusCode']);
        $this->assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM recorrido')->fetchColumn());
        $this->assertSame(503, (new RecoleccionController(null))->consultar([])['statusCode']);
        $this->db->exec('DROP TABLE recorrido');
        $response = $this->get(['view' => 'administracion', 'id_cuadrilla' => 1]);
        $this->assertSame(503, $response['statusCode']);
        $this->assertStringNotContainsString('SQL', json_encode($response));
    }
    private function write(array $body): array { return (new RecoleccionController($this->db))->modificar($body); }
    private function operator(int $id = 3): void {
        $this->user(); $_SESSION['usuario']['id_usuario'] = $id;
        $_SESSION['usuario']['autorizaciones'][] = ['permiso' => 'recorrido.operar', 'sector' => 'OPERACIONES'];
    }
    private function assigned(): void {
        $this->assertSame(200, $this->write(['accion' => 'asignar', 'integrante' => 3, 'destino' => 3])['statusCode']);
        $this->db->exec("INSERT INTO usa VALUES (4,3,1); INSERT INTO recorrido VALUES (3,1,'2026-01-01 08:00:00',NULL,'Pendiente',NULL,NULL); INSERT INTO participa VALUES (4,4,3)");
        $this->operator();
    }
    public function testAsignacionTrasladoCierreEHistorialPersisten(): void {
        $a = $this->write(['accion' => 'asignar', 'integrante' => 3, 'destino' => 1]);
        $this->assertSame(200, $a['statusCode']); $id = $a['data']['pertenencia']['id_usuario_cuadrilla'];
        $this->assertSame(409, $this->write(['accion' => 'asignar', 'integrante' => 3, 'destino' => 2])['statusCode']);
        $b = $this->write(['accion' => 'trasladar', 'integrante' => 3, 'destino' => 2, 'pertenencia' => $id]);
        $this->assertSame(200, $b['statusCode']);
        $this->assertSame(409, $this->write(['accion' => 'trasladar', 'integrante' => 3, 'destino' => 3, 'pertenencia' => $id])['statusCode']);
        $rows = $this->db->query('SELECT * FROM usuario_cuadrilla ORDER BY id_usuario_cuadrilla')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows); $this->assertNotNull($rows[0]['fecha_fin']); $this->assertSame(1,$rows[0]['id_usuario_finaliza']); $this->assertNull($rows[1]['fecha_fin']);
        $this->assertSame(200, $this->write(['accion' => 'finalizar_pertenencia', 'integrante' => 3, 'pertenencia' => $rows[1]['id_usuario_cuadrilla']])['statusCode']);
        $this->operator(); $this->assertSame(409, $this->get()['statusCode']);
        $this->assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM usuario_cuadrilla')->fetchColumn());
    }
    public function testRechazaIntegrantesNoElegiblesYDestinosInvalidos(): void {
        foreach ([2,5,999] as $id) $this->assertContains($this->write(['accion'=>'asignar','integrante'=>$id,'destino'=>1])['statusCode'],[400,404]);
        $this->assertSame(404,$this->write(['accion'=>'asignar','integrante'=>3,'destino'=>999])['statusCode']);
        $this->db->exec("UPDATE usuario_rol SET sector='INSPECCION' WHERE id_usuario=3");
        $this->assertSame(400,$this->write(['accion'=>'asignar','integrante'=>3,'destino'=>1])['statusCode']);
        $this->assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM usuario_cuadrilla')->fetchColumn());
    }
    public function testFlujoCompletoConProgresoAutoresYFechasServidor(): void {
        $this->assigned();
        $data = $this->get()['data']; $this->assertSame(3,$data['pertenencia']['id_cuadrilla']); $this->assertSame(3,$data['recorrido']['id_recorrido']);
        $this->assertSame(409,$this->write(['accion'=>'atender','id_recorrido'=>3,'id_contenedor'=>1])['statusCode']);
        $this->assertSame(409,$this->write(['accion'=>'finalizar','id_recorrido'=>3])['statusCode']);
        $start = $this->write(['accion'=>'iniciar','id_recorrido'=>3]); $this->assertSame(200,$start['statusCode']);
        $this->assertSame(3,$start['data']['recorrido']['id_usuario_inicio']);
        $this->assertNotSame('2026-01-01 08:00:00',$start['data']['recorrido']['fecha_inicio']);
        $this->assertSame(409,$this->write(['accion'=>'iniciar','id_recorrido'=>3])['statusCode']);
        $this->assertSame(404,$this->write(['accion'=>'atender','id_recorrido'=>3,'id_contenedor'=>2])['statusCode']);
        $attend = $this->write(['accion'=>'atender','id_recorrido'=>3,'id_contenedor'=>1]); $this->assertSame(200,$attend['statusCode']);
        $this->assertSame(['total'=>1,'atendidos'=>1,'pendientes'=>0],$attend['data']['recorrido']['progreso']);
        $this->assertSame(409,$this->write(['accion'=>'atender','id_recorrido'=>3,'id_contenedor'=>1])['statusCode']);
        $end = $this->write(['accion'=>'finalizar','id_recorrido'=>3]); $this->assertSame(200,$end['statusCode']);
        $this->assertSame('Finalizado',$end['data']['recorrido']['estado']); $this->assertSame(3,$end['data']['recorrido']['id_usuario_fin']);
        $this->assertNotNull($end['data']['recorrido']['fecha_fin']);
        $this->assertSame(409,$this->write(['accion'=>'finalizar','id_recorrido'=>3])['statusCode']);
        $this->assertSame(409,$this->write(['accion'=>'atender','id_recorrido'=>3,'id_contenedor'=>1])['statusCode']);
        $this->assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM atencion_contenedor WHERE id_usuario=3 AND fecha_atencion IS NOT NULL')->fetchColumn());
        $this->assertSame('Finalizado',$this->get(['id_recorrido'=>3])['data']['recorrido']['estado']);
    }
    public function testIdentidadPermisosYAislamientoOperativo(): void {
        $this->assigned();
        $this->assertSame(404,$this->get(['id_recorrido'=>2])['statusCode']);
        $this->assertSame(404,$this->write(['accion'=>'iniciar','id_recorrido'=>2])['statusCode']);
        foreach (['id_usuario','id_cuadrilla','fecha_inicio','id_usuario_inicio'] as $key) $this->assertSame(400,$this->write(['accion'=>'iniciar','id_recorrido'=>3,$key=>1])['statusCode']);
        $this->assertSame(403,$this->write(['accion'=>'asignar','integrante'=>4,'destino'=>3])['statusCode']);
        $this->user(); $_SESSION['usuario']['id_usuario']=3;
        $this->assertSame(200,$this->get()['statusCode']); $this->assertFalse($this->get()['puede_operar']);
        $this->assertSame(403,$this->write(['accion'=>'iniciar','id_recorrido'=>3])['statusCode']);
        $_SESSION=[]; $this->assertSame(401,$this->write(['accion'=>'iniciar','id_recorrido'=>3])['statusCode']);
    }
    public function testRestriccionUnicaYPersistenciaFallidaHacenRollback(): void {
        $this->assigned();
        try { $this->db->exec("INSERT INTO usuario_cuadrilla(id_usuario,id_cuadrilla,fecha_inicio,id_usuario_asigna) VALUES(3,1,'2026-09-01',1)"); $this->fail('Debe rechazar doble pertenencia'); } catch(PDOException $e) { $this->assertSame('23000',$e->getCode()); }
        $this->user(true);
        $id=(new RecoleccionOperativa($this->db))->pertenencia(3)['id_usuario_cuadrilla'];
        $this->db->exec("CREATE TRIGGER fallo BEFORE INSERT ON usuario_cuadrilla BEGIN SELECT RAISE(ABORT,'fallo de prueba'); END");
        $this->assertSame(503,$this->write(['accion'=>'trasladar','integrante'=>3,'destino'=>2,'pertenencia'=>$id])['statusCode']);
        $this->assertSame(3,(new RecoleccionOperativa($this->db))->pertenencia(3)['id_cuadrilla']);
        $this->assertFalse($this->db->inTransaction());
    }
    public function testVehiculoInactivoRecorridoAmbiguoYSegundaEjecucionActiva(): void {
        $this->assigned(); $this->db->exec('UPDATE vehiculo SET activo=0 WHERE id_vehiculo=1');
        $this->assertSame(409,$this->write(['accion'=>'iniciar','id_recorrido'=>3])['statusCode']);
        $this->db->exec('UPDATE vehiculo SET activo=1 WHERE id_vehiculo=1; INSERT INTO participa VALUES(5,1,3)');
        $this->assertSame(409,$this->write(['accion'=>'iniciar','id_recorrido'=>3])['statusCode']);
        $ambiguous=$this->get(); $this->assertSame(409,$ambiguous['statusCode']); $this->assertSame('recorrido_ambiguo',$ambiguous['code']);
        $this->db->exec("DELETE FROM participa WHERE id_participa=5; INSERT INTO recorrido VALUES(4,1,'2026-01-01',NULL,'En Proceso',NULL,NULL); INSERT INTO participa VALUES(6,4,4)");
        $this->assertSame(409,$this->write(['accion'=>'iniciar','id_recorrido'=>3])['statusCode']);
    }
    public function testSinRecorridoYConsultaAdministrativaDeIntegrantes(): void {
        $this->assertSame(200,$this->write(['accion'=>'asignar','integrante'=>3,'destino'=>3])['statusCode']);
        $members=$this->get(['view'=>'integrantes','id_cuadrilla'=>3]); $this->assertSame(200,$members['statusCode']);
        $this->assertCount(1,$members['data']['historial']); $this->assertSame('Prueba',$members['data']['historial'][0]['asignador_nombre']); $this->assertCount(2,$members['data']['elegibles']);
        $this->operator(); $own=$this->get(); $this->assertSame(409,$own['statusCode']); $this->assertSame('sin_recorrido',$own['code']);
        $this->assertSame(3,$own['data']['pertenencia']['id_cuadrilla']);
        $this->assertSame(403,$this->get(['view'=>'integrantes','id_cuadrilla'=>3])['statusCode']);
    }
    public function testCrudGeneralNoModificaHistoriaOperativa(): void {
        require_once __DIR__.'/../models/Recorrido.php';
        $this->assigned(); $this->write(['accion'=>'iniciar','id_recorrido'=>3]);
        $crud=new Recorrido($this->db); $crud->id_recorrido=3; $crud->fecha_inicio='2020-01-01'; $crud->estado='Pendiente'; $crud->id_ruta=2;
        foreach(['update','delete'] as $method){try{$crud->$method();$this->fail('Debe proteger el historial');}catch(DomainException $e){$this->assertSame(409,$e->getCode());}}
        $row=$this->db->query('SELECT estado,id_usuario_inicio,id_ruta FROM recorrido WHERE id_recorrido=3')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['estado'=>'En Proceso','id_usuario_inicio'=>3,'id_ruta'=>1],$row);
    }
    public function testPermisosDelMenuNoConcedenAccesoAdministrativo(): void {
        $flags=$this->get(['view'=>'permisos']); $this->assertTrue($flags['data']['administracion']); $this->assertTrue($flags['data']['modificar_integrantes']);
        $catalog=$this->get(['view'=>'administracion']); $this->assertSame(200,$catalog['statusCode']);
        $this->assertSame(0,$catalog['data']['lista']['items'][0]['integrantes_activos']);
        $this->assertSame('En Proceso',$catalog['data']['lista']['items'][0]['recorrido_actual']['estado']);
        $this->operator(); $flags=$this->get(['view'=>'permisos']); $this->assertFalse($flags['data']['administracion']); $this->assertFalse($flags['data']['integrantes']);
        $this->assertSame(403,$this->get(['view'=>'administracion'])['statusCode']);
    }
    public function testElegibilidadExplicaEstadoSectorVigenciaYPermisosSinUsarNombreDelRol(): void {
        $m = new RecoleccionOperativa($this->db);
        $this->assertTrue($m->elegible(3));
        $this->assertSame('Cuenta inactiva', $m->elegibilidad(2)['motivo']);
        $this->assertSame('Sin asignación de rol', $m->elegibilidad(5)['motivo']);
        $this->db->exec("UPDATE usuario_rol SET sector='PUNTOS_Y_DESTINOS' WHERE id_usuario=3");
        $this->assertFalse($m->elegible(3)); $this->assertSame('Sector Puntos y destinos', $m->elegibilidad(3)['motivo']);
        $this->db->exec("UPDATE usuario_rol SET sector='OPERACIONES',fecha_hasta='2020-02-01' WHERE id_usuario=3");
        $this->assertFalse($m->elegible(3)); $this->assertStringContainsString('vencida', $m->elegibilidad(3)['motivo']);
        $this->db->exec("UPDATE usuario_rol SET fecha_hasta=NULL,fecha_desde='2099-01-01' WHERE id_usuario=3");
        $this->assertFalse($m->elegible(3));
        $this->db->exec("UPDATE usuario_rol SET fecha_desde='2020-01-01' WHERE id_usuario=3; DELETE FROM rol_permiso WHERE id_permiso=2");
        $this->assertSame('Sin permiso para operar recorridos', $m->elegibilidad(3)['motivo']);
        // Compatibilidad con un rol administrativo: importa el permiso, no su nombre.
        $this->db->exec("INSERT INTO permiso VALUES (3,'recorrido.modificar'); INSERT INTO rol_permiso VALUES (1,3)");
        $this->assertTrue($m->elegible(3));
        $this->db->exec('DELETE FROM rol_permiso WHERE id_permiso=1');
        $this->assertSame('Sin permiso para consultar recorridos', $m->elegibilidad(3)['motivo']);
    }
    public function testMotivosExcluidosProtegidosYSinDatosPersonalesInnecesarios(): void {
        $this->db->exec("UPDATE usuario_rol SET sector='PUNTOS_Y_DESTINOS' WHERE id_usuario=3");
        $r = $this->get(['view'=>'integrantes','id_cuadrilla'=>3]);
        $this->assertSame(200,$r['statusCode']); $this->assertCount(1,$r['data']['elegibles']);
        foreach ($r['data']['no_elegibles'] as $u) $this->assertSame(['nombre','apellido','motivo'],array_keys($u));
        $this->operator(); $denied=$this->get(['view'=>'integrantes','id_cuadrilla'=>3]);
        $this->assertSame(403,$denied['statusCode']);$this->assertArrayNotHasKey('data',$denied);
        $_SESSION=[];$this->assertSame(401,$this->get(['view'=>'integrantes','id_cuadrilla'=>3])['statusCode']);
    }
    private function assignmentFixture(): array {
        $this->user(true); $_SESSION['usuario']['autorizaciones'][]=['permiso'=>'recorrido.modificar','sector'=>'OPERACIONES'];
        $this->db->exec("ALTER TABLE participa ADD hora_inicio TEXT; INSERT INTO vehiculo VALUES (3,'LIBRE','Disponible',1);
            INSERT INTO usa VALUES (4,3,3); INSERT INTO recorrido VALUES (3,1,'2026-09-22 09:30:00',NULL,'Pendiente',NULL,NULL)");
        return ['accion'=>'asignar_recorrido','destino'=>3,'id_recorrido'=>3,'id_usa'=>4];
    }
    public function testAsignacionDeRecorridoConservaHistorialYSeVeDesdeSesionPropia(): void {
        $body=$this->assignmentFixture();
        $history=$this->db->query('SELECT * FROM participa ORDER BY id_participa')->fetchAll(PDO::FETCH_ASSOC);
        $available=$this->get(['view'=>'asignables','id_cuadrilla'=>3]);
        $this->assertSame(200,$available['statusCode']);$this->assertSame(1,$available['data']['total']);
        $this->assertSame(4,$available['data']['vehiculos'][0]['id_usa']);
        $this->assertSame(200,$this->write($body)['statusCode']);
        $this->assertSame('09:30:00',$this->db->query('SELECT hora_inicio FROM participa WHERE id_recorrido=3')->fetchColumn());
        $this->assertSame($history,$this->db->query('SELECT * FROM participa WHERE id_recorrido<>3 ORDER BY id_participa')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame(409,$this->write($body)['statusCode']);
        $this->assertSame(0,$this->get(['view'=>'asignables','id_cuadrilla'=>3])['data']['total']);
        $this->assertSame(200,$this->write(['accion'=>'asignar','integrante'=>3,'destino'=>3])['statusCode']);
        $this->operator();$own=$this->get();$this->assertSame(200,$own['statusCode']);
        $this->assertSame(3,$own['data']['recorrido']['id_recorrido']);$this->assertSame(3,$own['data']['pertenencia']['id_cuadrilla']);
        $this->assertSame(400,$this->get(['id_cuadrilla'=>1])['statusCode']);
    }
    public function testAsignacionRechazaFinalizadosCanceladosCompartidosYTrabajoIncompatible(): void {
        $body=$this->assignmentFixture();
        foreach (['Finalizado','Cancelado','En Proceso'] as $state) {
            $this->db->exec("UPDATE recorrido SET estado='$state' WHERE id_recorrido=3");
            $this->assertSame(409,$this->write($body)['statusCode']);
        }
        $this->db->exec("UPDATE recorrido SET estado='Pendiente' WHERE id_recorrido=3");
        $this->assertSame(409,$this->write(array_replace($body,['id_recorrido'=>1]))['statusCode']);
        $this->assertSame(409,$this->write(array_replace($body,['destino'=>1,'id_usa'=>1]))['statusCode']);
        $this->db->exec('INSERT INTO participa(id_participa,id_usa,id_recorrido) VALUES(4,1,3)');
        $this->assertSame(409,$this->write($body)['statusCode']);
        $this->assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM participa WHERE id_recorrido=3')->fetchColumn());
    }
    public function testAsignacionExigeVehiculoPropioActivoSinOtroRecorrido(): void {
        $body=$this->assignmentFixture();
        $this->assertSame(404,$this->write(array_replace($body,['id_usa'=>1]))['statusCode']);
        $this->db->exec('UPDATE vehiculo SET activo=0 WHERE id_vehiculo=3');$this->assertSame(409,$this->write($body)['statusCode']);
        $this->db->exec("UPDATE vehiculo SET activo=1,estado='En Mantenimiento' WHERE id_vehiculo=3");$this->assertSame(409,$this->write($body)['statusCode']);
        $this->db->exec("UPDATE vehiculo SET estado='Disponible' WHERE id_vehiculo=3; INSERT INTO usa VALUES(5,1,3); INSERT INTO participa(id_participa,id_usa,id_recorrido) VALUES(4,5,1)");
        $this->assertSame(409,$this->write($body)['statusCode']);
        $this->assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM participa WHERE id_recorrido=3')->fetchColumn());
    }
    public function testAsignacionSesionPermisosEIdentidadesManipuladas(): void {
        $body=$this->assignmentFixture();
        foreach(['id_usuario','id_cuadrilla','fecha_inicio'] as $field) $this->assertSame(400,$this->write($body+[$field=>1])['statusCode']);
        foreach([0,[], '1 OR 1=1'] as $id) $this->assertSame(400,$this->write(array_replace($body,['destino'=>$id]))['statusCode']);
        $this->user(true);$this->assertSame(403,$this->write($body)['statusCode']);$this->assertSame(403,$this->get(['view'=>'asignables','id_cuadrilla'=>3])['statusCode']);
        $this->operator();$this->assertSame(403,$this->write($body)['statusCode']);
        $_SESSION=[];$this->assertSame(401,$this->write($body)['statusCode']);
    }
    public function testAsignablesPaginadosVaciosYErroresSinCambiosParciales(): void {
        $body=$this->assignmentFixture();
        for($id=4;$id<=30;$id++)$this->db->exec("INSERT INTO recorrido VALUES($id,1,'2026-09-22 10:00:00',NULL,'Pendiente',NULL,NULL)");
        $first=$this->get(['view'=>'asignables','id_cuadrilla'=>3])['data'];$second=$this->get(['view'=>'asignables','id_cuadrilla'=>3,'page'=>2])['data'];
        $this->assertSame(28,$first['total']);$this->assertCount(25,$first['items']);$this->assertCount(3,$second['items']);
        $this->assertSame([],array_intersect(array_column($first['items'],'id_recorrido'),array_column($second['items'],'id_recorrido')));
        $this->assertSame([],$this->get(['view'=>'asignables','id_cuadrilla'=>3,'page'=>3])['data']['items']);
        $this->assertSame(400,$this->get(['view'=>'asignables'])['statusCode']);$this->assertSame(404,$this->get(['view'=>'asignables','id_cuadrilla'=>999])['statusCode']);
        $this->db->exec("CREATE TRIGGER fallo_asignar BEFORE INSERT ON participa BEGIN SELECT RAISE(ABORT,'fallo de prueba'); END");
        $this->assertSame(503,$this->write($body)['statusCode']);$this->assertFalse($this->db->inTransaction());
        $this->assertSame(3,(int)$this->db->query('SELECT COUNT(*) FROM participa')->fetchColumn());
    }

    public function testCatalogoDeRolesDerivaSugerenciaDesdePermisosReales(): void {
        require_once __DIR__.'/../models/Usuario.php';
        $this->db->exec("CREATE TABLE rol(id_rol INTEGER,nombre TEXT,descripcion TEXT); INSERT INTO rol VALUES(1,'NOMBRE_IRRELEVANTE','Operativo'),(2,'OPERARIO','Sin permisos')");
        $roles=(new Usuario($this->db))->getRolesDisponibles();
        $this->assertSame(['recorrido.consultar','recorrido.operar'],$roles[0]['permisos_recorrido']);
        $this->assertSame([],$roles[1]['permisos_recorrido']);
        $this->db->exec('DELETE FROM rol_permiso');
        $this->assertSame([],(new Usuario($this->db))->getRolesDisponibles()[0]['permisos_recorrido']);
    }

}
