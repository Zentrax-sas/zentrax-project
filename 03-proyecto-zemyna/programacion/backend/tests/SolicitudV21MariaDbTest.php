<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../controllers/SolicitudController.php';
require_once __DIR__ . '/../controllers/IncidenciaController.php';
require_once __DIR__ . '/../models/AtencionSolicitud.php';

/** Solo servidor descartable TEMP/zemyna-f61-<hex>/data. Nunca la instancia habitual. */
final class SolicitudV21MariaDbTest extends TestCase
{
    private ?PDO $db = null;
    private string $database;
    private string $root = __DIR__ . '/../../base-datos/database/sql/';
    private array $historical;
    protected function setUp(): void
    {
        $dsn = getenv('ZEMYNA_F61_MARIADB_DSN');
        if (!$dsn) $this->markTestSkipped('MariaDB F6.1 descartable no configurada.');
        $db = new PDO($dsn, 'root', getenv('ZEMYNA_F61_MARIADB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $datadir = str_replace('\\', '/', $db->query('SELECT @@datadir')->fetchColumn());
        $temp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/';
        if (!str_starts_with(strtolower($datadir), strtolower($temp)) || !preg_match('~/zemyna-f61-[a-f0-9]+/data/$~i', $datadir)) {
            $this->fail('Servidor rechazado: no es MariaDB descartable F6.1.');
        }
        $this->db = $db;
        $this->database = 'f61_test_' . bin2hex(random_bytes(6));
        $db->exec('CREATE DATABASE ' . $this->database . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $db->exec('USE ' . $this->database);
        // La sección anterior a V21 conserva el schema V20. No reconstruir filas habituales.
        $schema = explode('-- V21 STRUCTURE', file_get_contents($this->root . 'schema.sql'))[0];
        $db->exec($schema);
        $db->exec("INSERT INTO centro(id_centro,nombre,direccion) VALUES(1,'Test','Test');
            INSERT INTO usuario(id_usuario,nombre,apellido,email,contrasena,fecha_registro,id_centro) VALUES(1,'Test','Test','test@test.invalid','x','2020-01-01',1);
            INSERT INTO cuadrilla(id_cuadrilla,nombre,turno,id_centro) VALUES(1,'Test','Matutino',1);
            INSERT INTO tipo_residuo(id_tipo_residuo,nombre) VALUES(27,'Papel y cartón'),(38,'Plástico'),(49,'Residuos voluminosos');
            INSERT INTO vehiculo(id_vehiculo,id_tipo_residuo,matricula,marca,modelo,capacidad_carga,estado,funcion_operativa) VALUES(1,27,'TEST','Test','Test',1,'Disponible','APOYO');
            INSERT INTO asignacion_vehiculo_operativa(id_asignacion_vehiculo,id_cuadrilla,id_vehiculo,fecha_inicio,id_usuario_asigna) VALUES(1,1,1,'2020-01-01',1);
            INSERT INTO rol(nombre) VALUES('OPERARIO'),('RESPONSABLE_SECTORIAL'),('ADMINISTRATIVO_OPERATIVO'),('INSPECTOR'),('ADMINISTRADOR_TI');");
        $mode = $db->query('SELECT @@session.sql_mode')->fetchColumn();
        $db->exec("SET SESSION sql_mode=''");
        for ($i = 1; $i <= 9; $i++) {
            $stmt = $db->prepare('INSERT INTO solicitud(tracking_number,fecha,descripcion,direccion,estado,id_tipo_residuo,email,telefono,tipo_solicitud) VALUES(?,?,?,?,?,?,?,?,?)');
            $stmt->execute(['REF-2026-' . sprintf('%05X', $i), '2026-01-01', 'Histórica', 'Test', $i === 9 ? 'Programada' : 'Pendiente', 27, 'test@test.invalid', '099111111', $i === 1 ? '' : 'Reciclables']);
        }
        $db->exec('SET SESSION sql_mode=' . $db->quote($mode));
        $this->historical = $db->query('SELECT * FROM solicitud ORDER BY id_solicitud')->fetchAll(PDO::FETCH_ASSOC);
        $db->exec(file_get_contents($this->root . 'migration_v21_solicitud_operativa.sql'));
    }
    protected function tearDown(): void
    {
        if ($this->db && isset($this->database)) $this->db->exec('DROP DATABASE ' . $this->database);
        $this->db = null;
    }
    private function grants(): string
    {
        return explode('-- END V21 GRANTS', explode('-- BEGIN V21 GRANTS', file_get_contents($this->root . 'migration_v21_solicitud_operativa.sql'))[1])[0];
    }
    public function testHistoricosPermisosYInstalacionLimpiaEquivalentes(): void
    {
        $rows = $this->db->query('SELECT * FROM solicitud ORDER BY id_solicitud')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $i => $row) {
            $this->assertSame($this->historical[$i], array_intersect_key($row, $this->historical[$i]));
            $this->assertSame(array_fill(0, 7, null), array_values(array_diff_key($row, $this->historical[$i])));
        }
        $this->assertSame([], (new AtencionSolicitud($this->db))->history(1));
        $this->db->exec($this->grants()); // Concesiones reejecutables sin duplicados.
        $actual = $this->db->query("SELECT CONCAT(r.nombre,':',p.nombre) FROM rol r JOIN rol_permiso rp ON rp.id_rol=r.id_rol JOIN permiso p ON p.id_permiso=rp.id_permiso WHERE p.nombre LIKE 'solicitud.%' ORDER BY r.nombre,p.nombre")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['ADMINISTRATIVO_OPERATIVO:solicitud.consultar','ADMINISTRATIVO_OPERATIVO:solicitud.modificar','OPERARIO:solicitud.operar','RESPONSABLE_SECTORIAL:solicitud.consultar','RESPONSABLE_SECTORIAL:solicitud.modificar'], $actual);
        $definitions = [];
        foreach (['solicitud','atencion_solicitud'] as $table) $definitions[$table] = preg_replace('/AUTO_INCREMENT=\d+ /', '', $this->db->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM)[1]);
        $this->db->exec(file_get_contents($this->root . 'schema.sql'));
        foreach ($definitions as $table => $expected) $this->assertSame($expected, preg_replace('/AUTO_INCREMENT=\d+ /', '', $this->db->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM)[1]));
    }
    public function testConstraintsEsencialesYReaplicacionRechazada(): void
    {
        $this->db->exec("INSERT INTO atencion_solicitud(id_solicitud,id_cuadrilla,id_asignacion_vehiculo,estado,fecha_asignacion,id_usuario_asigna) VALUES(1,1,1,'Asignada','2026-01-01',1)");
        foreach ([
            "INSERT INTO atencion_solicitud(id_solicitud,id_cuadrilla,id_asignacion_vehiculo,estado,fecha_asignacion,id_usuario_asigna) VALUES(1,1,1,'Asignada','2026-01-01',1)",
            "UPDATE atencion_solicitud SET id_asignacion_vehiculo=999",
            "UPDATE atencion_solicitud SET id_usuario_asigna=999",
            "UPDATE atencion_solicitud SET estado='Finalizada'",
            "UPDATE atencion_solicitud SET fecha_aceptacion='2026-01-02'",
            "UPDATE atencion_solicitud SET estado='Aceptada',fecha_aceptacion='2025-01-01',id_usuario_acepta=1",
            "UPDATE atencion_solicitud SET estado='En atención',fecha_inicio='2026-01-02',id_usuario_inicia=1",
            "UPDATE atencion_solicitud SET estado='Rechazada',fecha_cierre='2026-01-02',id_usuario_cierra=1,motivo_cierre=' '",
            "UPDATE solicitud SET fecha_finalizacion='2026-01-02' WHERE id_solicitud=1",
            "UPDATE solicitud SET estado='Cancelada',fecha_cancelacion='2026-01-02' WHERE id_solicitud=1",
            "UPDATE solicitud SET id_usuario_confirma_residuo=1 WHERE id_solicitud=1",
            'DELETE FROM asignacion_vehiculo_operativa WHERE id_asignacion_vehiculo=1',
            'DELETE FROM solicitud WHERE id_solicitud=1'
        ] as $sql) {
            try { $this->db->exec($sql); $this->fail('Restricción ausente: ' . $sql); }
            catch (PDOException $e) { $this->assertContains((int)$e->errorInfo[1], [1062,1451,1452,4025]); }
        }
        $this->db->exec("UPDATE atencion_solicitud SET estado='Interrumpida',fecha_cierre='2026-01-02',id_usuario_cierra=1,motivo_cierre='Reasignación';
            INSERT INTO atencion_solicitud(id_solicitud,id_cuadrilla,id_asignacion_vehiculo,estado,fecha_asignacion,id_usuario_asigna) VALUES(1,1,1,'Asignada','2026-01-02',1)");
        $this->assertCount(2, (new AtencionSolicitud($this->db))->history(1));
        try { $this->db->exec(file_get_contents($this->root . 'migration_v21_solicitud_operativa.sql')); $this->fail('No debe reaplicar DDL'); }
        catch (PDOException $e) { $this->assertSame(4025, (int)$e->errorInfo[1]); }
    }
    public function testAltaRealConResiduoExplicitoYTrackingPrivado(): void
    {
        $controller = new SolicitudController($this->db);
        $body = ['descripcion'=>'Papel', 'direccion'=>'Test', 'email'=>'test@test.invalid','telefono'=>'099111111','tipo_solicitud'=>'Reciclables'];
        $this->assertSame(400, $controller->create($body)['statusCode']);
        $this->assertSame(400, $controller->create($body + ['id_tipo_residuo'=>999])['statusCode']);
        $response = $controller->create($body + ['id_tipo_residuo'=>38,'estado'=>'Finalizada','fecha'=>'2099-01-01','tracking_number'=>'CLIENT','fecha_confirmacion_residuo'=>'2099-01-01','id_usuario_confirma_residuo'=>1]);
        $this->assertSame(201, $response['statusCode']);
        $stmt = $this->db->prepare('SELECT * FROM solicitud WHERE tracking_number=?'); $stmt->execute([$response['tracking_number']]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('38', (string)$row['id_tipo_residuo']);
        $this->assertSame('Pendiente', $row['estado']); $this->assertSame($row['fecha'], $row['fecha_confirmacion_residuo']);
        $this->assertNull($row['id_usuario_confirma_residuo']);
        $public = (new IncidenciaController($this->db))->getPublicByTracking($response['tracking_number']);
        $this->assertSame(['tracking_number','estado','fecha_reporte','tipo_problema'], array_keys($public['data']));
        $historical = (new IncidenciaController($this->db))->getPublicByTracking('REF-2026-00001');
        $this->assertSame(200, $historical['statusCode']);
    }
}
