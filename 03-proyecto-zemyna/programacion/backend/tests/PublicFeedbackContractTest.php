<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/Solicitud.php';

class PublicFeedbackContractTest extends TestCase
{
    public function testSolicitudSinConexionDistingueFalloDeRegistroInexistente(): void
    {
        $model = new Solicitud(null);

        $this->expectException(PersistenceException::class);
        $model->findPublicByTracking('REF-2026-ABCDE');
    }

    /** @dataProvider trackingQueryResultProvider */
    public function testConsultaValidaDistingueFilaDeAusencia(?array $row, ?array $expected): void
    {
        $statement = new class($row) {
            public string $boundTracking = '';
            public function __construct(private ?array $row) {}
            public function bindValue(string $parameter, mixed $value): void {
                if ($parameter === ':tracking_number') $this->boundTracking = $value;
            }
            public function execute(): bool { return true; }
            public function fetch(int $mode): array|false { return $this->row ?? false; }
        };
        $connection = new class($statement) {
            public function __construct(private object $statement) {}
            public function prepare(string $query): object { return $this->statement; }
        };
        $model = new Solicitud($connection);

        $result = $model->findPublicByTracking('REF-2026-ABCDE');

        $this->assertSame($expected, $result);
        $this->assertSame('REF-2026-ABCDE', $statement->boundTracking);
    }

    public static function trackingQueryResultProvider(): array
    {
        $row = [
            'tracking_number' => 'REF-2026-ABCDE',
            'estado' => 'Pendiente',
            'fecha' => '2026-09-04 10:00:00',
            'tipo_solicitud' => 'Reciclables'
        ];
        return ['con fila' => [$row, $row], 'sin filas' => [null, null]];
    }

    public function testSolicitudPropagaExcepcionPdoDeConsulta(): void
    {
        $connection = new class {
            public function prepare(string $query): object {
                throw new PDOException('SQLSTATE interno');
            }
        };
        $model = new Solicitud($connection);

        $this->expectException(PDOException::class);
        $model->findPublicByTracking('REF-2026-ABCDE');
    }

    public function testSolicitudBloqueaMetodosNoPostAntesDeAbrirConexion(): void
    {
        $source = file_get_contents(__DIR__ . '/../api/solicitud.php');
        $gatePosition = strpos($source, "if (\$method !== 'POST')");
        $databasePosition = strpos($source, 'new Database()');

        $this->assertNotFalse($gatePosition);
        $this->assertNotFalse($databasePosition);
        $this->assertLessThan($databasePosition, $gatePosition);
        $this->assertStringContainsString('http_response_code(405)', $source);
        $this->assertStringNotContainsString('case "PUT"', $source);
        $this->assertStringNotContainsString('case "DELETE"', $source);
        $this->assertStringContainsString('$controller->create(', $source);
    }

    public function testIncidenciasMantieneListadoProtegidoYTrackingPublico(): void
    {
        $source = file_get_contents(__DIR__ . '/../api/incidencias.php');

        $this->assertStringContainsString("array_key_exists('tracking_number', \$_GET)", $source);
        $this->assertStringContainsString('$controller->getPublicByTracking(', $source);
        $this->assertStringContainsString("requirePermission('incidencia.consultar'", $source);
        $this->assertLessThan(
            strpos($source, "requirePermission('incidencia.consultar'"),
            strpos($source, '$controller->getPublicByTracking(')
        );
    }

    public function testFrontendConservaTrackingCuandoFallaLaFoto(): void
    {
        $source = file_get_contents(__DIR__ . '/../../frontend/public/mapa.js');
        $trackingPosition = strpos($source, 'const trackingNumber = json?.data?.tracking_number');
        $photoTryPosition = strpos($source, 'try {', strpos($source, "formData.append('foto'"));
        $confirmationPosition = strpos($source, 'mostrarConfirmacionReporte(trackingNumber', $trackingPosition);

        $this->assertNotFalse($trackingPosition);
        $this->assertNotFalse($photoTryPosition);
        $this->assertNotFalse($confirmationPosition);
        $this->assertLessThan($photoTryPosition, $trackingPosition);
        $this->assertLessThan($confirmationPosition, $photoTryPosition);
        $this->assertStringContainsString('La incidencia fue registrada, pero no se pudo adjuntar la fotografía.', $source);
    }

    public function testFrontendDetectaRespuestaVaciaYJsonInvalidoSinParseoDirectoEnElEnvio(): void
    {
        $source = file_get_contents(__DIR__ . '/../../frontend/public/mapa.js');
        $flowStart = strpos($source, "submitReporteButton.addEventListener('click'");
        $flowEnd = strpos($source, 'const trackingForm', $flowStart);
        $flow = substr($source, $flowStart, $flowEnd - $flowStart);

        $this->assertStringContainsString('const body = await response.text()', $source);
        $this->assertStringContainsString("body.trim() === ''", $source);
        $this->assertStringContainsString("includes('application/json')", $source);
        $this->assertStringContainsString('JSON.parse(body)', $source);
        $this->assertStringNotContainsString('response.json()', $flow);
        $this->assertStringNotContainsString('fotoResponse.json()', $flow);
        $this->assertStringNotContainsString('Unexpected end of JSON input', $source);
    }
}
