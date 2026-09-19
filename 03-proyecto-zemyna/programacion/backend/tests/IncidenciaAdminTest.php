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
}
