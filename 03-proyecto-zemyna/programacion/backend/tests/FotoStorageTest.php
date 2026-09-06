<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/FotoStorage.php';

class FotoStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zemyna_foto_' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) unlink($file);
        }
        if (is_dir($this->directory)) rmdir($this->directory);
    }

    public function testAceptaNombreValido(): void
    {
        $this->assertSame('incidencia_12_demo.jpg', FotoStorage::extractSafeFileName('incidencia_12_demo.jpg'));
        $this->assertTrue(FotoStorage::isSafeFileName('incidencia_12_demo.jpg'));
    }

    public function testExtraeNombreDesdeUrlAntiguaConLocalhost(): void
    {
        $this->assertSame(
            'incidencia_12_demo.jpg',
            FotoStorage::extractSafeFileName('http://localhost:80/proyecto/uploads/incidencias/incidencia_12_demo.jpg')
        );
    }

    public function testExtraeNombreDesdeRutaRelativaAnterior(): void
    {
        $this->assertSame('inc1_foto1.jpg', FotoStorage::extractSafeFileName('/uploads/incidencias/inc1_foto1.jpg'));
    }

    public function testRechazaIntentoDePathTraversal(): void
    {
        $this->assertFalse(FotoStorage::isSafeFileName('../secreto.jpg'));
        $this->assertNull(FotoStorage::extractSafeFileName('../secreto.jpg'));
        $this->assertNull(FotoStorage::resolveExistingPath($this->directory, '../secreto.jpg'));
    }

    public function testDevuelveNullParaArchivoInexistente(): void
    {
        $this->assertNull(FotoStorage::resolveExistingPath($this->directory, 'inexistente.jpg'));
    }

    /** @dataProvider invalidExtensionProvider */
    public function testRechazaExtensionNoPermitida(string $fileName): void
    {
        $this->assertFalse(FotoStorage::isSafeFileName($fileName));
        $this->assertNull(FotoStorage::extractSafeFileName($fileName));
    }

    public static function invalidExtensionProvider(): array
    {
        return ['php' => ['foto.php'], 'svg' => ['foto.svg'], 'sin extensión' => ['foto']];
    }

    public function testResuelveArchivoDentroDelDirectorioAutorizado(): void
    {
        $file = $this->directory . DIRECTORY_SEPARATOR . 'prueba.png';
        file_put_contents($file, 'contenido ficticio');
        $this->assertSame(realpath($file), FotoStorage::resolveExistingPath($this->directory, 'prueba.png'));
    }

    public function testFormatosMimePermitidos(): void
    {
        $this->assertSame('jpg', FotoStorage::extensionForMime('image/jpeg'));
        $this->assertSame('png', FotoStorage::extensionForMime('image/png'));
        $this->assertSame('webp', FotoStorage::extensionForMime('image/webp'));
        $this->assertNull(FotoStorage::extensionForMime('image/svg+xml'));
    }

    public function testValidaTamanoMaximo(): void
    {
        $this->assertTrue(FotoStorage::isAllowedSize(FotoStorage::MAX_FILE_SIZE));
        $this->assertFalse(FotoStorage::isAllowedSize(FotoStorage::MAX_FILE_SIZE + 1));
        $this->assertFalse(FotoStorage::isAllowedSize(0));
    }

    public function testUrlDeDescargaEsIndependienteDelDominio(): void
    {
        $url = FotoStorage::downloadUrl(7);
        $this->assertSame('foto.php?id=7', $url);
        $this->assertStringNotContainsString('localhost', $url);
        $this->assertStringNotContainsString('http://', $url);
        $this->assertStringNotContainsString('https://', $url);
    }

    public function testEndpointProtegeLecturaYMantieneCargaPublica(): void
    {
        $source = file_get_contents(__DIR__ . '/../api/foto.php');
        $getPosition = strpos($source, "if (\$_SERVER['REQUEST_METHOD'] === 'GET')");
        $permissionPosition = strpos($source, "requirePermission('incidencia.consultar'");
        $postPosition = strpos($source, "if (\$_SERVER['REQUEST_METHOD'] !== 'POST')");

        $this->assertNotFalse($getPosition);
        $this->assertNotFalse($permissionPosition);
        $this->assertNotFalse($postPosition);
        $this->assertGreaterThan($getPosition, $permissionPosition);
        $this->assertLessThan($postPosition, $permissionPosition);
        $this->assertStringNotContainsString("'http://'", $source);
        $this->assertStringNotContainsString('HTTP_HOST', $source);
    }

    public function testCarpetaImpideAccesoEstaticoEnApache(): void
    {
        $rule = trim(file_get_contents(__DIR__ . '/../uploads/incidencias/.htaccess'));
        $this->assertSame('Require all denied', $rule);
    }
}
