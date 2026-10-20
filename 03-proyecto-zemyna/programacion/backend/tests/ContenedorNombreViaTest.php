<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
require_once __DIR__ . '/../models/Contenedor.php';
require_once __DIR__ . '/../config/database.php';

/** Pruebas SQL de solo lectura: evalúan constantes, sin consultar ni modificar tablas. */
final class ContenedorNombreViaTest extends TestCase {
    private PDO $db;
    protected function setUp(): void {
        $db = (new Database())->getConnection();
        if (!$db) $this->fail('Se necesita MySQL/MariaDB para evaluar las expresiones SQL');
        $this->db = $db;
    }
    public static function direcciones(): array {
        return [
            ['Emancipación 4746', 'Emancipación'],
            ['Emancipación 4626,4630', 'Emancipación'],
            ['Emancipación 5485,5487,5497,5499,5497 BIS', 'Emancipación'],
            ['Timote 5394,5396,5398', 'Timote'],
            ['Camino Carlos María de Pena 5361', 'Camino Carlos María de Pena'],
            ['Oficial 3 6057', 'Oficial 3'],
            ['Oficial 1 6102', 'Oficial 1'],
            ['Emancipación 5280 BIS', 'Emancipación'],
            ['  Emancipación   4626, 4630 bis  ', 'Emancipación'],
            ['Oficial 3', 'Oficial 3'],
            ['Ruta 8', 'Ruta 8'],
            ['18 de Julio', '18 de Julio'],
            ['18 de Julio 1234', '18 de Julio'],
        ];
    }
    #[DataProvider('direcciones')]
    public function testExtraeViaSinAlterarNumerosDelNombre(string $entrada, string $esperado): void {
        $expression = Contenedor::expresionNombreVia('direccion');
        $stmt = $this->db->prepare("SELECT $expression FROM (SELECT :entrada AS direccion) datos");
        $stmt->execute([':entrada' => $entrada]);
        $this->assertSame($esperado, $stmt->fetchColumn());
    }
    public function testDeduplicaDespuesDeExtraerVia(): void {
        $expression = Contenedor::expresionNombreVia('direccion');
        $stmt = $this->db->prepare("SELECT DISTINCT ($expression) AS nombre FROM (
            SELECT :a AS direccion UNION ALL SELECT :b UNION ALL SELECT :c
        ) datos ORDER BY nombre LIMIT 10");
        $stmt->execute([':a' => 'Emancipación 4746', ':b' => 'Emancipación 4626,4630', ':c' => 'Emancipación 5280 BIS']);
        $this->assertSame(['Emancipación'], $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    public function testNoAdmiteFragmentosSqlExternos(): void {
        $this->expectException(InvalidArgumentException::class);
        Contenedor::expresionNombreVia('direccion; SELECT 1');
    }
}
