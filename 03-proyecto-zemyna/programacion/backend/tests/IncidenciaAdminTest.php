<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../controllers/IncidenciaController.php';

/** Ejecuta el controlador y el SQL real sobre una base aislada en memoria. */
final class IncidenciaAdminTest extends TestCase
{
    private PDO $db;
    private IncidenciaController $controller;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("PRAGMA foreign_keys = ON;
            CREATE TABLE contenedor (id_contenedor INTEGER PRIMARY KEY, codigo TEXT);
            CREATE TABLE ruta (id_ruta INTEGER PRIMARY KEY, nombre TEXT);
            CREATE TABLE cuadrilla (id_cuadrilla INTEGER PRIMARY KEY, nombre TEXT, turno TEXT);
            CREATE TABLE usuario (id_usuario INTEGER PRIMARY KEY, nombre TEXT, apellido TEXT);
            CREATE TABLE incidencia (
                id_incidencia INTEGER PRIMARY KEY AUTOINCREMENT,
                tracking_number TEXT UNIQUE NOT NULL, descripcion TEXT NOT NULL,
                fecha_reporte TEXT NOT NULL, estado TEXT NOT NULL CHECK(estado IN ('Pendiente','En Proceso','Resuelta')),
                prioridad TEXT NOT NULL CHECK(prioridad IN ('Baja','Media','Alta')), tipo_problema TEXT NOT NULL,
                id_contenedor INTEGER REFERENCES contenedor(id_contenedor), id_ruta INTEGER REFERENCES ruta(id_ruta),
                id_cuadrilla INTEGER REFERENCES cuadrilla(id_cuadrilla), id_usuario INTEGER REFERENCES usuario(id_usuario));
            INSERT INTO contenedor VALUES(1, 'C-001');
            INSERT INTO cuadrilla VALUES(1, 'Cuadrilla prueba', 'Matutino');
            INSERT INTO incidencia VALUES(1,'INC-2026-ABCDE','Descripción original','2026-08-20 10:00:00','Pendiente','Media','Contenedor Desbordado',1,NULL,NULL,NULL);
            INSERT INTO incidencia VALUES(2,'INC-2026-ABCDF','Otro reporte','2026-08-20 11:00:00','Resuelta','Alta','Contenedor Desbordado',1,NULL,1,NULL);");
        $this->db->exec('ALTER TABLE incidencia ADD COLUMN fecha_resolucion TEXT DEFAULT NULL; ALTER TABLE contenedor ADD COLUMN latitud NUMERIC; ALTER TABLE contenedor ADD COLUMN longitud NUMERIC');
        $this->db->exec('ALTER TABLE incidencia ADD COLUMN latitud NUMERIC; ALTER TABLE incidencia ADD COLUMN longitud NUMERIC; ALTER TABLE contenedor ADD COLUMN activo INTEGER DEFAULT 1');
        $this->db->exec("ALTER TABLE contenedor ADD COLUMN direccion TEXT; ALTER TABLE contenedor ADD COLUMN id_ruta INTEGER;
            ALTER TABLE ruta ADD COLUMN zona TEXT;
            CREATE TABLE foto (id_foto INTEGER PRIMARY KEY, fecha TEXT, url TEXT, id_incidencia INTEGER);");
        $this->db->exec("ALTER TABLE usuario ADD activo TEXT DEFAULT 'Activo';
            CREATE TABLE usuario_cuadrilla (id_usuario INTEGER, id_cuadrilla INTEGER, fecha_fin TEXT);
            CREATE TABLE usuario_rol (id_usuario INTEGER, id_rol INTEGER, sector TEXT, fecha_desde TEXT, fecha_hasta TEXT);
            CREATE TABLE rol_permiso (id_rol INTEGER, id_permiso INTEGER);
            CREATE TABLE permiso (id_permiso INTEGER, nombre TEXT);
            CREATE TABLE vehiculo (id_vehiculo INTEGER PRIMARY KEY, matricula TEXT, activo INTEGER, estado TEXT);
            CREATE TABLE usa (id_usa INTEGER PRIMARY KEY, id_cuadrilla INTEGER, id_vehiculo INTEGER);
            CREATE TABLE recorrido (id_recorrido INTEGER PRIMARY KEY, id_ruta INTEGER, estado TEXT, fecha_inicio TEXT, fecha_fin TEXT);
            CREATE TABLE participa (id_participa INTEGER PRIMARY KEY, id_usa INTEGER, id_recorrido INTEGER, hora_fin TEXT);
            INSERT INTO usuario VALUES (10, 'Operario', 'Prueba', 'Activo');
            INSERT INTO usuario_cuadrilla VALUES (10, 1, NULL);
            INSERT INTO usuario_rol VALUES (10, 1, 'OPERACIONES', '2020-01-01', NULL);
            INSERT INTO permiso VALUES (1, 'recorrido.consultar'), (2, 'recorrido.operar');
            INSERT INTO rol_permiso VALUES (1, 1), (1, 2);
            INSERT INTO ruta VALUES (9, 'Operativa', 'Centro');
            INSERT INTO vehiculo VALUES (1, 'PRUEBA', 1, 'Disponible');
            INSERT INTO usa VALUES (1, 1, 1);
            INSERT INTO recorrido VALUES (1, 9, 'Pendiente', '2026-09-01 10:00:00', NULL);
            INSERT INTO participa VALUES (1, 1, 1, NULL);");
        $this->controller = new IncidenciaController($this->db);
    }

    private function assignmentPayload(array $changes = []): array {
        return array_replace(['accion' => 'asignar', 'id_incidencia' => 1, 'id_cuadrilla' => 1, 'id_recorrido' => 1, 'id_usa' => 1,
            'cuadrilla_esperada' => null, 'estado_esperado' => 'Pendiente'], $changes);
    }

    public function testAsignacionOperativaValidaPersisteSinCrearRelaciones(): void {
        $incidenceBefore = $this->db->query('SELECT * FROM incidencia WHERE id_incidencia=1')->fetch(PDO::FETCH_ASSOC);
        $usesBefore = $this->db->query('SELECT * FROM usa ORDER BY id_usa')->fetchAll(PDO::FETCH_ASSOC);
        $participationsBefore = $this->db->query('SELECT * FROM participa ORDER BY id_participa')->fetchAll(PDO::FETCH_ASSOC);
        $options = $this->controller->getAssignmentOptions();
        $this->assertSame(200, $options['statusCode']);
        $this->assertCount(1, $options['data']);
        $this->assertSame('PRUEBA', $options['data'][0]['matricula']);
        $this->assertArrayNotHasKey('id_usuario', $options['data'][0]);
        $this->assertSame(200, $this->controller->assignAdministrative($this->assignmentPayload())['statusCode']);
        $incidenceAfter = $this->db->query('SELECT * FROM incidencia WHERE id_incidencia=1')->fetch(PDO::FETCH_ASSOC);
        $incidenceBefore['id_cuadrilla'] = 1;
        $this->assertSame($incidenceBefore, $incidenceAfter);
        $this->assertSame($usesBefore, $this->db->query('SELECT * FROM usa ORDER BY id_usa')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame($participationsBefore, $this->db->query('SELECT * FROM participa ORDER BY id_participa')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame(409, $this->controller->assignAdministrative($this->assignmentPayload())['statusCode']);
        $this->assertSame(200, $this->controller->assignAdministrative($this->assignmentPayload(['id_cuadrilla'=>null,'id_recorrido'=>null,'id_usa'=>null,'cuadrilla_esperada'=>1]))['statusCode']);
        $this->assertNull($this->db->query('SELECT id_cuadrilla FROM incidencia WHERE id_incidencia=1')->fetchColumn());
    }

    /** @dataProvider unavailableAssignment */
    public function testAsignacionRevalidaConflictosSinCambios(string $sql): void {
        $this->db->exec($sql);
        $this->assertSame(409, $this->controller->assignAdministrative($this->assignmentPayload())['statusCode']);
        $this->assertNull($this->db->query('SELECT id_cuadrilla FROM incidencia WHERE id_incidencia=1')->fetchColumn());
        $this->assertFalse($this->db->inTransaction());
    }
    public static function unavailableAssignment(): array {
        return array_map(fn($sql) => [$sql], [
            "UPDATE incidencia SET estado='Resuelta' WHERE id_incidencia=1",
            "UPDATE incidencia SET estado='En Proceso' WHERE id_incidencia=1",
            "UPDATE usuario_cuadrilla SET fecha_fin='2026-09-01'",
            "UPDATE usuario SET activo='Inactivo'", "DELETE FROM rol_permiso WHERE id_permiso=2",
            "UPDATE usuario_rol SET sector='LOGISTICA'", "UPDATE usuario_rol SET fecha_hasta='2020-01-02'",
            "UPDATE recorrido SET estado='Finalizado'", "UPDATE recorrido SET estado='Cancelado'",
            "UPDATE vehiculo SET activo=0", "UPDATE vehiculo SET estado='En Mantenimiento'",
            "INSERT INTO participa VALUES(2,1,1,NULL)", "INSERT INTO usa VALUES(2,1,1)",
            "UPDATE participa SET hora_fin='12:00:00'",
            "INSERT INTO recorrido VALUES(2,9,'Pendiente','2026-09-02',NULL); INSERT INTO participa VALUES(2,1,2,NULL)",
            "INSERT INTO cuadrilla VALUES(2,'Otra','Matutino'); INSERT INTO usa VALUES(2,2,1); INSERT INTO participa VALUES(2,2,1,NULL)"
        ]);
    }
    public function testAsignacionManipuladaLegacyPublicaYPersistencia(): void {
        $this->assertSame(404, $this->controller->assignAdministrative($this->assignmentPayload(['id_incidencia'=>999]))['statusCode']);
        $this->assertSame(404, $this->controller->assignAdministrative($this->assignmentPayload(['id_cuadrilla'=>999]))['statusCode']);
        $this->assertSame(409, $this->controller->assignAdministrative($this->assignmentPayload(['id_usa'=>999]))['statusCode']);
        foreach ([['id_cuadrilla'=>'1 OR 1=1'], ['id_usuario'=>10], ['id_vehiculo'=>1], ['id_recorrido'=>null], ['cuadrilla_esperada'=>[]]] as $change) {
            $this->assertSame(400, $this->controller->assignAdministrative($this->assignmentPayload($change))['statusCode']);
        }
        $this->assertSame(400, $this->controller->updateAdministrative(['id_incidencia'=>1,'id_cuadrilla'=>1])['statusCode']);
        $legacy = $this->controller->getAll(['id'=>1])['data'][0]; $legacy['id_cuadrilla']=1;
        $this->assertSame(400, $this->controller->updateAdministrative($legacy)['statusCode']);
        $created = $this->controller->create(['descripcion'=>'Reporte', 'tipo_problema'=>'Contenedor Desbordado','id_contenedor'=>1,'id_cuadrilla'=>1]);
        $this->assertSame(201, $created['statusCode']);
        $this->assertNull($this->controller->getAll(['id'=>$created['data']['id_incidencia']])['data'][0]['id_cuadrilla']);
        $this->db->exec("CREATE TRIGGER fail_assignment BEFORE UPDATE ON incidencia BEGIN SELECT RAISE(ABORT, 'private SQL'); END");
        $result = $this->controller->assignAdministrative($this->assignmentPayload());
        $this->assertSame(500, $result['statusCode']);
        $this->assertStringNotContainsString('private SQL', json_encode($result));
        $this->assertFalse($this->db->inTransaction());
    }

    public function testBandejaOrdenaPrioridadAntiguedadYDesempate(): void {
        $this->db->exec("UPDATE incidencia SET estado = 'Pendiente';
            INSERT INTO incidencia (tracking_number,descripcion,fecha_reporte,estado,prioridad,tipo_problema) VALUES
            ('INC-2026-00003','x','2026-08-19 10:00:00','En Proceso','Alta','Contenedor Desbordado'),
            ('INC-2026-00004','x','2026-08-19 10:00:00','Pendiente','Alta','Contenedor Desbordado'),
            ('INC-2026-00005','x','2026-08-01 10:00:00','Pendiente','Baja','Contenedor Desbordado'),
            ('INC-2026-00006','x','2026-08-01 10:00:00','Resuelta','Alta','Contenedor Desbordado')");
        $result = $this->controller->getAll(['activas' => '1']);
        $this->assertSame(200, $result['statusCode']);
        $this->assertSame([3, 4, 2, 1, 5], array_column($result['data'], 'id_incidencia'));
    }

    public function testBandejaCombinaRutaDelContenedorZonaTipoEstadoPrioridadYFechas(): void {
        $this->db->exec("INSERT INTO ruta VALUES (7, 'Ruta siete', 'Centro'); UPDATE contenedor SET id_ruta=7, direccion='Calle prueba';");
        $filters = ['estado' => 'Pendiente', 'prioridad' => 'Media', 'tipo_problema' => 'Contenedor Desbordado',
            'id_ruta' => '7', 'zona' => 'Centro', 'desde' => '2026-08-20', 'hasta' => '2026-08-20'];
        $result = $this->controller->getAll($filters);
        $this->assertSame([1], array_column($result['data'], 'id_incidencia'));
        $this->assertSame('Calle prueba', $result['data'][0]['contenedor_direccion']);
        $this->assertNull($result['data'][0]['cuadrilla_nombre']);
        $this->assertSame([], $this->controller->getAll(array_replace($filters, ['zona' => 'Otra']))['data']);
        $this->db->exec('UPDATE incidencia SET id_contenedor=NULL, id_ruta=7 WHERE id_incidencia=1');
        $this->assertSame([1], array_column($this->controller->getAll($filters)['data'], 'id_incidencia'));
        $this->assertSame('Centro', $this->controller->getInboxOptions()['data']['rutas'][0]['zona']);
        $this->assertSame(['Contenedor Desbordado'], $this->controller->getInboxOptions()['data']['tipos']);
    }

    public function testBandejaPaginaExactaVeinteYRegistroAdicional(): void {
        for ($id = 3; $id <= 20; $id++) {
            $this->db->exec("INSERT INTO incidencia (tracking_number,descripcion,fecha_reporte,estado,prioridad,tipo_problema)
                VALUES ('INC-MIG-$id','x','2026-08-20 12:00:00','Pendiente','Media','Contenedor Desbordado')");
        }
        $first = $this->controller->getAll();
        $this->assertCount(20, $first['data']);
        $this->assertFalse($first['meta']['has_more']);
        $this->db->exec("INSERT INTO incidencia (tracking_number,descripcion,fecha_reporte,estado,prioridad,tipo_problema)
            VALUES ('INC-MIG-21','x','2026-08-20 12:00:00','Pendiente','Media','Contenedor Desbordado')");
        $first = $this->controller->getAll();
        $last = $this->controller->getAll(['page' => 2]);
        $this->assertTrue($first['meta']['has_more']);
        $this->assertCount(20, $first['data']);
        $this->assertCount(1, $last['data']);
        $this->assertFalse($last['meta']['has_more']);
        $this->assertCount(21, array_unique(array_merge(array_column($first['data'], 'id_incidencia'), array_column($last['data'], 'id_incidencia'))));
        $empty = $this->controller->getAll(['page' => 3]);
        $this->assertSame([], $empty['data']);
        $this->assertFalse($empty['meta']['has_more']);
    }

    public function testEvidenciaSoloDelDetalleSinRutaInterna(): void {
        $this->db->exec("INSERT INTO foto VALUES (1, '2026-08-20', '/ruta/interna/privada.jpg', 1), (2, '2026-08-20', 'otra.jpg', 2)");
        $detail = $this->controller->getAll(['id' => 1]);
        $this->assertSame([['id_foto' => 1, 'fecha' => '2026-08-20']], $detail['data'][0]['evidencias']);
        $this->assertStringNotContainsString('/ruta/interna', json_encode($detail));
        $this->assertArrayNotHasKey('evidencias', $this->controller->getPublicByTracking('INC-2026-ABCDE')['data']);
    }

    public function testListaRegistrosYRelacionesReales(): void
    {
        $result = $this->controller->getAll();
        $this->assertSame(200, $result['statusCode']);
        $this->assertCount(2, $result['data']);
        $this->assertSame('C-001', $result['data'][0]['contenedor_codigo']);
        $this->assertSame('Cuadrilla prueba', $result['data'][0]['cuadrilla_nombre']);
    }

    /** @dataProvider validFilters */
    public function testFiltrosAplicanAlResultado(array $filters, array $ids): void
    {
        $result = $this->controller->getAll($filters);
        $this->assertSame(200, $result['statusCode']);
        $this->assertSame($ids, array_column($result['data'], 'id_incidencia'));
    }

    public static function validFilters(): array
    {
        return [
            'estado' => [['estado' => 'Pendiente'], [1]],
            'prioridad' => [['prioridad' => 'Alta'], [2]],
            'tracking' => [['tracking_number' => ' inc-2026-abcde '], [1]],
            'combinados' => [['estado' => 'Resuelta', 'prioridad' => 'Alta', 'tracking_number' => 'INC-2026-ABCDF'], [2]],
            'vacío' => [['estado' => 'Resuelta', 'prioridad' => 'Media'], []],
            'paginación' => [['page' => 2, 'limit' => 1], [1]],
        ];
    }

    /** @dataProvider invalidFilters */
    public function testRechazaFiltrosInvalidos(array $filters): void
    {
        $this->assertSame(400, $this->controller->getAll($filters)['statusCode']);
    }

    public static function invalidFilters(): array
    {
        return array_map(fn($item) => [$item], [
            ['id_ruta' => '1 OR 1=1'], ['zona' => []], ['tipo_problema' => []], ['activas' => 'si'],
            ['desde' => '2026-02-30'], ['hasta' => 'ayer'], ['desde' => '2026-09-01', 'hasta' => '2026-08-01'],
            ['estado' => 'Asignada'], ['prioridad' => 'Urgente'], ['estado' => []], ['tracking_number' => []],
            ['tracking_number' => "' OR 1=1 --"], ['id' => 0], ['id' => -1], ['id' => '1x'], ['id' => []],
            ['page' => 0], ['page' => 1000001], ['page' => '2.5'], ['limit' => 101], ['limit' => []], ['limit' => 0],
        ]);
    }

    public function testDetalleExistenteEInexistente(): void
    {
        $result = $this->controller->getAll(['id' => 1]);
        $this->assertSame(200, $result['statusCode']);
        $this->assertSame('Descripción original', $result['data'][0]['descripcion']);
        $this->assertSame(404, $this->controller->getAll(['id' => 999])['statusCode']);
    }

    /** @dataProvider changes */
    public function testActualizaSoloCamposSolicitados(array $change): void
    {
        $before = $this->controller->getAll(['id' => 1])['data'][0];
        $result = ($change['id_cuadrilla'] ?? null) === 1
            ? $this->controller->assignAdministrative($this->assignmentPayload())
            : $this->controller->updateAdministrative(['id_incidencia' => 1] + $change);
        $this->assertSame(200, $result['statusCode']);
        $after = $this->controller->getAll(['id' => 1])['data'][0];
        foreach ($change as $field => $value) $this->assertSame($value, $after[$field]);
        foreach (['descripcion', 'tracking_number', 'fecha_reporte', 'id_contenedor', 'id_usuario'] as $field) {
            $this->assertSame($before[$field], $after[$field]);
        }
        if (!isset($change['estado'])) $this->assertSame($before['estado'], $after['estado']);
        if (!isset($change['prioridad'])) $this->assertSame($before['prioridad'], $after['prioridad']);
    }

    public static function changes(): array
    {
        return [[['estado' => 'En Proceso']], [['estado' => 'Resuelta']], [['prioridad' => 'Alta']], [['id_cuadrilla' => 1]], [['id_cuadrilla' => null]], [['estado' => 'Pendiente']]];
    }

    /** @dataProvider invalidChanges */
    public function testRechazaCambiosInvalidosSinModificarDatos(array $change): void
    {
        $before = $this->controller->getAll()['data'];
        $this->assertSame(400, $this->controller->updateAdministrative($change + ['id_incidencia' => 1])['statusCode']);
        $this->assertSame($before, $this->controller->getAll()['data']);
    }

    public static function invalidChanges(): array
    {
        return array_map(fn($item) => [$item], [
            ['estado' => 'Cerrada'], ['prioridad' => 'Urgente'], ['estado' => null], ['prioridad' => []],
            ['id_incidencia' => 0, 'estado' => 'Resuelta'], ['id_incidencia' => -1, 'estado' => 'Resuelta'],
            ['id_incidencia' => '1x', 'estado' => 'Resuelta'], ['id_incidencia' => [], 'estado' => 'Resuelta'],
            ['id_cuadrilla' => 0], ['id_cuadrilla' => 999], [],
        ]);
    }

    public function testActualizarInexistenteDevuelve404(): void
    {
        $this->assertSame(404, $this->controller->updateAdministrative(['id_incidencia' => 999, 'estado' => 'Resuelta'])['statusCode']);
    }

    public function testErrorDePersistenciaNoExponeDetalles(): void
    {
        $this->db->exec("CREATE TRIGGER reject_update BEFORE UPDATE ON incidencia BEGIN SELECT RAISE(ABORT, 'SQLSTATE ruta privada'); END;");
        $result = $this->controller->updateAdministrative(['id_incidencia' => 1, 'estado' => 'Resuelta']);
        $this->assertSame(500, $result['statusCode']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result));
        $this->assertSame('Pendiente', $this->controller->getAll(['id' => 1])['data'][0]['estado']);
    }

    public function testFalloDeLecturaControlado(): void
    {
        $this->db->exec('DROP TABLE incidencia');
        $this->assertSame(500, $this->controller->getAll()['statusCode']);
    }

    public function testOpcionesCuadrillasReales(): void
    {
        $result = $this->controller->getCuadrillas();
        $this->assertSame(200, $result['statusCode']);
        $this->assertSame('Cuadrilla prueba', $result['data'][0]['nombre']);
    }

    public function testRegistroPublicoYSeguimientoUsanPersistencia(): void
    {
        $created = $this->controller->create(['descripcion' => 'Reporte público', 'tipo_problema' => 'Contenedor Desbordado', 'id_contenedor' => 1]);
        $this->assertSame(201, $created['statusCode']);
        $result = $this->controller->getPublicByTracking($created['data']['tracking_number']);
        $this->assertSame(200, $result['statusCode']);
        $this->assertSame(['tracking_number', 'estado', 'fecha_reporte', 'tipo_problema'], array_keys($result['data']));
        $this->assertSame('Pendiente', $result['data']['estado']);
    }

    public function testTrackingResueltoSigueSiendoPublico(): void
    {
        $result = $this->controller->getPublicByTracking('INC-2026-ABCDF');
        $this->assertSame(200, $result['statusCode']);
        $this->assertSame('Resuelta', $result['data']['estado']);
        $this->assertSame(['tracking_number', 'estado', 'fecha_reporte', 'tipo_problema'], array_keys($result['data']));
    }

    public function testSinConexionDevuelveErroresControlados(): void
    {
        $controller = new IncidenciaController(null);
        $this->assertSame(500, $controller->getAll()['statusCode']);
        $this->assertSame(500, $controller->getCuadrillas()['statusCode']);
        $this->assertSame(500, $controller->updateAdministrative(['id_incidencia' => 1, 'estado' => 'Resuelta'])['statusCode']);
    }

    public function testIDsPublicosDebenSerPositivos(): void
    {
        foreach ([0, -1, '1x', []] as $id) {
            $result = $this->controller->create(['descripcion' => 'Reporte', 'tipo_problema' => 'Contenedor Desbordado', 'id_contenedor' => $id]);
            $this->assertSame(400, $result['statusCode']);
        }
    }

    public function testBajaValidaIDYExistencia(): void
    {
        $this->assertSame(400, $this->controller->delete(0)['statusCode']);
        $this->assertSame(404, $this->controller->delete(999)['statusCode']);
        $this->assertSame(200, $this->controller->delete(1)['statusCode']);
        $this->assertSame(404, $this->controller->getAll(['id' => 1])['statusCode']);
    }

    public function testActualizacionCompletaAnteriorSigueFuncionando(): void
    {
        $data = $this->controller->getAll(['id' => 1])['data'][0];
        $data['estado'] = 'Resuelta';
        $this->assertSame(200, $this->controller->updateAdministrative($data)['statusCode']);
        $this->assertSame('Resuelta', $this->controller->getAll(['id' => 1])['data'][0]['estado']);
    }
    public function testCaracteresEspanolesPersistenSinDobleCodificacion(): void
    {
        $text = 'Prueba UTF-8: información, recolección, contenedor dañado, año, pingüino, ¿correcto? á é í ó ú Á É Í Ó Ú ñ Ñ ü Ü ¿ ¡';
        $type = 'Contenedor Roto/Dañado';
        $created = $this->controller->create(['descripcion' => $text, 'tipo_problema' => $type, 'id_contenedor' => 1]);
        $this->assertSame(201, $created['statusCode']);
        $id = $created['data']['id_incidencia'];
        for ($i = 0; $i < 3; $i++) {
            $row = $this->controller->getAll(['id' => $id])['data'][0];
            $this->assertSame($text, $row['descripcion']);
            $this->assertSame(bin2hex($text), bin2hex($row['descripcion']));
            $this->assertSame($row, json_decode(json_encode($row, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
            $this->assertSame(200, $this->controller->updateAdministrative($row)['statusCode']);
        }
        $tracking = $this->controller->getPublicByTracking($created['data']['tracking_number']);
        $this->assertSame($type, $tracking['data']['tipo_problema']);
        $listed = $this->controller->getAll(['tracking_number' => $created['data']['tracking_number']]);
        $this->assertSame($text, $listed['data'][0]['descripcion']);
    }
    public function testInformeClasificaPaginaYOrdenaSinLimitarTotales(): void
    {
        $this->db->exec("INSERT INTO incidencia (id_incidencia,tracking_number,descripcion,fecha_reporte,estado,prioridad,tipo_problema,id_contenedor,id_ruta,id_cuadrilla,id_usuario) VALUES(3,'INC-2026-AAAAA','Prueba','2026-08-21 23:59:59','En Proceso','Baja','Contenedor Desbordado',NULL,NULL,NULL,NULL)");
        $result = $this->controller->getReport(['limit' => 1]);
        $this->assertSame(200, $result['statusCode']);
        $this->assertSame(['total' => 3, 'abiertas' => 2, 'cerradas' => 1], $result['totals']);
        $this->assertSame([3], array_column($result['data'], 'id_incidencia'));
        $second = $this->controller->getReport(['limit' => 1, 'page' => 2]);
        $this->assertSame($result['totals'], $second['totals']);
        $this->assertSame([2], array_column($second['data'], 'id_incidencia'));
        $this->assertSame(3, $second['meta']['pages']);
        $this->assertArrayNotHasKey('descripcion', $result['data'][0]);
        $this->assertNull($result['data'][0]['contenedor_codigo']);
        $open = $this->controller->getReport(['grupo' => 'abiertas']);
        $this->assertSame([3, 1], array_column($open['data'], 'id_incidencia'));
        $this->assertSame(['total' => 2, 'abiertas' => 2, 'cerradas' => 0], $open['totals']);
        $closed = $this->controller->getReport(['grupo' => 'cerradas']);
        $this->assertSame([2], array_column($closed['data'], 'id_incidencia'));
        $this->assertSame(['total' => 1, 'abiertas' => 0, 'cerradas' => 1], $closed['totals']);
        $date = $this->controller->getReport(['desde' => '2026-08-21', 'hasta' => '2026-08-21']);
        $this->assertSame([3], array_column($date['data'], 'id_incidencia'));
        $this->assertSame(1, $date['totals']['total']);
        $this->assertSame(2, $this->controller->getReport(['hasta' => '2026-08-20'])['totals']['total']);
        $this->assertSame(1, $this->controller->getReport(['desde' => '2026-08-21'])['totals']['total']);
    }

    public function testInformeVacioYPaginaFueraDeRango(): void
    {
        $result = $this->controller->getReport(['desde' => '2099-01-01']);
        $this->assertSame([], $result['data']);
        $this->assertSame(['total' => 0, 'abiertas' => 0, 'cerradas' => 0], $result['totals']);
        $this->assertSame(0, $result['meta']['pages']);
        $result = $this->controller->getReport(['page' => 9]);
        $this->assertSame([], $result['data']);
        $this->assertSame(2, $result['totals']['total']);
    }

    /** @dataProvider invalidReportFilters */
    public function testInformeRechazaFiltrosInvalidos(array $filters): void
    {
        $this->assertSame(400, $this->controller->getReport($filters)['statusCode']);
    }

    public static function invalidReportFilters(): array
    {
        return array_map(fn($filter) => [$filter], [
            ['grupo' => 'Pendiente'], ['grupo' => []], ['grupo' => "' OR 1=1 --"],
            ['page' => 0], ['page' => []], ['page' => '1.5'], ['page' => 1000001],
            ['limit' => 0], ['limit' => 101], ['limit' => []],
            ['desde' => '2026-02-29'], ['hasta' => '2026-13-01'], ['desde' => []],
            ['desde' => '2026-1-01'], ['hasta' => '2026-01-01 12:00:00'],
            ['desde' => '0999-01-01'], ['desde' => '2026-08-22', 'hasta' => '2026-08-20'],
        ]);
    }

    public function testInformeFechaValidaYErrorSeguro(): void
    {
        $this->assertSame(200, $this->controller->getReport(['desde' => '2024-02-29', 'hasta' => ''])['statusCode']);
        $this->db->exec('DROP TABLE incidencia');
        $result = $this->controller->getReport([]);
        $this->assertSame(500, $result['statusCode']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result));
    }
    public function testResolucionDelCicloActualConservaFechaYReaperturaLaLimpia(): void
    {
        foreach (['Pendiente', 'En Proceso'] as $initial) {
            $this->controller->updateAdministrative(['id_incidencia' => 1, 'estado' => $initial]);
            $before = (new DateTimeImmutable('now', new DateTimeZone('America/Montevideo')))->format('Y-m-d H:i:s');
            $this->assertSame(200, $this->controller->updateAdministrative(['id_incidencia' => 1, 'estado' => 'Resuelta', 'fecha_resolucion' => '1900-01-01'])['statusCode']);
            $row = $this->controller->getAll(['id' => 1])['data'][0];
            $date = $row['fecha_resolucion'];
            $this->assertGreaterThanOrEqual($before, $date);
            $this->assertLessThanOrEqual((new DateTimeImmutable('now', new DateTimeZone('America/Montevideo')))->format('Y-m-d H:i:s'), $date);
            $this->assertNotSame($row['fecha_reporte'], $date);
            foreach ([['prioridad' => 'Baja'], ['id_cuadrilla' => 1], ['estado' => 'Resuelta'], $row] as $change) {
                $this->assertSame(isset($change['id_cuadrilla']) && $change['id_cuadrilla'] === 1 ? 400 : 200, $this->controller->updateAdministrative(['id_incidencia' => 1] + $change)['statusCode']);
                $this->assertSame($date, $this->controller->getAll(['id' => 1])['data'][0]['fecha_resolucion']);
            }
            $report = $this->controller->getReport(['grupo' => 'cerradas']);
            $dates = array_column($report['data'], 'fecha_resolucion', 'id_incidencia');
            $this->assertSame($date, $dates[1]);
            $row['estado'] = $initial;
            $this->assertSame(200, $this->controller->updateAdministrative($row)['statusCode']);
            $this->assertNull($this->controller->getAll(['id' => 1])['data'][0]['fecha_resolucion']);
        }
    }

    public function testResolucionHistoricaDesconocidaNoSeInventa(): void
    {
        $row = $this->controller->getAll(['id' => 2])['data'][0];
        $this->assertNull($row['fecha_resolucion']);
        $this->controller->updateAdministrative($row);
        $this->assertNull($this->controller->getAll(['id' => 2])['data'][0]['fecha_resolucion']);
        $this->controller->updateAdministrative(['id_incidencia' => 2, 'estado' => 'Pendiente']);
        $this->assertNull($this->controller->getAll(['id' => 2])['data'][0]['fecha_resolucion']);
        $this->controller->updateAdministrative(['id_incidencia' => 2, 'estado' => 'Resuelta']);
        $this->assertNotNull($this->controller->getAll(['id' => 2])['data'][0]['fecha_resolucion']);
    }

    public function testUbicacionIndividualResueltaYCoordenadasAusentes(): void
    {
        $this->assertNull($this->controller->getLocation(2)['data']);
        $this->db->exec('UPDATE contenedor SET latitud=-34.91,longitud=-56.15 WHERE id_contenedor=1');
        $result = $this->controller->getLocation(2);
        $this->assertSame('Resuelta', $result['data']['estado']);
        $this->assertEquals(-34.91, $result['data']['latitud']);
        $this->assertArrayNotHasKey('tracking_number', $result['data']);
        $this->assertArrayNotHasKey('descripcion', $result['data']);
        $this->db->exec('UPDATE contenedor SET latitud=91');
        $this->assertNull($this->controller->getLocation(2)['data']);
        $this->db->exec('UPDATE incidencia SET id_contenedor=NULL WHERE id_incidencia=2');
        $this->assertNull($this->controller->getLocation(2)['data']);
        $this->assertSame(404, $this->controller->getLocation(999)['statusCode']);
        $this->assertSame(400, $this->controller->getLocation([])['statusCode']);
    }
    public function testReporteCuadrillaConContenedorUsaIdentidadDelServidor(): void
    {
        $this->db->exec("INSERT INTO usuario (id_usuario,nombre,apellido) VALUES(1,'Prueba','Operario')");
        $result = $this->controller->createCrew(['descripcion' => 'Problema durante trabajo', 'tipo_problema' => 'Contenedor Roto/Dañado',
            'id_contenedor' => 1, 'id_usuario' => 999, 'id_ruta' => 999, 'id_cuadrilla' => 999, 'estado' => 'Resuelta', 'prioridad' => 'Alta'], 1);
        $this->assertSame(201, $result['statusCode']);
        $row = $this->controller->getAll(['id' => $result['data']['id_incidencia']])['data'][0];
        $this->assertSame(1, $row['id_usuario']);
        $this->assertSame(1, $row['id_contenedor']);
        $this->assertSame('Pendiente', $row['estado']);
        $this->assertSame('Media', $row['prioridad']);
        foreach (['id_ruta', 'id_cuadrilla', 'latitud', 'longitud'] as $key) $this->assertNull($row[$key]);
    }

    public function testReporteCuadrillaConPuntoSePersisteYSeUbicaSinContenedor(): void
    {
        $this->db->exec("INSERT INTO usuario (id_usuario,nombre,apellido) VALUES(1,'Prueba','Operario')");
        $payload = ['descripcion' => 'Obstrucción observada', 'tipo_problema' => 'Obstruido por Vehículo', 'latitud' => -34.9123456, 'longitud' => -56.15];
        $result = $this->controller->createCrew($payload, 1);
        $this->assertSame(201, $result['statusCode']);
        $id = $result['data']['id_incidencia'];
        $row = $this->controller->getAll(['id' => $id])['data'][0];
        $this->assertSame($payload['descripcion'], $row['descripcion']);
        $this->assertEquals($payload['latitud'], $row['latitud']);
        $this->assertNull($row['id_contenedor']);
        $this->assertNull($row['id_ruta']);
        $this->assertSame('problema', $this->controller->getLocation($id)['data']['ubicacion_origen']);
        $this->assertSame(200, $this->controller->updateAdministrative($row)['statusCode']);
        $this->assertEquals($payload['latitud'], $this->controller->getAll(['id' => $id])['data'][0]['latitud']);
        $tracking = $this->controller->getPublicByTracking($result['data']['tracking_number']);
        $this->assertSame(['tracking_number', 'estado', 'fecha_reporte', 'tipo_problema'], array_keys($tracking['data']));
        $this->assertSame(400, $this->controller->create($payload)['statusCode']);
        $this->assertSame(401, $this->controller->createCrew($payload, null)['statusCode']);
    }

    /** @dataProvider invalidCrewPayloads */
    public function testReporteCuadrillaRechazaDatosInvalidos(array $payload): void
    {
        $before = $this->controller->getAll()['data'];
        $result = $this->controller->createCrew($payload + ['descripcion' => 'Prueba', 'tipo_problema' => 'Contenedor Desbordado'], 1);
        $this->assertSame(400, $result['statusCode']);
        $this->assertSame($before, $this->controller->getAll()['data']);
    }

    public static function invalidCrewPayloads(): array
    {
        return array_map(fn($data) => [$data], [[], ['id_contenedor' => ''], ['id_contenedor' => 999], ['id_contenedor' => []],
            ['latitud' => 91, 'longitud' => 0], ['latitud' => 0, 'longitud' => -181], ['latitud' => 'NaN', 'longitud' => 0],
            ['latitud' => '', 'longitud' => 0], ['latitud' => 0], ['latitud' => null, 'longitud' => null],
            ['latitud' => [], 'longitud' => 0], ['latitud' => true, 'longitud' => 0],
            ['id_contenedor' => 1, 'tipo_problema' => 'Inventado'], ['id_contenedor' => 1, 'descripcion' => '   '],
            ['id_contenedor' => 1, 'descripcion' => str_repeat('ñ', 501)],
        ]);
    }

    public function testContenedorConPuntoPropioYPrivacidadDelMapa(): void
    {
        $this->db->exec("INSERT INTO usuario (id_usuario,nombre,apellido) VALUES(1,'Prueba','Operario'); UPDATE contenedor SET latitud=-34.92,longitud=-56.16");
        $result = $this->controller->createCrew(['descripcion' => 'Punto observado cerca del contenedor', 'tipo_problema' => 'Contenedor Desbordado',
            'id_contenedor' => 1, 'latitud' => -34.91, 'longitud' => -56.15], 1);
        $this->assertSame(201, $result['statusCode']);
        $id = $result['data']['id_incidencia'];
        $viewport = ['south' => -35, 'north' => -34.8, 'west' => -56.3, 'east' => -56, 'zoom' => 14];
        $this->assertNotContains($id, array_column($this->controller->getMap($viewport)['data'], 'id_incidencia'));
        $admin = $this->controller->getMap($viewport + ['admin' => '1', 'activas' => '1']);
        $byId = array_column($admin['data'], null, 'id_incidencia');
        $this->assertSame('problema', $byId[$id]['ubicacion_origen']);
        $this->assertEquals(-34.91, $byId[$id]['latitud']);
        $this->assertArrayNotHasKey('id_usuario', $byId[$id]);
        $this->assertSame('problema', $this->controller->getLocation($id)['data']['ubicacion_origen']);
    }
}
