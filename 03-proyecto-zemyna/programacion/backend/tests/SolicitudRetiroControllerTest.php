<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../controllers/SolicitudRetiroController.php';

class SolicitudRetiroControllerTest extends TestCase
{
    private SolicitudRetiroController $controller;
    private object $model;

    protected function setUp(): void
    {
        $this->controller = new SolicitudRetiroController(null);
        $this->model = new class {
            public mixed $fecha, $descripcion, $direccion, $estado, $id_tipo_residuo;
            public mixed $telefono, $tipo_solicitud, $tipo_residuo_solicitado, $foto_url, $tracking_number;
            public bool $consentimiento_whatsapp = false;
            public $calle, $numero_referencia, $latitud, $longitud, $fecha_confirmacion_residuo;
            public int $id_solicitud = 42;
            public int $createCalls = 0;
            public bool $createResult = true;
            public ?Throwable $exception = null;
            public function tipoResiduoIdPorNombre(string $nombre): ?int { return 9; }
            public function tipoResiduoPorId(int $id): ?array {
                return $id === 72 ? ['id_tipo_residuo' => 72, 'nombre' => 'Nombre real de BD'] : null;
            }
            public function createRetiroMovil(): bool {
                $this->createCalls++;
                if ($this->exception) throw $this->exception;
                return $this->createResult;
            }
        };
        $property = new ReflectionProperty(SolicitudRetiroController::class, 'solicitud');
        $property->setAccessible(true);
        $property->setValue($this->controller, $this->model);
    }

    public function testCreaSolicitudYDevuelveDatosDeConfirmacion(): void
    {
        $result = $this->controller->crear($this->payload());

        $this->assertTrue($result['success']);
        $this->assertSame(201, $result['statusCode']);
        $this->assertSame(42, $result['data']['id_solicitud']);
        $this->assertMatchesRegularExpression('/^RET-\d{4}-[A-F0-9]{5}$/', $result['data']['tracking_number']);
        $this->assertSame('Mueble', $result['data']['tipo_residuo']);
        $this->assertSame('Pendiente', $result['data']['estado']);
        $this->assertNotEmpty($result['data']['fecha']);
        $this->assertSame('099 123 456', $result['data']['celular']);
        $this->assertFalse($result['data']['consentimiento_whatsapp']);
        $this->assertArrayNotHasKey('whatsapp_enviado', $result['data']);
    }

    public function testIdRealUsaNombreDeBaseSinConfiarEnEtiquetaCliente(): void
    {
        $data = $this->payload();
        $data['id_tipo_residuo'] = '72';
        $data['tipo_residuo'] = 'Etiqueta manipulada';
        $result = $this->controller->crear($data);
        $this->assertTrue($result['success']);
        $this->assertSame(72, $this->model->id_tipo_residuo);
        $this->assertSame('Nombre real de BD', $this->model->tipo_residuo_solicitado);
        $this->assertSame('Nombre real de BD', $result['data']['tipo_residuo']);
    }

    public function testIdInexistenteOMalformadoNoUsaAliasComoFallback(): void
    {
        foreach ([999, 0, -1, '72x', null, [], true, 72.5] as $id) {
            $data = $this->payload();
            $data['id_tipo_residuo'] = $id;
            $result = $this->controller->crear($data);
            $this->assertFalse($result['success']);
            $this->assertSame(400, $result['statusCode']);
            $this->assertArrayHasKey('id_tipo_residuo', $result['errors']);
        }
        $this->assertSame(0, $this->model->createCalls);
    }

    public function testAceptaCelularYFotoOpcionales(): void
    {
        $payload = $this->payload();
        $payload['celular'] = '';
        $result = $this->controller->crear($payload);

        $this->assertTrue($result['success']);
        $this->assertNull($result['data']['celular']);
        $resultWithPhoto = $this->controller->crear($this->payload(), 'retiro_' . str_repeat('a', 32) . '.jpg');
        $this->assertTrue($resultWithPhoto['success']);
        $this->assertSame('Mueble', $this->model->tipo_residuo_solicitado);
        $this->assertSame('retiro_' . str_repeat('a', 32) . '.jpg', $this->model->foto_url);
    }

    public function testSinCelularNoIntentaWhatsApp(): void
    {
        $payload = $this->payload();
        $payload['celular'] = '';
        $result = $this->controller->crear($payload);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['consentimiento_whatsapp']);
    }

    public function testCredencialesAusentesNoReviertenLaSolicitud(): void
    {
        putenv('WHATSAPP_ACCESS_TOKEN');
        $result = $this->controller->crear($this->payload());

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['consentimiento_whatsapp']);
        $this->assertSame(1, $this->model->createCalls);
    }

    public function testConsentimientoMarcadoPersisteSinRespuestaDeProveedor(): void
    {
        $controller = new SolicitudRetiroController(null);
        $property = new ReflectionProperty(SolicitudRetiroController::class, 'solicitud');
        $property->setValue($controller, $this->model);

        $data = $this->payload();
        $data['consentimiento_whatsapp'] = '1';
        $result = $controller->crear($data);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['consentimiento_whatsapp']);
        $this->assertTrue($this->model->consentimiento_whatsapp);
        $this->assertSame(1, $this->model->createCalls);
        $this->assertArrayNotHasKey('whatsapp_enviado', $result['data']);
        $this->assertArrayNotHasKey('whatsapp_estado', $result['data']);
    }

    protected function tearDown(): void
    {
        putenv('WHATSAPP_ACCESS_TOKEN');
        putenv('WHATSAPP_PHONE_NUMBER_ID');
        putenv('WHATSAPP_API_VERSION');
        parent::tearDown();
    }

    /** @dataProvider invalidPayloadProvider */
    public function testValidaCamposYCelular(string $field, mixed $value): void
    {
        $payload = $this->payload();
        $payload[$field] = $value;
        $result = $this->controller->crear($payload);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['statusCode']);
        $this->assertArrayHasKey($field, $result['errors']);
        $this->assertSame(0, $this->model->createCalls);
    }

    public static function invalidPayloadProvider(): array
    {
        return [
            ['tipo_residuo', 'Tipo inventado'],
            ['direccion', ''],
            ['descripcion', ''],
            ['celular', '12345'],
        ];
    }

    public function testErrorDePersistenciaNoInventaRespuestaExitosa(): void
    {
        $this->model->createResult = false;
        $result = $this->controller->crear($this->payload());

        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['statusCode']);
        $this->assertNull($result['data']);
    }

    private function payload(): array
    {
        return [
            'tipo_residuo' => 'Mueble',
            'direccion' => 'Avenida de prueba 123',
            'descripcion' => 'Mueble para retirar',
            'celular' => '099 123 456',
            'calle' => 'Avenida de prueba', 'numero_referencia' => '123', 'latitud' => -34.91, 'longitud' => -56.16,
        ];
    }
    public function testRechazaSolicitudNuevaSinPuntoOperativoYCoordenadasInvalidas(): void {
        foreach ([null, '', 'NaN', 91, []] as $lat) {
            $data = $this->payload(); $data['latitud'] = $lat;
            $result = $this->controller->crear($data);
            $this->assertSame(400, $result['statusCode']);
            $this->assertArrayHasKey('ubicacion', $result['errors']);
        }
        $this->assertSame(0, $this->model->createCalls);
    }
}
