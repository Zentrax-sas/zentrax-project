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
        $this->controller = new IncidenciaController($this->db);
    }

    public function testListaRegistrosYRelacionesReales(): void
    {
        $result = $this->controller->getAll();
        $this->assertSame(200, $result['statusCode']);
        $this->assertCount(2, $result['data']);
        $this->assertSame('C-001', $result['data'][0]['contenedor_codigo']);
        $this->assertSame('Cuadrilla prueba', $result['data'][1]['cuadrilla_nombre']);
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
            'paginación' => [['page' => 2, 'limit' => 1], [2]],
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
        $result = $this->controller->updateAdministrative(['id_incidencia' => 1] + $change);
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
                $this->assertSame(200, $this->controller->updateAdministrative(['id_incidencia' => 1] + $change)['statusCode']);
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
        $this->db->exec("INSERT INTO usuario VALUES(1,'Prueba','Operario')");
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
        $this->db->exec("INSERT INTO usuario VALUES(1,'Prueba','Operario')");
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
        $this->db->exec("INSERT INTO usuario VALUES(1,'Prueba','Operario'); UPDATE contenedor SET latitud=-34.92,longitud=-56.16");
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
