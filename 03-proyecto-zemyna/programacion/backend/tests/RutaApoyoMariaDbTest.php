<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../controllers/RutaApoyoController.php';
require_once __DIR__ . '/fixtures/solicitud_admin_base.php';

final class RutaApoyoMariaDbTest extends TestCase
{
    use SolicitudAdminBase;
    protected PDO $db;
    private PDO $server;
    private PDO $writer;
    private string $name;
    private array $session;
    private int $calls = 0;
    protected function setUp(): void
    {
        $dsn = getenv('ZEMYNA_F52B_MARIADB_DSN');
        if (!$dsn) $this->markTestSkipped('Servidor MariaDB F5.2B descartable no configurado.');
        if (!preg_match('/^mysql:host=127\.0\.0\.1;port=([0-9]+);charset=utf8mb4$/D', $dsn, $parts)
            || (int) $parts[1] === 3306) throw new RuntimeException('DSN descartable inválido.');
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        $this->server = new PDO($dsn, 'root', '', $options);
        $dir = strtolower(str_replace('\\', '/', $this->server->query('SELECT @@datadir')->fetchColumn()));
        $temporary = strtolower(str_replace('\\', '/', sys_get_temp_dir())) . '/';
        $this->assertStringStartsWith($temporary, $dir);
        $this->assertMatchesRegularExpression('~/zemyna-f52b-[a-f0-9]{32}/data/$~D', $dir);
        $this->name = 'f52b_test_' . bin2hex(random_bytes(6));
        $this->server->exec('CREATE DATABASE ' . $this->name . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->db = new PDO($dsn . ';dbname=' . $this->name, 'root', '', $options);
        $this->writer = new PDO($dsn . ';dbname=' . $this->name, 'root', '', $options);
        $this->db->exec(file_get_contents(__DIR__ . '/../../base-datos/database/sql/schema.sql'));
        $this->seed();
        $this->db->exec("DELETE FROM participa; DELETE FROM recorrido;
            DELETE FROM asignacion_vehiculo_operativa WHERE id_asignacion_vehiculo=2;
            UPDATE asignacion_vehiculo_operativa SET id_vehiculo=2 WHERE id_asignacion_vehiculo=1;
            UPDATE incidencia SET id_cuadrilla=1,id_ruta=NULL,latitud=-34.901,longitud=-56.161 WHERE id_incidencia=1;
            INSERT INTO atencion_incidencia(id_incidencia,id_cuadrilla,estado,origen,fecha_registro,id_usuario_registra,fecha_aceptacion,id_usuario_acepta)
            VALUES(1,1,'Aceptada','Asignacion','2020-01-01',1,'2020-01-01',2)");
        $this->session = $_SESSION ?? []; $_SESSION = ['usuario' => ['id_usuario' => 2]];
    }
    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->inTransaction()) $this->db->rollBack();
        if (isset($this->writer) && $this->writer->inTransaction()) $this->writer->rollBack();
        if (isset($this->name) && preg_match('/^f52b_test_[a-f0-9]{12}$/D', $this->name)) $this->server->exec('DROP DATABASE ' . $this->name);
        if (isset($this->session)) $_SESSION = $this->session;
    }
    private function request(?Closure $during = null, ?array $response = null): array
    {
        $factory = fn() => new OsrmClient('http://osrm.invalid:5000', 3, 50,
            function () use ($during, $response): array {
                $this->calls++;
                $this->assertFalse($this->db->inTransaction());
                if ($during) {
                    // Escritor real en otra conexión durante el intervalo HTTP del lector.
                    $this->writer->beginTransaction();
                    try { $during($this->writer); $this->writer->commit(); }
                    catch (Throwable $e) { $this->writer->rollBack(); throw $e; }
                }
                return $response ?? ['status' => 200, 'body' => json_encode(['code' => 'Ok',
                    'waypoints' => [['distance' => 0, 'location' => [-56.16,-34.9]], ['distance' => 0, 'location' => [-56.161,-34.901]]],
                    'routes' => [['distance' => 150, 'duration' => 30, 'geometry' => ['type' => 'LineString',
                        'coordinates' => [[-56.16,-34.9],[-56.161,-34.901]]]]]], JSON_THROW_ON_ERROR)];
            });
        return (new RutaApoyoController($this->db, $factory))->get(['id_incidencia' => '1', 'origen_latitud' => '-34.9', 'origen_longitud' => '-56.16']);
    }
    private function assertDiscarded(array $result, int $status): void
    {
        $this->assertSame($status, $result['statusCode']); $this->assertNull($result['data']);
        $this->assertFalse($this->db->inTransaction()); $this->assertSame(1, $this->calls);
    }
    public function testOfficialSchemaCurrentPermissionsAndReadonlyQueries(): void
    {
        $before = $this->contents(); $r = $this->request();
        $this->assertSame(200, $r['statusCode']); $this->assertTrue($r['data']['consultiva']);
        $this->assertSame('OSRM', $r['data']['motor']); $this->assertNotEmpty($r['data']['advertencias']);
        $this->assertSame($before, $this->contents());
        $r = $this->request(response: ['status' => 400, 'body' => '{"code":"NoRoute"}']);
        $this->assertSame(422, $r['statusCode']); $this->assertSame($before, $this->contents());
    }
    private function contents(): array
    {
        $result = [];
        foreach ($this->db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (!preg_match('/^[a-z_]+$/D', $table)) throw new RuntimeException('Tabla inesperada.');
            $rows = array_map(static fn($r) => json_encode($r, JSON_THROW_ON_ERROR), $this->db->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC));
            sort($rows, SORT_STRING); $result[$table] = $rows;
        }
        ksort($result); return $result;
    }
    public function testOwnershipAndEffectivePermissionsBeforeHttp(): void
    {
        $_SESSION['usuario']['id_usuario'] = 3;
        $this->assertSame(404, $this->request()['statusCode']);
        $_SESSION['usuario']['id_usuario'] = 4;
        $_SESSION['usuario']['roles'] = ['ADMINISTRADOR_TI'];
        $this->assertSame(403, $this->request()['statusCode']);
        $_SESSION['usuario']['id_usuario'] = 2;
        $this->db->exec('DELETE FROM rol_permiso WHERE id_permiso=5');
        $this->assertSame(403, $this->request()['statusCode']); $this->assertSame(0, $this->calls);
    }
    public function testPermissionRevocationWhileAwaitingOsrm(): void
    { $this->assertDiscarded($this->request(fn(PDO $w) => $w->exec('DELETE FROM rol_permiso WHERE id_permiso=5')), 403); }
    public function testConcurrentReassignmentDiscardsRoute(): void
    {
        $this->assertDiscarded($this->request(fn(PDO $w) => $w->exec("UPDATE atencion_incidencia SET estado='Interrumpida',fecha_cierre='2020-01-02',id_usuario_cierra=1,motivo_cierre='Reasignación' WHERE id_incidencia=1;
            UPDATE incidencia SET id_cuadrilla=2 WHERE id_incidencia=1;
            INSERT INTO atencion_incidencia(id_incidencia,id_cuadrilla,estado,origen,fecha_registro,id_usuario_registra) VALUES(1,2,'Asignada','Asignacion','2020-01-02',1)")), 404);
    }
    public function testClosingV19WhileAwaitingOsrm(): void
    { $this->assertDiscarded($this->request(fn(PDO $w) => $w->exec("UPDATE asignacion_vehiculo_operativa SET fecha_fin='2020-01-02',id_usuario_finaliza=1,motivo_cierre='Fin' WHERE id_asignacion_vehiculo=1")), 409); }
    public function testReplacingV19EvenWithSameVehicleDiscardsRoute(): void
    {
        $this->assertDiscarded($this->request(fn(PDO $w) => $w->exec("UPDATE asignacion_vehiculo_operativa SET fecha_fin='2020-01-02',id_usuario_finaliza=1,motivo_cierre='Cambio' WHERE id_asignacion_vehiculo=1;
            INSERT INTO asignacion_vehiculo_operativa(id_cuadrilla,id_vehiculo,fecha_inicio,id_usuario_asigna) VALUES(1,2,'2020-01-03',1)")), 409);
    }
    public function testChangedDestinationDiscardsRoute(): void
    { $this->assertDiscarded($this->request(fn(PDO $w) => $w->exec('UPDATE incidencia SET longitud=-56.162 WHERE id_incidencia=1')), 409); }
    public function testMembershipEndsWhileAwaitingOsrm(): void
    { $this->assertDiscarded($this->request(fn(PDO $w) => $w->exec("UPDATE usuario_cuadrilla SET fecha_fin='2020-01-02',id_usuario_finaliza=1 WHERE id_usuario=2")), 409); }
    public function testFutureV19AndEndedRoleAreRejected(): void
    {
        $this->db->exec("UPDATE asignacion_vehiculo_operativa SET fecha_inicio='2099-01-01' WHERE id_asignacion_vehiculo=1");
        $this->assertSame(409, $this->request()['statusCode']);
        $this->db->exec("UPDATE asignacion_vehiculo_operativa SET fecha_inicio='2020-01-01' WHERE id_asignacion_vehiculo=1; UPDATE usuario_rol SET fecha_hasta='2020-01-02' WHERE id_usuario=2");
        $this->assertSame(403, $this->request()['statusCode']); $this->assertSame(0, $this->calls);
    }
    public function testConsultationWithSelectOnlyDatabaseAccount(): void
    {
        $reader = 'f52reader_' . bin2hex(random_bytes(5));
        $password = bin2hex(random_bytes(16));
        $account = "'" . $reader . "'@'127.0.0.1'";
        $this->server->exec('CREATE USER ' . $account . ' IDENTIFIED BY ' . $this->server->quote($password));
        try {
            $this->server->exec('GRANT SELECT ON `' . $this->name . '`.* TO ' . $account);
            $this->db = new PDO(getenv('ZEMYNA_F52B_MARIADB_DSN') . ';dbname=' . $this->name, $reader, $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $before = $this->contents(); $result = $this->request();
            $this->assertSame(200, $result['statusCode']);
            $this->assertSame($before, $this->contents());
            // Demuestra que la cuenta realmente no puede escribir.
            try { $this->db->exec('UPDATE incidencia SET prioridad=prioridad WHERE id_incidencia=1'); $this->fail('Escritura autorizada inesperadamente.'); }
            catch (PDOException $error) { $this->assertSame(1142, (int) $error->errorInfo[1]); }
        } finally { $this->server->exec('DROP USER ' . $account); }
    }
}
