<?php

use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../controllers/IncidenciaController.php';

final class IncidenciaMapTest extends TestCase
{
    private PDO $db;
    private IncidenciaController $controller;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // Columnas reales utilizadas por el mapa. Coordenadas anulables aquí para probar datos inválidos heredados.
        $this->db->exec("CREATE TABLE contenedor (id_contenedor INTEGER PRIMARY KEY, codigo TEXT, latitud NUMERIC, longitud NUMERIC, activo INTEGER);
            CREATE TABLE incidencia (id_incidencia INTEGER PRIMARY KEY AUTOINCREMENT, tracking_number TEXT, descripcion TEXT,
                fecha_reporte TEXT, estado TEXT, prioridad TEXT, tipo_problema TEXT, id_contenedor INTEGER, id_ruta INTEGER, id_usuario INTEGER);
            INSERT INTO contenedor VALUES (1, 'C-001', -34.91, -56.15, 1), (2, 'C-002', NULL, -56.15, 1),
                (3, 'C-003', 91, -56.15, 1), (4, 'C-004', -34.91, -181, 1),
                (5, 'C-005', -34.91, -56.15, 0), (6, 'C-006', -33, -56, 1);");
        $this->insert(1, 1, 'Pendiente', 'Alta');
        $this->insert(2, 1, 'Resuelta', 'Baja');
        foreach ([3 => 2, 4 => 3, 5 => 4, 6 => 5, 7 => 6, 8 => null, 9 => 999] as $id => $container) {
            $this->insert($id, $container);
        }
        $this->db->exec('ALTER TABLE incidencia ADD COLUMN latitud NUMERIC; ALTER TABLE incidencia ADD COLUMN longitud NUMERIC');
        $this->controller = new IncidenciaController($this->db);
    }

    private function insert(int $id, ?int $container, string $state = 'Pendiente', string $priority = 'Media'): void
    {
        $stmt = $this->db->prepare('INSERT INTO incidencia (id_incidencia,tracking_number,descripcion,fecha_reporte,estado,prioridad,tipo_problema,id_contenedor,id_ruta,id_usuario) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$id, 'INC-2026-' . strtoupper(str_pad(dechex($id), 5, '0', STR_PAD_LEFT)), 'Descripción privada con datos personales',
            '2026-09-01 10:00:00', $state, $priority, 'Contenedor Desbordado', $container, $container === null ? 1 : null, 7]);
    }

    private function viewport(array $changes = []): array
    {
        return $changes + ['south' => -35, 'north' => -34.8, 'west' => -56.3, 'east' => -56, 'zoom' => 14];
    }

    public function testSoloIncidenciasConContenedorActivoYCoordenadasValidasEnViewport(): void
    {
        $result = $this->controller->getMap($this->viewport());
        $this->assertSame(200, $result['statusCode']);
        $this->assertSame([2, 1], array_column($result['data'], 'id_incidencia'));
        $this->assertEquals(-34.91, $result['data'][0]['latitud']);
        $this->assertEquals(-56.15, $result['data'][0]['longitud']);
        $this->assertSame('C-001', $result['data'][0]['contenedor_codigo']);
        $this->assertSame(['returned' => 2, 'hasMore' => false, 'limit' => 300], $result['meta']);
    }

    /** @dataProvider validFilters */
    public function testFiltrosCambianLosRegistrosDevueltos(array $filters, array $ids): void
    {
        $result = $this->controller->getMap($this->viewport($filters));
        $this->assertSame(200, $result['statusCode']);
        $this->assertSame($ids, array_column($result['data'], 'id_incidencia'));
    }

    public static function validFilters(): array
    {
        return [
            'estado' => [['estado' => 'Pendiente'], [1]],
            'prioridad' => [['prioridad' => 'Baja'], [2]],
            'ambos' => [['estado' => 'Resuelta', 'prioridad' => 'Baja'], [2]],
            'respuesta vacía' => [['estado' => 'En Proceso'], []],
            'límite menor' => [['limit' => 1], [2]],
            'área vacía' => [['west' => -56.1, 'east' => -56.0], []],
        ];
    }

    /** @dataProvider invalidFilters */
    public function testRechazaFiltrosInvalidosSinNecesitarConexion(array $filters): void
    {
        $controller = new IncidenciaController(null);
        $result = $controller->getMap($this->viewport($filters));
        $this->assertSame(400, $result['statusCode']);
        $this->assertFalse($result['success']);
        $this->assertSame([], $result['data']);
    }

    public static function invalidFilters(): array
    {
        return array_map(fn($filters) => [$filters], [
            ['estado' => 'Asignada'], ['prioridad' => 'Urgente'], ['estado' => []], ['estado' => ''],
            ['prioridad' => null], ['estado' => "' OR 1=1 --"], ['north' => []], ['south' => null],
            ['north' => INF], ['east' => NAN], ['west' => 'texto'], ['north' => 91], ['south' => -91],
            ['west' => -181], ['east' => 181], ['north' => -35], ['east' => -56.3],
            ['north' => -34], ['east' => -55], ['limit' => 301], ['limit' => 0], ['limit' => []],
            ['limit' => '1.5'], ['zoom' => 20], ['zoom' => -1], ['zoom' => []], ['zoom' => true], ['zoom' => '14.5']
        ]);
    }

    public function testLimitesObligatorios(): void
    {
        $this->assertSame(400, $this->controller->getMap([])['statusCode']);
    }

    public function testZoomBajoNoConsultaBase(): void
    {
        $result = (new IncidenciaController(null))->getMap($this->viewport(['zoom' => 12]));
        $this->assertSame(200, $result['statusCode']);
        $this->assertSame([], $result['data']);
    }

    public function testLimiteMaximoEIndicadorDeMasResultados(): void
    {
        for ($id = 10; $id <= 310; $id++) $this->insert($id, 1);
        $result = $this->controller->getMap($this->viewport());
        $this->assertCount(300, $result['data']);
        $this->assertSame(['returned' => 300, 'hasMore' => true, 'limit' => 300], $result['meta']);
        $this->assertSame(310, $result['data'][0]['id_incidencia']);
        $stmt = (new Incidencia($this->db))->readForMap(-35, -34.8, -56.3, -56, 301);
        $this->assertCount(301, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testCamposPublicosNoIncluyenIdentidadDescripcionNiTracking(): void
    {
        $result = $this->controller->getMap($this->viewport());
        $expected = ['id_incidencia', 'estado', 'prioridad', 'tipo_problema', 'fecha_reporte', 'latitud', 'longitud', 'contenedor_codigo'];
        foreach ($result['data'] as $row) {
            $this->assertSame($expected, array_keys($row));
            foreach (['email', 'telefono', 'id_usuario', 'nombre', 'descripcion', 'tracking_number', 'ruta', 'foto', 'id_cuadrilla'] as $field) {
                $this->assertArrayNotHasKey($field, $row);
            }
        }
        $this->assertStringNotContainsString('privada', json_encode($result));
    }

    public function testTipoProblemaHistoricoLibreNoSeDifunde(): void
    {
        $this->db->exec("UPDATE incidencia SET tipo_problema = 'Nombre y teléfono de una persona' WHERE id_incidencia = 1");
        $result = $this->controller->getMap($this->viewport(['estado' => 'Pendiente']));
        $this->assertNull($result['data'][0]['tipo_problema']);
        $this->assertStringNotContainsString('persona', json_encode($result));
    }

    public function testErrorDePersistenciaControlado(): void
    {
        $this->db->exec('DROP TABLE incidencia');
        $result = $this->controller->getMap($this->viewport());
        $this->assertSame(500, $result['statusCode']);
        $this->assertSame([], $result['data']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result));
    }

    public function testSinConexionDevuelve500(): void
    {
        $this->assertSame(500, (new IncidenciaController(null))->getMap($this->viewport())['statusCode']);
    }
    public function testMapaConservaTipoNormalizadoConAcentosEnJson(): void
    {
        $type = 'Contenedor Roto/Dañado';
        $stmt = $this->db->prepare('UPDATE incidencia SET tipo_problema = ? WHERE id_incidencia = 1');
        $stmt->execute([$type]);
        $result = $this->controller->getMap($this->viewport(['estado' => 'Pendiente']));
        $decoded = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($type, $decoded['data'][0]['tipo_problema']);
        $this->assertSame(bin2hex($type), bin2hex($decoded['data'][0]['tipo_problema']));
    }
    public function testCargaActivaExcluyeHistorialResuelto(): void
    {
        $result = $this->controller->getMap($this->viewport(['activas' => '1']));
        $this->assertSame([1], array_column($result['data'], 'id_incidencia'));
        $this->assertSame([], $this->controller->getMap($this->viewport(['activas' => '1', 'estado' => 'Resuelta']))['data']);
    }
}
