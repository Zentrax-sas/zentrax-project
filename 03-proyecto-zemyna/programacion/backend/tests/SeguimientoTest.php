<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../controllers/IncidenciaController.php';

class SeguimientoTest extends TestCase {
    public function testFormatosInvalidosNoConsultanBase(): void {
        $controller = new IncidenciaController(null);
        foreach ([null, [], '', 'INC-2026-ZZZZZ', 'RET-26-ABCDE', 'ABC-2026-ABCDE'] as $code) {
            $this->assertSame(400, $controller->getPublicDetailByTracking($code)['statusCode']);
        }
    }

    public function testSinConexionNoSeConfundeConInexistente(): void {
        $result = (new IncidenciaController(null))->getPublicDetailByTracking('RET-2026-ABCDE');
        $this->assertSame(500, $result['statusCode']);
        $this->assertNull($result['data']);
    }

    public function testConsultaAmbosTiposConParametrosYCamposPublicos(): void {
        foreach (['INC', 'RET', 'REF'] as $prefix) {
            $tracking = $prefix . '-2026-ABCDE';
            $row = ['tracking_number' => $tracking, 'tipo_tramite' => 'Incidencia',
                'estado' => 'En revisión', 'fecha' => '2026-09-27 10:00:00',
                'direccion' => 'Rivera 123', 'descripcion' => 'Texto registrado'];
            $stmt = new class($row) {
                public string $bound = '';
                public function __construct(private array $row) {}
                public function bindValue($key, $value): void { $this->bound = $value; }
                public function execute(): bool { return true; }
                public function fetch($mode): array { return $this->row; }
            };
            $db = new class($stmt) {
                public string $sql = '';
                public function __construct(private $stmt) {}
                public function prepare($sql) { $this->sql = $sql; return $this->stmt; }
            };
            $result = (new IncidenciaController($db))->getPublicDetailByTracking(' ' . strtolower($tracking) . ' ');
            $this->assertSame(200, $result['statusCode']);
            $this->assertSame($tracking, $stmt->bound);
            $this->assertSame($row['estado'], $result['data']['estado']);
            $this->assertSame($row['direccion'], $result['data']['direccion']);
            $this->assertSame(array_keys($row), array_keys($result['data']));
            $this->assertStringContainsString($prefix === 'INC' ? 'FROM incidencia' : 'FROM solicitud', $db->sql);
            $this->assertStringNotContainsString($tracking, $db->sql);
            $this->assertStringNotContainsString('telefono', $db->sql);
            $this->assertStringNotContainsString('email', $db->sql);
            if ($prefix === 'RET') $this->assertSame('Solicitud de retiro', $result['data']['tipo_tramite']);
        }
    }

    public function testInexistenteDevuelve404(): void {
        $db = new class {
            public function prepare($sql) { return new class {
                public function bindValue($key, $value): void {}
                public function execute(): bool { return true; }
                public function fetch($mode): bool { return false; }
            }; }
        };
        $result = (new IncidenciaController($db))->getPublicDetailByTracking('RET-2026-ABCDE');
        $this->assertSame(404, $result['statusCode']);
        $this->assertFalse($result['success']);
    }

    public function testErrorSqlNoExponeDetalles(): void {
        $db = new class { public function prepare($sql) { throw new PDOException('detalle privado'); } };
        $result = (new IncidenciaController($db))->getPublicDetailByTracking('INC-2026-ABCDE');
        $this->assertSame(500, $result['statusCode']);
        $this->assertStringNotContainsString('detalle privado', json_encode($result));
    }
}
