<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../controllers/RutaApoyoController.php';
require_once __DIR__ . '/fixtures/asignacion_vehiculo.php';
require_once __DIR__ . '/fixtures/solicitud_admin_base.php';

final class RutaApoyoTest extends TestCase
{
    use SolicitudAdminBase;
    protected PDO $db;
    private array $savedSession;
    private array $calls;
    protected function setUp(): void
    {
        $this->savedSession = $_SESSION ?? [];
        $this->resetDatabase();
    }
    protected function tearDown(): void { $_SESSION = $this->savedSession; }
    private function resetDatabase(): void
    {
        // Base íntegramente descartable en memoria; nunca carga config ni .env.
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->schema(); $this->seed();
        $this->db->exec("ALTER TABLE incidencia ADD latitud NUMERIC;
            ALTER TABLE incidencia ADD longitud NUMERIC;
            ALTER TABLE incidencia ADD id_contenedor INTEGER;
            ALTER TABLE incidencia ADD fecha_resolucion TEXT;
            CREATE TABLE contenedor(id_contenedor INTEGER PRIMARY KEY,latitud NUMERIC,longitud NUMERIC,activo INTEGER,id_tipo_residuo INTEGER);
            CREATE TABLE atencion_incidencia(id_atencion_incidencia INTEGER PRIMARY KEY,id_incidencia INTEGER,id_cuadrilla INTEGER,estado TEXT,fecha_cierre TEXT,fecha_aceptacion TEXT,fecha_inicio TEXT,id_usuario_acepta INTEGER);
            DELETE FROM participa; DELETE FROM recorrido;
            DELETE FROM asignacion_vehiculo_operativa WHERE id_asignacion_vehiculo=2;
            UPDATE asignacion_vehiculo_operativa SET id_vehiculo=2 WHERE id_asignacion_vehiculo=1;
            UPDATE incidencia SET id_cuadrilla=1,id_ruta=NULL,latitud=-34.901,longitud=-56.161 WHERE id_incidencia=1;
            INSERT INTO atencion_incidencia VALUES(1,1,1,'Aceptada',NULL,'2020-01-01',NULL,2);");
        $_SESSION = ['usuario' => ['id_usuario' => 2]]; $this->calls = [];
    }
    private function query(): array { return ['id_incidencia' => '1', 'origen_latitud' => '-34.9', 'origen_longitud' => '-56.16']; }
    private function response(): array
    {
        return ['status' => 200, 'body' => json_encode(['code' => 'Ok',
            'waypoints' => [['distance' => 0, 'location' => [-56.16, -34.9]], ['distance' => 0, 'location' => [-56.161, -34.901]]],
            'routes' => [['distance' => 150, 'duration' => 30, 'geometry' => ['type' => 'LineString', 'coordinates' => [[-56.16, -34.9], [-56.161, -34.901]]]]]], JSON_THROW_ON_ERROR)];
    }
    private function request(?Closure $during = null, ?array $response = null, ?array $query = null): array
    {
        $factory = fn() => new OsrmClient('http://osrm.invalid:5000', 3, 50,
            function (string $url, int $timeout) use ($during, $response): array {
                $this->assertFalse($this->db->inTransaction(), 'No mantener transacción durante HTTP.');
                $this->calls[] = [$url, $timeout];
                if ($during) $during();
                return $response ?? $this->response();
            });
        return (new RutaApoyoController($this->db, $factory))->get($query ?? $this->query());
    }
    public function testSuccessUsesAuthorizedDestinationAndLongitudeFirst(): void
    {
        $result = $this->request();
        $this->assertSame(200, $result['statusCode']);
        $this->assertStringContainsString('/driving/-56.1600000,-34.9000000;-56.1610000,-34.9010000?', $this->calls[0][0]);
        $this->assertStringContainsString('geometries=geojson', $this->calls[0][0]);
        $this->assertStringNotContainsString('fallback', $this->calls[0][0]);
        $this->assertSame(3, $this->calls[0][1]);
        $this->assertTrue($result['data']['consultiva']);
        $this->assertSame('OSRM', $result['data']['motor']);
        $this->assertSame(150.0, $result['data']['distancia_metros']);
        $this->assertSame(30.0, $result['data']['duracion_segundos']);
        $this->assertSame('problema', $result['data']['destino']['fuente']);
        $this->assertSame('LineString', $result['data']['geometria']['type']);
        $this->assertStringContainsString('GPS', implode(' ', $result['data']['advertencias']));
        $this->assertStringNotContainsString('osrm.invalid', json_encode($result));
        $this->assertFalse($this->db->inTransaction());
    }
    private function startAttention(): void
    {
        $this->db->exec("UPDATE incidencia SET estado='En Proceso';
            UPDATE atencion_incidencia SET estado='En atención',fecha_aceptacion='2020-01-01 00:00:00',fecha_inicio='2020-01-01 01:00:00'");
    }
    public function testInProgressSuccessAndSameSquadAuthorship(): void
    {
        $this->startAttention();
        // F4 ownership is squad membership, not the acceptance author's identity.
        $this->db->exec('UPDATE atencion_incidencia SET id_usuario_acepta=1');
        $this->assertSame(200, $this->request()['statusCode']);
        $this->assertCount(1, $this->calls);
        $_SESSION['usuario']['id_usuario'] = 3;
        $this->assertSame(404, $this->request()['statusCode']);
        $this->assertCount(1, $this->calls);
    }
    public function testInProgressRejectsInconsistentDatesStatesAndContext(): void
    {
        foreach ([
            "UPDATE atencion_incidencia SET fecha_inicio=NULL",
            "UPDATE atencion_incidencia SET fecha_inicio='2019-12-31 23:00:00'",
            "UPDATE atencion_incidencia SET fecha_inicio='2099-01-01 00:00:00'",
            "UPDATE atencion_incidencia SET fecha_inicio='2020-02-30 00:00:00'",
            "UPDATE atencion_incidencia SET fecha_aceptacion=NULL",
            "UPDATE atencion_incidencia SET fecha_cierre='2020-01-02 00:00:00'",
            "UPDATE atencion_incidencia SET estado='Finalizada'",
            "UPDATE atencion_incidencia SET estado='Rechazada'",
            "UPDATE atencion_incidencia SET estado='Cancelada'",
            "UPDATE incidencia SET estado='Pendiente'",
            "UPDATE incidencia SET estado='Resuelta'",
            "UPDATE incidencia SET fecha_resolucion='2020-01-02 00:00:00'",
            'UPDATE atencion_incidencia SET id_cuadrilla=2',
            "INSERT INTO atencion_incidencia VALUES(2,1,1,'Aceptada',NULL,'2020-01-01',NULL,2)",
            'DELETE FROM usuario_cuadrilla WHERE id_usuario=2',
            "UPDATE vehiculo SET funcion_operativa='REGULAR' WHERE id_vehiculo=2",
            "UPDATE asignacion_vehiculo_operativa SET fecha_fin='2020-01-02',id_usuario_finaliza=1,motivo_cierre='Test'",
            "UPDATE vehiculo SET estado='En Mantenimiento' WHERE id_vehiculo=2",
            'UPDATE vehiculo SET activo=0 WHERE id_vehiculo=2',
        ] as $sql) {
            $this->resetDatabase(); $this->startAttention(); $this->db->exec($sql);
            $this->assertSame(409, $this->request()['statusCode'], $sql);
            $this->assertSame([], $this->calls, $sql);
        }
    }
    public function testInProgressRevalidatesPermissionMembershipAndCompletion(): void
    {
        foreach ([
            ['DELETE FROM rol_permiso WHERE id_permiso=5', 403],
            ['DELETE FROM usuario_cuadrilla WHERE id_usuario=2', 409],
            ['UPDATE incidencia SET id_cuadrilla=2', 404],
            ["UPDATE vehiculo SET funcion_operativa='REGULAR' WHERE id_vehiculo=2", 409],
            ["UPDATE asignacion_vehiculo_operativa SET fecha_fin='2020-01-02',id_usuario_finaliza=1,motivo_cierre='Test'", 409],
            ["UPDATE atencion_incidencia SET estado='Finalizada',fecha_cierre='2020-01-02'; UPDATE incidencia SET estado='Resuelta'", 409],
            ['UPDATE incidencia SET longitud=-56.162', 409],
        ] as [$sql, $status]) {
            $this->resetDatabase(); $this->startAttention();
            $result = $this->request(fn() => $this->db->exec($sql));
            $this->assertSame($status, $result['statusCode'], $sql);
            $this->assertNull($result['data']); $this->assertCount(1, $this->calls);
        }
    }
    public function testSessionOnlyAuthorizationAndNoTiBypass(): void
    {
        foreach ([null, 4, '2 OR 1=1', [], 2147483648] as $actor) {
            $_SESSION = ['usuario' => ['id_usuario' => $actor, 'roles' => ['ADMINISTRADOR_TI']]];
            $result = $this->request();
            $this->assertSame($actor === 4 ? 403 : 401, $result['statusCode']);
            $this->assertSame([], $this->calls);
        }
    }
    public function testOwnershipAndIdorDoNotContactOsrm(): void
    {
        $_SESSION['usuario']['id_usuario'] = 3;
        $this->assertSame(404, $this->request()['statusCode']);
        $_SESSION['usuario']['id_usuario'] = 2;
        $q = $this->query(); $q['id_incidencia'] = '99';
        $this->assertSame(404, $this->request(query: $q)['statusCode']);
        $this->assertSame([], $this->calls);
    }
    public function testStrictInputRejectsDestinationAndIdentity(): void
    {
        $invalid = [];
        foreach (['id_usuario', 'id_cuadrilla', 'destino_latitud', 'destino_longitud', 'url', 'id_asignacion_vehiculo'] as $key) {
            $q = $this->query(); $q[$key] = '1'; $invalid[] = $q;
        }
        foreach (['NaN', 'INF', '91', [], true, '1e2', '', '-34,9'] as $value) {
            $q = $this->query(); $q['origen_latitud'] = $value; $invalid[] = $q;
        }
        $q = $this->query(); $q['origen_longitud'] = '181'; $invalid[] = $q;
        $q = $this->query(); unset($q['id_incidencia']); $invalid[] = $q;
        foreach ($invalid as $q) $this->assertSame(400, $this->request(query: $q)['statusCode']);
        $this->assertSame([], $this->calls);
        $this->assertSame(405, (new RutaApoyoController($this->db))->get($this->query(), 'POST')['statusCode']);
    }
    public function testInvalidOperationalContexts(): void
    {
        $cases = [
            ["UPDATE usuario SET activo='Inactivo' WHERE id_usuario=2", 401],
            ['DELETE FROM rol_permiso WHERE id_permiso=5', 403],
            ['DELETE FROM rol_permiso WHERE id_permiso=3', 403],
            ["UPDATE usuario_rol SET sector='TI' WHERE id_usuario=2", 403],
            ["UPDATE usuario_rol SET fecha_hasta='2020-01-02' WHERE id_usuario=2", 403],
            ['DELETE FROM usuario_cuadrilla WHERE id_usuario=2', 409],
            ["UPDATE usuario_cuadrilla SET fecha_inicio='2099-01-01' WHERE id_usuario=2", 409],
            ["INSERT INTO usuario_cuadrilla(id_usuario,id_cuadrilla,fecha_inicio) VALUES(2,2,'2020-01-01')", 409],
            ['DELETE FROM asignacion_vehiculo_operativa', 409],
            ["UPDATE asignacion_vehiculo_operativa SET fecha_inicio='2099-01-01'", 409],
            ["UPDATE asignacion_vehiculo_operativa SET fecha_fin='2020-01-02',id_usuario_finaliza=1,motivo_cierre='Test'", 409],
            ['UPDATE vehiculo SET activo=0 WHERE id_vehiculo=2', 409],
            ["UPDATE vehiculo SET estado='En Mantenimiento' WHERE id_vehiculo=2", 409],
            ["UPDATE vehiculo SET funcion_operativa='REGULAR' WHERE id_vehiculo=2", 409],
            ['DELETE FROM usa WHERE id_usa=3', 409],
            ['INSERT INTO usa(id_cuadrilla,id_vehiculo) VALUES(1,2)', 409],
            ["UPDATE incidencia SET estado='Resuelta'", 409],
            ["UPDATE incidencia SET fecha_resolucion='2020-01-02'", 409],
            ["UPDATE atencion_incidencia SET estado='Asignada'", 409],
            ["UPDATE atencion_incidencia SET estado='En atención',fecha_inicio='2020-01-02'", 409],
            ['UPDATE atencion_incidencia SET id_cuadrilla=2', 409],
            ['UPDATE incidencia SET latitud=NULL,longitud=NULL', 422],
            ['UPDATE incidencia SET longitud=NULL', 422],
            ['UPDATE incidencia SET latitud=91', 422],
        ];
        foreach ($cases as [$sql, $status]) {
            $this->resetDatabase(); $this->db->exec($sql);
            $this->assertSame($status, $this->request()['statusCode'], $sql);
            $this->assertSame([], $this->calls, $sql);
            $this->assertFalse($this->db->inTransaction());
        }
    }
    public function testContainerLocationAndWasteCompatibility(): void
    {
        $this->db->exec('INSERT INTO contenedor VALUES(1,-34.901,-56.161,1,1); UPDATE incidencia SET id_contenedor=1,latitud=NULL,longitud=NULL');
        $this->assertSame('contenedor', $this->request()['data']['destino']['fuente']);
        $this->db->exec('UPDATE contenedor SET id_tipo_residuo=2');
        $this->assertSame(409, $this->request()['statusCode']);
        $this->db->exec('UPDATE contenedor SET id_tipo_residuo=1,activo=0');
        $this->assertSame(409, $this->request()['statusCode']);
    }
    public function testRevalidationDiscardsChangesDuringHttp(): void
    {
        foreach ([
            ['DELETE FROM rol_permiso WHERE id_permiso=5', 403],
            ["UPDATE usuario SET activo='Inactivo' WHERE id_usuario=2", 401],
            ['UPDATE incidencia SET id_cuadrilla=2', 404],
            ['UPDATE incidencia SET longitud=-56.162', 409],
            ["UPDATE atencion_incidencia SET estado='En atención',fecha_inicio='2020-01-02'", 409],
            ["UPDATE asignacion_vehiculo_operativa SET fecha_fin='2020-01-02',id_usuario_finaliza=1,motivo_cierre='Test'; INSERT INTO asignacion_vehiculo_operativa(id_cuadrilla,id_vehiculo,fecha_inicio,id_usuario_asigna) VALUES(1,2,'2020-01-03',1)", 409],
            ['UPDATE vehiculo SET capacidad_carga=2 WHERE id_vehiculo=2', 409],
            ['DELETE FROM usuario_cuadrilla WHERE id_usuario=2', 409],
        ] as [$sql, $status]) {
            $this->resetDatabase();
            $result = $this->request(fn() => $this->db->exec($sql));
            $this->assertSame($status, $result['statusCode'], $sql);
            $this->assertNull($result['data']); $this->assertCount(1, $this->calls);
        }
    }
    public function testProviderErrorsAreSafeAndStillRevalidate(): void
    {
        foreach ([
            [['status' => 400, 'body' => '{"code":"NoRoute"}'], 422, 'sin_ruta'],
            [['status' => 400, 'body' => '{"code":"NoSegment"}'], 422, 'sin_ruta'],
            [['error' => 'timeout'], 504, 'timeout'],
            [['status' => 429, 'body' => 'internal secret'], 503, 'limite_motor'],
            [['status' => 200, 'body' => 'internal secret'], 502, 'respuesta_motor'],
            [['status' => 500, 'body' => 'internal secret'], 502, 'respuesta_motor'],
            [['error' => 'secret connection error'], 503, 'indisponible'],
        ] as [$response, $status, $code]) {
            $result = $this->request(response: $response);
            $this->assertSame($status, $result['statusCode']); $this->assertSame($code, $result['code']);
            $this->assertNull($result['data']); $this->assertStringNotContainsString('secret', json_encode($result));
            $this->assertStringNotContainsString('osrm.invalid', json_encode($result));
        }
        $result = $this->request(fn() => $this->db->exec('DELETE FROM rol_permiso WHERE id_permiso=5'), ['error' => 'timeout']);
        $this->assertSame(403, $result['statusCode']);
    }
    public function testRejectsExcessiveSnapAndMalformedGeometry(): void
    {
        $body = json_decode($this->response()['body'], true);
        $invalid = [];
        $b = $body; $b['waypoints'][0]['distance'] = 51; $invalid[] = [$b, 422];
        $b = $body; $b['waypoints'][1]['location'] = [-56.2, -34.901]; $invalid[] = [$b, 422];
        $b = $body; $b['routes'][0]['distance'] = -1; $invalid[] = [$b, 502];
        $b = $body; $b['routes'][0]['duration'] = '30'; $invalid[] = [$b, 502];
        $b = $body; $b['routes'][0]['geometry']['type'] = 'Polygon'; $invalid[] = [$b, 502];
        $b = $body; $b['routes'][0]['geometry']['coordinates'][0] = [-56.3, -34.9]; $invalid[] = [$b, 502];
        $b = $body; $b['routes'][0]['geometry']['coordinates'][0] = [-56.16, 91]; $invalid[] = [$b, 502];
        $b = $body; $b['waypoints'] = []; $invalid[] = [$b, 502];
        $b = $body; $b['waypoints'][0] = 'invalid'; $invalid[] = [$b, 502];
        $b = $body; $b['routes'][0] = 'invalid'; $invalid[] = [$b, 502];
        foreach ($invalid as [$b, $status]) {
            $this->assertSame($status, $this->request(response: ['status' => 200, 'body' => json_encode($b)])['statusCode']);
        }
    }
    public function testNoOperationalWritesEvenOnSuccessAndFailure(): void
    {
        foreach (['Aceptada', 'En atención'] as $state) {
            $this->resetDatabase();
            if ($state === 'En atención') $this->startAttention();
            $tables = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = $this->db->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
                foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
                    $this->db->exec("CREATE TRIGGER deny_{$table}_{$event} BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT,'Escritura prohibida'); END");
                }
            }
            $this->assertSame(200, $this->request()['statusCode']);
            $this->assertSame(422, $this->request(response: ['status' => 400, 'body' => '{"code":"NoRoute"}'])['statusCode']);
            foreach ($tables as $table) $this->assertSame($before[$table], $this->db->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC));
        }
    }
    public function testConfigurationIsExplicitAndBounded(): void
    {
        foreach (['file:///etc/passwd', 'http://user:pass@osrm', 'http://osrm?x=1', 'http://osrm#x', ''] as $url) {
            try { new OsrmClient($url); $this->fail('Configuración insegura aceptada.'); }
            catch (RutaVialException $e) { $this->assertSame(503, $e->getCode()); }
        }
        foreach ([[0, 50], [9, 50], [5, INF], [5, 101], [5, 0]] as [$timeout, $snap]) {
            try { new OsrmClient('http://osrm:5000', $timeout, $snap); $this->fail('Límite inválido aceptado.'); }
            catch (RutaVialException $e) { $this->assertSame('configuracion', $e->reason); }
        }
        $this->assertSame(503, (new RutaApoyoController(null))->get($this->query())['statusCode']);
    }
    public function testRealHttpAnonymousMethodsAndCrossSiteHeaders(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $address = stream_socket_get_name($socket, false); fclose($socket);
        $process = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/f52_http_router.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process); fclose($pipes[0]);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        try {
            $ready = false; $deadline = microtime(true) + 5;
            do {
                $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
                if ($probe) { fclose($probe); $ready = true; break; }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($ready);
            foreach ([['GET', '', 401], ['OPTIONS', '', 405], ['POST', '', 405], ['GET', 'Sec-Fetch-Site: cross-site', 403]] as [$method, $header, $status]) {
                $context = stream_context_create(['http' => ['method' => $method, 'header' => $header, 'ignore_errors' => true, 'timeout' => 5]]);
                $raw = file_get_contents('http://' . $address . '/?' . http_build_query($this->query()), false, $context);
                $result = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $this->assertStringContainsString(' ' . $status . ' ', $http_response_header[0]);
                $this->assertSame($status, $result['statusCode']); $this->assertNull($result['data']);
                $this->assertContains('Cache-Control: no-store', $http_response_header);
                $this->assertContains('Content-Type: application/json; charset=UTF-8', $http_response_header);
                $this->assertNotContains('Access-Control-Allow-Origin: *', $http_response_header);
                if ($status === 405) $this->assertContains('Allow: GET', $http_response_header);
            }
        } finally { proc_terminate($process); fclose($pipes[1]); fclose($pipes[2]); proc_close($process); }
    }
    public function testEnvironmentConfigurationHasNoImplicitPublicFallback(): void
    {
        $names = ['ZEMYNA_OSRM_URL', 'ZEMYNA_OSRM_TIMEOUT', 'ZEMYNA_OSRM_MAX_SNAP_METERS'];
        $previous = []; foreach ($names as $name) $previous[$name] = getenv($name);
        try {
            foreach ($names as $name) putenv($name);
            try { OsrmClient::fromEnvironment(); $this->fail('URL ausente aceptada.'); }
            catch (RutaVialException $e) { $this->assertSame('configuracion', $e->reason); }
            putenv('ZEMYNA_OSRM_URL=http://osrm:5000');
            $this->assertInstanceOf(OsrmClient::class, OsrmClient::fromEnvironment());
            foreach (['ZEMYNA_OSRM_TIMEOUT=0', 'ZEMYNA_OSRM_TIMEOUT=9', 'ZEMYNA_OSRM_MAX_SNAP_METERS=0', 'ZEMYNA_OSRM_MAX_SNAP_METERS=NaN'] as $setting) {
                putenv('ZEMYNA_OSRM_TIMEOUT=5'); putenv('ZEMYNA_OSRM_MAX_SNAP_METERS=50'); putenv($setting);
                try { OsrmClient::fromEnvironment(); $this->fail('Configuración inválida aceptada.'); }
                catch (RutaVialException $e) { $this->assertSame(503, $e->getCode()); }
            }
        } finally { foreach ($previous as $name => $value) putenv($value === false ? $name : $name . '=' . $value); }
    }
}
