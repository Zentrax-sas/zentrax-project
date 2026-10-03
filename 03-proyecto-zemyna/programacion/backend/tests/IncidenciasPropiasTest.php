<?php
require_once __DIR__ . '/fixtures/asignacion_vehiculo.php';
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/fixtures/atencion_incidencia.php';
require_once __DIR__ . '/../controllers/RecoleccionController.php';
require_once __DIR__ . '/../controllers/IncidenciaController.php';

/** Contrato F4.1 con SQL real y datos exclusivamente en memoria. */
final class IncidenciasPropiasTest extends TestCase
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
        $this->user();
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->previous;
        if ($this->started) { session_destroy(); session_save_path($this->savePath); }
    }

    private function user(int $id = 1, bool $ti = false): void
    {
        $_SESSION = ['usuario' => ['id_usuario' => $id, 'roles' => $ti ? ['ADMINISTRADOR_TI'] : ['OPERARIO'],
            'autorizaciones' => array_map(fn($p) => ['permiso' => $p, 'sector' => 'OPERACIONES'], ['recorrido.consultar', 'recorrido.operar'])]];
    }

    private function get(array $query = [], string $method = 'GET'): array
    {
        return (new RecoleccionController($this->db))->consultar(['view' => 'incidencias_propias'] + $query, $method);
    }

    private function ids(array $query = []): array
    {
        $response = $this->get($query);
        $this->assertSame(200, $response['statusCode']);
        return array_column($response['data'], 'id_incidencia');
    }

    public function testF3ApoyoSinRecorridoVisibleSoloParaSuCuadrilla(): void
    {
        $this->db->exec("INSERT INTO permiso VALUES(3,'incidencia.consultar'),(4,'incidencia.modificar'); INSERT INTO rol_permiso VALUES(1,3),(1,4);
            CREATE TABLE vehiculo(id_vehiculo INTEGER PRIMARY KEY,matricula TEXT,activo INTEGER,estado TEXT,funcion_operativa TEXT);
            CREATE TABLE usa(id_usa INTEGER PRIMARY KEY,id_cuadrilla INTEGER,id_vehiculo INTEGER);
            CREATE TABLE recorrido(id_recorrido INTEGER PRIMARY KEY,id_ruta INTEGER,estado TEXT,fecha_fin TEXT);
            CREATE TABLE participa(id_usa INTEGER,id_recorrido INTEGER,hora_fin TEXT);
            INSERT INTO vehiculo VALUES(1,'APOYO1',1,'En Servicio','APOYO'); INSERT INTO usa VALUES(1,1,1);
            INSERT INTO asignacion_vehiculo_operativa(id_asignacion_vehiculo,id_cuadrilla,id_vehiculo,fecha_inicio,id_usuario_asigna) VALUES(1,1,1,'2020-01-01',1)");
        $response=(new IncidenciaController($this->db))->assignAdministrative(['accion'=>'asignar','id_incidencia'=>5,'id_cuadrilla'=>1,'id_asignacion_vehiculo'=>1,'id_usa'=>1,'id_recorrido'=>null,'cuadrilla_esperada'=>null,'estado_esperado'=>'Pendiente']);
        $this->assertSame(200,$response['statusCode']);$this->assertContains(5,$this->ids());
        $this->user(2);$this->assertNotContains(5,$this->ids());
        $this->assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM recorrido')->fetchColumn());
    }

    public function testListaPropiaSinRecorridoNiDatosDelAutor(): void
    {
        // No hay tablas recorrido/usa/participa: consultar incidencias no debe necesitarlas.
        $_SESSION['usuario']['id_cuadrilla'] = 2;
        $this->assertSame([1,2], $this->ids());
        $this->assertSame(['page' => 1, 'limit' => 20, 'has_more' => false], $this->get()['meta']);
        $this->assertSame([
            'id_incidencia' => 1, 'tracking_number' => 'INC-2026-00001', 'estado' => 'Pendiente', 'prioridad' => 'Alta',
            'tipo_problema' => 'Contenedor Desbordado', 'descripcion' => 'Reporte propio',
            'fecha_reporte' => '2026-09-01 08:00:00', 'fecha_resolucion' => null,
            'id_contenedor' => 1, 'contenedor_codigo' => 'C-001', 'contenedor_direccion' => 'Calle de prueba 123',
            'id_ruta' => 1, 'ruta_nombre' => 'Ruta del contenedor', 'latitud' => -34.9, 'longitud' => -56.1, 'ubicacion_origen' => 'contenedor', 'atencion' => null,
        ], $this->get(['id_incidencia' => 1])['data'][0]);
        $this->user(2);
        $this->assertSame([4], $this->ids());
    }

    public function testDetalleOcultaAjenaNoAsignadaEInexistenteIgualmente(): void
    {
        $expected = $this->get(['id_incidencia' => 999]);
        $this->assertSame(404, $expected['statusCode']);
        foreach ([4,5] as $id) $this->assertSame($expected, $this->get(['id_incidencia' => $id]));
        $this->assertSame([1], $this->ids(['id_incidencia' => 1]));
    }

    public function testEstadosYDetalleResuelto(): void
    {
        foreach (['Pendiente' => [1], 'En Proceso' => [2], 'Resuelta' => [3]] as $estado => $ids) {
            $this->assertSame($ids, $this->ids(['estado' => $estado]));
        }
        $this->assertSame([3], $this->ids(['id_incidencia' => 3]));
        $this->assertSame('2026-09-03 12:00:00', $this->get(['id_incidencia' => 3])['data'][0]['fecha_resolucion']);
    }

    /** @dataProvider invalidQueries */
    public function testRechazaParametrosQueManipulanIdentidadOFiltros(array $query): void
    {
        $this->assertSame(400, $this->get($query)['statusCode']);
    }

    public static function invalidQueries(): array
    {
        $cases = [];
        foreach (['id_usuario','id_cuadrilla','usuario','cuadrilla','id','id_recorrido','id_usa','id_participa','admin','tracking_number','accion','activas'] as $key) {
            $cases[$key] = [[$key => 2]];
        }
        foreach (['id_usuario','id_cuadrilla'] as $key) $cases[$key . '_null'] = [[$key => null]];
        foreach (['id_incidencia','page','limit'] as $key) {
            foreach ([0,-1,'1 OR 1=1','1.5',[],null,'', '2147483648'] as $index => $value) $cases[$key . $index] = [[$key => $value]];
        }
        $cases['page_max'] = [['page' => 1000001]];
        $cases['limit_max'] = [['limit' => 101]];
        foreach (['Finalizado','Cancelado','Todas','',[],null] as $index => $value) $cases['estado'.$index] = [['estado' => $value]];
        foreach (['page' => 1,'limit' => 20,'estado' => 'Pendiente'] as $key => $value) $cases['detalle_'.$key] = [['id_incidencia' => 1, $key => $value]];
        return $cases;
    }

    public function testSesionCuentaPermisosYPertenencia(): void
    {
        $_SESSION = []; $this->assertSame(401, $this->get()['statusCode']);
        $this->user(3); $this->assertSame(401, $this->get()['statusCode']);
        $this->user(999); $this->assertSame(401, $this->get()['statusCode']);
        $this->user(4); $missing = $this->get();
        $this->assertSame(409, $missing['statusCode']); $this->assertSame('sin_pertenencia', $missing['code']);
        $this->user(); $_SESSION['usuario']['autorizaciones'] = [];
        $this->assertSame(403, $this->get()['statusCode']);
        $this->user(); array_pop($_SESSION['usuario']['autorizaciones']);
        $this->assertSame(403, $this->get()['statusCode']);
        $this->user(); $_SESSION['usuario']['autorizaciones'][0]['sector'] = 'LOGISTICA';
        $this->assertSame(403, $this->get()['statusCode']);
    }

    /** @dataProvider revokedEligibility */
    public function testRevalidaElegibilidadAunqueLaSesionConservePermisos(string $sql): void
    {
        $this->db->exec($sql);
        $this->assertSame(403, $this->get()['statusCode']);
    }

    public static function revokedEligibility(): array
    {
        return [
            ["UPDATE usuario_rol SET sector='INSPECCION' WHERE id_usuario=1"],
            ["UPDATE usuario_rol SET fecha_hasta='2020-01-02' WHERE id_usuario=1"],
            ["UPDATE usuario_rol SET fecha_desde='2099-01-01' WHERE id_usuario=1"],
            ['DELETE FROM rol_permiso WHERE id_permiso=1'],
            ['DELETE FROM rol_permiso WHERE id_permiso=2'],
        ];
    }

    public function testTiNoEvitaElegibilidadPertenenciaNiOwnership(): void
    {
        $this->user(5, true); $this->assertSame(403, $this->get()['statusCode']);
        $this->user(4, true); $this->assertSame(409, $this->get()['statusCode']);
        $this->user(1, true); $_SESSION['usuario']['autorizaciones'] = [];
        $this->assertSame([1,2], $this->ids());
        $this->assertSame(404, $this->get(['id_incidencia' => 4])['statusCode']);
        $this->assertSame(400, $this->get(['id_cuadrilla' => 2])['statusCode']);
    }

    public function testCompatibilidadConPermisoOperativoModificar(): void
    {
        $this->db->exec("UPDATE permiso SET nombre='recorrido.modificar' WHERE id_permiso=2");
        $_SESSION['usuario']['autorizaciones'][1]['permiso'] = 'recorrido.modificar';
        $this->assertSame([1,2], $this->ids());
    }

    public function testOrdenPaginacionYLimitesNoAmplianOwnership(): void
    {
        $insert = $this->db->prepare("INSERT INTO incidencia (id_incidencia,estado,prioridad,fecha_reporte,id_cuadrilla)
            VALUES (?, 'Pendiente', ?, ?, ?)");
        foreach ([[6,'Alta','2026-08-01',1],[7,'Alta','2026-08-01',1],[8,'Baja','2020-01-01',1],
            [9,'Media','2026-08-01',1],[10,'Alta','2019-01-01',2]] as $row) $insert->execute($row);
        $expected = [6,7,1,9,2,8];
        $this->assertSame($expected, $this->ids());
        $collected = [];
        for ($page = 1; $page <= 3; $page++) {
            $result = $this->get(['page' => $page, 'limit' => 2]);
            $this->assertSame(200, $result['statusCode']);
            $this->assertSame(['page' => $page, 'limit' => 2, 'has_more' => $page < 3], $result['meta']);
            array_push($collected, ...array_column($result['data'], 'id_incidencia'));
        }
        $this->assertSame($expected, $collected);
        $this->assertSame([], $this->ids(['page' => 4, 'limit' => 2]));
        for ($id = 11; $id <= 115; $id++) $insert->execute([$id, 'Baja', '2026-09-01', 1]);
        $this->assertCount(20, $this->get()['data']); $this->assertTrue($this->get()['meta']['has_more']);
        $this->assertCount(100, $this->get(['limit' => 100])['data']);
        $this->assertSame([], $this->ids(['page' => 1000000]));
    }

    public function testUbicacionRealRutaDirectaYPuntoSinRuta(): void
    {
        $this->db->exec('UPDATE incidencia SET latitud=-34.8,longitud=-56.2 WHERE id_incidencia=1');
        $row = $this->get(['id_incidencia' => 1])['data'][0];
        $this->assertSame('problema', $row['ubicacion_origen']); $this->assertSame(-34.8, $row['latitud']);
        $this->assertSame(1, $row['id_ruta']);
        $this->db->exec('UPDATE incidencia SET id_contenedor=NULL,id_ruta=2,latitud=NULL,longitud=NULL WHERE id_incidencia=1');
        $row = $this->get(['id_incidencia' => 1])['data'][0];
        $this->assertSame(2, $row['id_ruta']); $this->assertSame('Ruta directa', $row['ruta_nombre']);
        $this->assertNull($row['contenedor_codigo']); $this->assertNull($row['latitud']); $this->assertNull($row['ubicacion_origen']);
        $this->db->exec('UPDATE incidencia SET id_ruta=NULL,latitud=-34.8,longitud=-56.2 WHERE id_incidencia=1');
        $row = $this->get(['id_incidencia' => 1])['data'][0];
        $this->assertNull($row['id_ruta']); $this->assertNull($row['ruta_nombre']); $this->assertSame('problema', $row['ubicacion_origen']);
        $this->assertArrayNotHasKey('id_recorrido', $row);
        $this->db->exec('UPDATE incidencia SET latitud=91 WHERE id_incidencia=1');
        $row = $this->get(['id_incidencia' => 1])['data'][0];
        $this->assertNull($row['latitud']); $this->assertNull($row['longitud']); $this->assertNull($row['ubicacion_origen']);
    }

    public function testTrasladoCierreYReasignacionSeReflejanEnLaConsulta(): void
    {
        $this->db->exec('UPDATE incidencia SET id_cuadrilla=2 WHERE id_incidencia=1');
        $this->assertSame([2], $this->ids()); $this->assertSame(404, $this->get(['id_incidencia' => 1])['statusCode']);
        $this->db->exec("UPDATE usuario_cuadrilla SET fecha_fin='2026-09-03' WHERE id_usuario=1;
            INSERT INTO usuario_cuadrilla VALUES(6,1,2,'2026-09-03',NULL)");
        $this->assertSame([4,1], $this->ids());
        $this->assertSame(404, $this->get(['id_incidencia' => 2])['statusCode']);
        // Un alcance leído antes del traslado tampoco sirve para consultar la cuadrilla anterior.
        $this->assertSame([], (new Incidencia($this->db))->readOwn(1, 1, 2, 1, 20, null)->fetchAll(PDO::FETCH_ASSOC));
        $this->db->exec("UPDATE usuario_cuadrilla SET fecha_fin='2026-09-04' WHERE id_usuario=1 AND fecha_fin IS NULL");
        $this->assertSame(409, $this->get()['statusCode']);
    }

    public function testConsultasNoEscribenYErroresSonSeguros(): void
    {
        $before = $this->db->query('SELECT * FROM incidencia ORDER BY id_incidencia')->fetchAll(PDO::FETCH_ASSOC);
        $this->db->exec('PRAGMA query_only = ON');
        $this->assertSame([1,2], $this->ids()); $this->assertSame([3], $this->ids(['estado' => 'Resuelta']));
        $this->assertSame([1], $this->ids(['id_incidencia' => 1]));
        foreach (['POST','PUT','PATCH','DELETE','HEAD'] as $method) $this->assertSame(405, $this->get([], $method)['statusCode']);
        $this->assertSame($before, $this->db->query('SELECT * FROM incidencia ORDER BY id_incidencia')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame(503, (new RecoleccionController(null))->consultar(['view' => 'incidencias_propias'])['statusCode']);
        $this->db->exec('PRAGMA query_only = OFF; DROP TABLE incidencia');
        $response = $this->get();
        $this->assertSame(503, $response['statusCode']); $this->assertStringNotContainsString('SELECT', json_encode($response));
    }

    public function testReportePublicoF2AsignacionF3YDescubrimientoF41(): void
    {
        $this->db->exec("INSERT INTO permiso VALUES(3,'incidencia.consultar'),(4,'incidencia.modificar'); INSERT INTO rol_permiso VALUES(1,3),(1,4);
            CREATE TABLE vehiculo (id_vehiculo INTEGER, matricula TEXT, activo INTEGER, estado TEXT,funcion_operativa TEXT);
            CREATE TABLE usa (id_usa INTEGER, id_cuadrilla INTEGER, id_vehiculo INTEGER);
            CREATE TABLE recorrido (id_recorrido INTEGER, id_ruta INTEGER, estado TEXT, fecha_inicio TEXT, fecha_fin TEXT);
            CREATE TABLE participa (id_participa INTEGER, id_usa INTEGER, id_recorrido INTEGER, hora_fin TEXT);
            INSERT INTO vehiculo VALUES(1,'TEST',1,'Disponible','REGULAR'); INSERT INTO usa VALUES(1,1,1);
            INSERT INTO asignacion_vehiculo_operativa(id_asignacion_vehiculo,id_cuadrilla,id_vehiculo,fecha_inicio,id_usuario_asigna) VALUES(1,1,1,'2020-01-01',1);
            INSERT INTO recorrido VALUES(1,2,'Pendiente','2026-09-01',NULL); INSERT INTO participa VALUES(1,1,1,NULL);");
        $admin = new IncidenciaController($this->db);
        $created = $admin->create(['descripcion' => 'Reporte ciudadano de prueba', 'tipo_problema' => 'Contenedor Desbordado', 'id_contenedor' => 1]);
        $this->assertSame(201, $created['statusCode']);
        $id = $created['data']['id_incidencia'];
        $this->assertSame(200, $admin->getAll(['id' => $id])['statusCode']);
        $this->assertSame(404, $this->get(['id_incidencia' => $id])['statusCode']);
        $assigned = $admin->assignAdministrative(['accion' => 'asignar', 'id_incidencia' => $id,
            'id_cuadrilla' => 1, 'id_asignacion_vehiculo'=>1, 'id_recorrido' => 1, 'id_usa' => 1, 'cuadrilla_esperada' => null, 'estado_esperado' => 'Pendiente']);
        $this->assertSame(200, $assigned['statusCode']);
        $this->assertContains((int) $id, $this->ids());
        $detail = $this->get(['id_incidencia' => $id]);
        $this->assertSame(1, $detail['data'][0]['id_ruta']); // La ruta del contenedor, no la del recorrido F3.
        $this->assertArrayNotHasKey('id_recorrido', $detail['data'][0]);
        $this->db->exec("UPDATE recorrido SET estado='Finalizado',fecha_fin='2026-09-02'");
        $this->assertContains((int) $id, $this->ids());
        $this->assertSame(200, $admin->getPublicByTracking($created['data']['tracking_number'])['statusCode']);
        $this->user(2); $this->assertSame(404, $this->get(['id_incidencia' => $id])['statusCode']);
    }

    /** @dataProvider apiRequests */
    public function testEndpointRealEnProcesoAisladoSinBasePersistente(string $method, bool $authenticated, array $query, int $status): void
    {
        // Igual que IncidenciaApiAccessTest: puerto cerrado, sin conexión a MariaDB real.
        $script = tempnam(sys_get_temp_dir(), 'own_inc_access_');
        $code = <<<'PHP'
<?php
session_save_path(sys_get_temp_dir());
session_start();
putenv('DB_HOST=127.0.0.1;port=1');
register_shutdown_function(function () {
    $body = ob_get_clean();
    echo json_encode(['status' => http_response_code(), 'body' => json_decode($body, true)]);
    session_destroy();
});
ob_start();
PHP;
        $code .= PHP_EOL;
        // No interpolar código desde datos: exportar arrays/strings como literales PHP.
        $code .= '$_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';' . PHP_EOL;
        $code .= '$_GET = ' . var_export(['view' => 'incidencias_propias'] + $query, true) . ';' . PHP_EOL;
        $code .= '$_SESSION = ' . var_export($authenticated ? $_SESSION : [], true) . ';' . PHP_EOL;
        $code .= 'require ' . var_export(realpath(__DIR__ . '/../api/recoleccion.php'), true) . ';';
        file_put_contents($script, $code);
        try {
            $process = proc_open([PHP_BINARY, $script], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $errors);
            $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($status, $result['status']);
            $this->assertFalse($result['body']['success']);
        } finally { unlink($script); }
    }

    public static function apiRequests(): array
    {
        return [
            ['GET', false, [], 401], ['GET', true, [], 503],
            ['GET', true, ['id_usuario' => 2], 400], ['GET', true, ['id_cuadrilla' => 2], 400],
            ['POST', true, [], 405], ['PUT', true, [], 405], ['DELETE', true, [], 405],
        ];
    }
}
