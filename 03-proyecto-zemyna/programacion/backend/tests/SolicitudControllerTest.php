<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../controllers/SolicitudController.php';

class SolicitudControllerTest extends TestCase
{
    private SolicitudController $controller;
    private object $model;

    protected function setUp(): void
    {
        $this->controller = new SolicitudController(null);
        $this->model = new class {
            public mixed $fecha, $descripcion, $direccion, $estado, $id_tipo_residuo;
            public mixed $email, $telefono, $tipo_solicitud, $tracking_number;
            public int $createCalls = 0;
            public bool $createResult = true;
            public ?Throwable $exception = null;
            public array $outcomes = [];
            public array $trackingNumbers = [];
            public function create(): bool {
                $this->createCalls++;
                $this->trackingNumbers[] = $this->tracking_number;
                $outcome = $this->outcomes ? array_shift($this->outcomes) : ($this->exception ?? $this->createResult);
                if ($outcome instanceof Throwable) throw $outcome;
                return (bool)$outcome;
            }
        };
        $property = new ReflectionProperty(SolicitudController::class, 'solicitud');
        $property->setAccessible(true);
        $property->setValue($this->controller, $this->model);
    }

    public function testSolicitudSinCiEsValidaYNoConsultaVecino(): void
    {
        $result = $this->controller->create($this->validPayload());

        $this->assertTrue($result['success']);
        $this->assertSame(201, $result['statusCode']);
        $this->assertSame(1, $this->model->createCalls);
        $this->assertFalse(property_exists($this->controller, 'vecino'));
        $this->assertArrayNotHasKey('ci', $result);
    }

    /** @dataProvider requiredFieldProvider */
    public function testCampoObligatorioAusenteDevuelve400(string $field): void
    {
        $payload = $this->validPayload();
        unset($payload[$field]);
        $result = $this->controller->create($payload);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['statusCode']);
        $this->assertArrayHasKey($field, $result['errors']);
        $this->assertSame(0, $this->model->createCalls);
    }

    public static function requiredFieldProvider(): array
    {
        return [['direccion'], ['telefono'], ['email']];
    }

    public function testEmailInvalidoDevuelve400(): void
    {
        $payload = $this->validPayload();
        $payload['email'] = 'correo-invalido';
        $result = $this->controller->create($payload);

        $this->assertSame(400, $result['statusCode']);
        $this->assertArrayHasKey('email', $result['errors']);
        $this->assertSame(0, $this->model->createCalls);
    }

    public function testTipoDeRetiroInvalidoDevuelve400(): void
    {
        $payload = $this->validPayload();
        $payload['tipo_solicitud'] = 'Otro';
        $result = $this->controller->create($payload);

        $this->assertSame(400, $result['statusCode']);
        $this->assertArrayHasKey('tipo_solicitud', $result['errors']);
        $this->assertSame(0, $this->model->createCalls);
    }

    public function testCreacionValidaDevuelveTrackingSinDatosPersonales(): void
    {
        $result = $this->controller->create($this->validPayload());
        $serialized = json_encode($result);

        $this->assertTrue($result['success']);
        $this->assertSame(201, $result['statusCode']);
        $this->assertMatchesRegularExpression('/^REF-\d{4}-[A-F0-9]{5}$/', $result['data']['tracking_number']);
        $this->assertStringNotContainsString('persona@example.com', $serialized);
        $this->assertStringNotContainsString('099123456', $serialized);
        $this->assertStringNotContainsString('Avenida de prueba', $serialized);
    }

    public function testFalloDePersistenciaDevuelve500Generico(): void
    {
        $this->model->createResult = false;
        $result = $this->controller->create($this->validPayload());

        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['statusCode']);
        $this->assertSame('Error al registrar la solicitud.', $result['message']);
    }

    public function testExcepcionPdoNoExponeDetallesInternos(): void
    {
        $this->model->exception = new PDOException('SQLSTATE password=/ruta/interna');
        $result = $this->controller->create($this->validPayload());
        $serialized = json_encode($result);

        $this->assertSame(500, $result['statusCode']);
        $this->assertStringNotContainsString('SQLSTATE', $serialized);
        $this->assertStringNotContainsString('password', $serialized);
        $this->assertStringNotContainsString('/ruta/interna', $serialized);
    }

    public function testPrimerDuplicadoReintentaYSegundoIntentoTieneExito(): void
    {
        $this->model->outcomes = [$this->duplicateException(), true];

        $result = $this->controller->create($this->validPayload());

        $this->assertTrue($result['success']);
        $this->assertSame(2, $this->model->createCalls);
        $this->assertCount(2, array_unique($this->model->trackingNumbers));
    }

    public function testDosDuplicadosReintentanYTercerIntentoTieneExito(): void
    {
        $this->model->outcomes = [$this->duplicateException(), $this->duplicateException(), true];

        $result = $this->controller->create($this->validPayload());

        $this->assertTrue($result['success']);
        $this->assertSame(3, $this->model->createCalls);
        $this->assertCount(3, array_unique($this->model->trackingNumbers));
    }

    public function testTresDuplicadosDevuelven500SinCuartoIntento(): void
    {
        $this->model->outcomes = [
            $this->duplicateException(), $this->duplicateException(), $this->duplicateException(), true
        ];

        $result = $this->controller->create($this->validPayload());

        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['statusCode']);
        $this->assertSame(3, $this->model->createCalls);
        $this->assertCount(3, array_unique($this->model->trackingNumbers));
    }

    public function testExcepcionPdoNoDuplicadaNoSeReintenta(): void
    {
        $exception = new PDOException('SQLSTATE interno');
        $exception->errorInfo = ['HY000', 2002, 'detalle interno'];
        $this->model->outcomes = [$exception, true];

        $result = $this->controller->create($this->validPayload());

        $this->assertSame(1, $this->model->createCalls);
        $this->assertSame(500, $result['statusCode']);
        $this->assertStringNotContainsString('detalle interno', json_encode($result));
    }

    public function testPersistenceExceptionDevuelve500SinDetalles(): void
    {
        $this->model->outcomes = [new PersistenceException('credencial /ruta/interna')];

        $result = $this->controller->create($this->validPayload());
        $serialized = json_encode($result);

        $this->assertSame(500, $result['statusCode']);
        $this->assertStringNotContainsString('credencial', $serialized);
        $this->assertStringNotContainsString('/ruta/interna', $serialized);
    }

    private function duplicateException(): PDOException
    {
        $exception = new PDOException('duplicate key');
        $exception->errorInfo = ['23000', 1062, 'uk_solicitud_tracking_number'];
        return $exception;
    }

    private function validPayload(): array
    {
        return ['descripcion' => 'Bolsas de papel y cartón', 'direccion' => 'Avenida de prueba 123',
            'estado' => 'Pendiente', 'id_tipo_residuo' => 2, 'email' => 'persona@example.com',
            'telefono' => '099123456', 'tipo_solicitud' => 'Reciclables'];
    }
}
