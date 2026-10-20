<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../controllers/NovedadController.php';

class NovedadTest extends TestCase {
    private function connection(array $rows): object {
        return new class($rows) {
            public string $sql = '';
            public function __construct(private array $rows) {}
            public function prepare($sql): object {
                $this->sql = $sql;
                return new class($this->rows) {
                    public function __construct(private array $rows) {}
                    public function execute(): bool { return true; }
                    public function fetchAll($mode): array { return $this->rows; }
                };
            }
        };
    }
    private function row(): array {
        return ['id' => '7', 'tipo_problema' => 'Contenedor Desbordado', 'fecha' => '2026-09-27 08:30:00',
            'direccion' => 'Av. Brasil 123', 'estado' => 'Pendiente'];
    }
    public function testSoloPublicaCamposPermitidosSinDatosPersonales(): void {
        $row = $this->row() + ['email' => 'privado@example.org', 'telefono' => '099123456',
            'descripcion' => 'Mi nombre es vecino', 'tracking_number' => 'INC-2026-ABCDE', 'foto_url' => 'privada.jpg'];
        $result = (new NovedadController($this->connection([$row])))->listar();
        $this->assertSame(200, $result['statusCode']);
        $this->assertSame([['id' => 7, 'tipo' => 'Incidencia', 'titulo' => 'Reporte de contenedor desbordado',
            'fecha' => $row['fecha'], 'direccion' => $row['direccion'], 'estado' => $row['estado']]], $result['data']);
    }
    public function testCategoriaLibreNoSeExponeYEstadoDesconocidoSeOmite(): void {
        $row = $this->row(); $row['tipo_problema'] = 'Llamar a 099123456';
        $unknown = $row; $unknown['estado'] = 'Texto privado';
        $result = (new NovedadController($this->connection([$row, $unknown])))->listar();
        $this->assertCount(1, $result['data']);
        $this->assertSame('Incidencia reportada en contenedor', $result['data'][0]['titulo']);
        $this->assertStringNotContainsString('099123456', json_encode($result));
    }
    public function testConsultaLimitadaOrdenadaYDeColumnasPublicas(): void {
        $db = $this->connection([]);
        $result = (new NovedadController($db))->listar();
        $this->assertSame([], $result['data']);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('ORDER BY i.fecha_reporte DESC, i.id_incidencia DESC', $db->sql);
        $this->assertStringContainsString('LIMIT 20', $db->sql);
        $this->assertStringContainsString('c.activo = 1', $db->sql);
        $this->assertStringContainsString('i.fecha_reporte <= CURRENT_TIMESTAMP', $db->sql);
        foreach (['*', 'descripcion', 'tracking_number', 'usuario', 'telefono', 'email', 'foto', 'solicitud'] as $private) {
            $this->assertStringNotContainsString($private, $db->sql);
        }
    }
    public function testFalloDeConexionNoSeConfundeConListadoVacio(): void {
        $result = (new NovedadController(null))->listar();
        $this->assertSame(500, $result['statusCode']);
        $this->assertFalse($result['success']);
    }
    public function testExcepcionSqlNoExponeDetalles(): void {
        $db = new class { public function prepare($sql) { throw new PDOException('SQL secreto'); } };
        $result = (new NovedadController($db))->listar();
        $this->assertSame(500, $result['statusCode']);
        $this->assertStringNotContainsString('SQL secreto', json_encode($result));
    }
    public function testEndpointBloqueaEscriturasAntesDeAbrirConexion(): void {
        $source = file_get_contents(__DIR__ . '/../api/novedades.php');
        $gate = strpos($source, "!== 'GET'");
        $this->assertNotFalse($gate);
        $this->assertLessThan(strpos($source, 'new Database()'), $gate);
        $this->assertStringContainsString('http_response_code(405)', $source);
    }
}
