<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../controllers/IncidenciaController.php';

class IncidenciaPublicTrackingTest extends TestCase
{
    private IncidenciaController $controller;
    private object $model;
    private bool $sessionExisted;
    private mixed $previousSession;

    protected function setUp(): void
    {
        $this->sessionExisted = array_key_exists('_SESSION', $GLOBALS);
        $this->previousSession = $GLOBALS['_SESSION'] ?? null;
        $this->controller = new IncidenciaController(null);
        $this->model = new class {
            public int $readCalls = 0;
            public mixed $readResult = null;
            public ?Throwable $exception = null;

            public function read($id = null, $page = 1, $limit = 20, $trackingNumber = null)
            {
                $this->readCalls++;
                if ($this->exception) {
                    throw $this->exception;
                }
                return $this->readResult;
            }
        };

        $property = new ReflectionProperty(IncidenciaController::class, 'incidencia');
        $property->setAccessible(true);
        $property->setValue($this->controller, $this->model);
    }

    protected function tearDown(): void
    {
        if ($this->sessionExisted) {
            $GLOBALS['_SESSION'] = $this->previousSession;
        } else {
            unset($GLOBALS['_SESSION']);
        }
    }

    public function testSeguimientoValidoEncontradoDevuelveSoloCamposPublicos(): void
    {
        $this->model->readResult = $this->statementWithRows([[
            'id_incidencia' => 8,
            'tracking_number' => 'INC-2026-ABCDE',
            'estado' => 'Pendiente',
            'fecha_reporte' => '2026-09-04 10:00:00',
            'tipo_problema' => 'Contenedor Desbordado',
            'descripcion' => 'dato interno',
            'ci' => '12345678',
            'email' => 'persona@example.com',
            'telefono' => '099000000',
            'direccion' => 'Dirección privada',
            'usuario_nombre' => 'Operador'
        ]]);

        $result = $this->controller->getPublicByTracking(' inc-2026-abcde ');

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['statusCode']);
        $this->assertSame(
            ['tracking_number', 'estado', 'fecha_reporte', 'tipo_problema'],
            array_keys($result['data'])
        );
        $this->assertSame('INC-2026-ABCDE', $result['data']['tracking_number']);
        $this->assertArrayNotHasKey('ci', $result['data']);
        $this->assertArrayNotHasKey('email', $result['data']);
        $this->assertArrayNotHasKey('telefono', $result['data']);
        $this->assertArrayNotHasKey('direccion', $result['data']);
        $this->assertArrayNotHasKey('id_incidencia', $result['data']);
        $this->assertArrayNotHasKey('usuario_nombre', $result['data']);
    }

    public function testSeguimientoValidoInexistenteDevuelve404(): void
    {
        $this->model->readResult = $this->statementWithRows([]);

        $result = $this->controller->getPublicByTracking('INC-2026-ABCDE');

        $this->assertFalse($result['success']);
        $this->assertSame(404, $result['statusCode']);
        $this->assertSame([], $result['data']);
    }

    /** @dataProvider invalidTrackingProvider */
    public function testFormatoInvalidoNoConsultaElModelo(mixed $tracking): void
    {
        $result = $this->controller->getPublicByTracking($tracking);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['statusCode']);
        $this->assertSame(0, $this->model->readCalls);
        $this->assertSame(
            'Usá el formato INC-AAAA-XXXXX o REF-AAAA-XXXXX.',
            $result['errors']['tracking_number']
        );
    }

    public static function invalidTrackingProvider(): array
    {
        return [
            'sin número' => [''],
            'demasiado corto' => ['INC-2026-ABC'],
            'caracteres inválidos' => ['INC-2026-XYZ12'],
            'prefijo inválido' => ['SOL-2026-ABCDE'],
            'texto arbitrario' => ['seguimiento']
        ];
    }

    public function testFalloDelModeloDevuelve500SinDetallesInternos(): void
    {
        $this->model->readResult = null;

        $result = $this->controller->getPublicByTracking('INC-2026-ABCDE');

        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['statusCode']);
        $this->assertStringNotContainsString('SQL', json_encode($result));
    }

    public function testExcepcionPdoDevuelve500SinFiltrarSuMensaje(): void
    {
        $this->model->exception = new PDOException('SQLSTATE password=/ruta/interna');

        $result = $this->controller->getPublicByTracking('INC-2026-ABCDE');
        $serialized = json_encode($result);

        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['statusCode']);
        $this->assertStringNotContainsString('SQLSTATE', $serialized);
        $this->assertStringNotContainsString('password', $serialized);
        $this->assertStringNotContainsString('/ruta/interna', $serialized);
    }

    public function testSeguimientoDeSolicitudEsPublicoYNoExponeDatosPersonales(): void
    {
        $solicitud = new class {
            public function findPublicByTracking(string $tracking): ?array {
                return ['tracking_number' => $tracking, 'estado' => 'Pendiente',
                    'fecha' => '2026-09-04 10:00:00', 'tipo_solicitud' => 'Reciclables'];
            }
        };
        $property = new ReflectionProperty(IncidenciaController::class, 'solicitud');
        $property->setAccessible(true);
        $property->setValue($this->controller, $solicitud);

        $result = $this->controller->getPublicByTracking('REF-2026-ABCDE');

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['statusCode']);
        $this->assertSame(['tracking_number', 'estado', 'fecha_reporte', 'tipo_problema'], array_keys($result['data']));
        foreach (['ci', 'nombre', 'email', 'telefono', 'direccion'] as $personalField) {
            $this->assertArrayNotHasKey($personalField, $result['data']);
        }
    }

    public function testSeguimientoDeSolicitudInexistenteDevuelve404(): void
    {
        $this->replaceSolicitudModel(new class {
            public function findPublicByTracking(string $tracking): ?array { return null; }
        });

        $result = $this->controller->getPublicByTracking('REF-2026-ABCDE');

        $this->assertFalse($result['success']);
        $this->assertSame(404, $result['statusCode']);
    }

    public function testSeguimientoDeSolicitudSinConexionDevuelve500(): void
    {
        $result = $this->controller->getPublicByTracking('REF-2026-ABCDE');

        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['statusCode']);
        $this->assertStringNotContainsString('conexión', json_encode($result));
    }

    public function testExcepcionPdoAlConsultarSolicitudDevuelve500Seguro(): void
    {
        $this->replaceSolicitudModel(new class {
            public function findPublicByTracking(string $tracking): ?array {
                throw new PDOException('SQLSTATE password=/ruta/interna');
            }
        });

        $result = $this->controller->getPublicByTracking('REF-2026-ABCDE');
        $serialized = json_encode($result);

        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['statusCode']);
        $this->assertStringNotContainsString('SQLSTATE', $serialized);
        $this->assertStringNotContainsString('password', $serialized);
        $this->assertStringNotContainsString('/ruta/interna', $serialized);
    }

    public function testIncidenciaAnonimaAceptaSoloCamposOperativos(): void
    {
        $_SESSION = [];
        $model = $this->replaceIncidenciaModel();

        $result = $this->controller->create([
            'descripcion' => 'Contenedor desbordado',
            'tipo_problema' => 'Contenedor Desbordado',
            'id_contenedor' => 1
        ]);
        $this->assertTrue($result['success']);
        $this->assertSame(201, $result['statusCode']);
        $this->assertNull($model->id_usuario);
        $this->assertMatchesRegularExpression('/^INC-\d{4}-[A-F0-9]{5}$/', $result['data']['tracking_number']);
        foreach (['ci', 'nombre', 'email', 'telefono'] as $personalField) {
            $this->assertArrayNotHasKey($personalField, $result['data']);
        }
    }

    /** @dataProvider anonymousIdentityProvider */
    public function testIncidenciaIgnoraSesionYPayloadDeUsuario(array $session, array $payload): void
    {
        $_SESSION = $session;
        $model = $this->replaceIncidenciaModel();

        $result = $this->controller->create(array_merge($this->validIncidenciaPayload(), $payload));

        $this->assertTrue($result['success']);
        $this->assertNull($model->id_usuario);
    }

    public static function anonymousIdentityProvider(): array
    {
        return [
            'sesión vacía' => [[], []],
            'sesión activa' => [['usuario' => ['id_usuario' => 7]], []],
            'payload malicioso' => [[], ['id_usuario' => 'usuario-ajeno']],
            'sesión y payload' => [['usuario' => ['id_usuario' => 7]], ['id_usuario' => 'usuario-ajeno']],
        ];
    }

    public function testCreacionReintentaUnDuplicadoYLuegoTieneExito(): void
    {
        $model = $this->replaceIncidenciaModel([$this->duplicateException(), true]);

        $result = $this->controller->create($this->validIncidenciaPayload());

        $this->assertTrue($result['success']);
        $this->assertSame(2, $model->createCalls);
        $this->assertCount(2, array_unique($model->trackingNumbers));
    }

    public function testCreacionReintentaDosDuplicadosYElTercerIntentoTieneExito(): void
    {
        $model = $this->replaceIncidenciaModel([
            $this->duplicateException(), $this->duplicateException(), true
        ]);

        $result = $this->controller->create($this->validIncidenciaPayload());

        $this->assertTrue($result['success']);
        $this->assertSame(3, $model->createCalls);
        $this->assertCount(3, array_unique($model->trackingNumbers));
    }

    public function testCreacionDevuelve500DespuesDeTresDuplicados(): void
    {
        $model = $this->replaceIncidenciaModel([
            $this->duplicateException(), $this->duplicateException(), $this->duplicateException()
        ]);

        $result = $this->controller->create($this->validIncidenciaPayload());

        $this->assertSame(3, $model->createCalls);
        $this->assertSame([
            'success' => false,
            'data' => null,
            'message' => 'No se pudo registrar la incidencia.',
            'errors' => ['Ocurrió un error al guardar la incidencia.'],
            'statusCode' => 500
        ], $result);
    }

    /** @dataProvider expectedPersistenceExceptionProvider */
    public function testCreacionDevuelve500SeguroAnteExcepcionDePersistencia(Throwable $exception): void
    {
        $model = $this->replaceIncidenciaModel([$exception]);

        $result = $this->controller->create($this->validIncidenciaPayload());
        $serialized = json_encode($result);

        $this->assertSame(1, $model->createCalls);
        $this->assertSame(500, $result['statusCode']);
        $this->assertSame('No se pudo registrar la incidencia.', $result['message']);
        $this->assertStringNotContainsString('SQLSTATE', $serialized);
        $this->assertStringNotContainsString('/ruta/interna', $serialized);
    }

    public static function expectedPersistenceExceptionProvider(): array
    {
        return [
            'PDO' => [new PDOException('SQLSTATE password=/ruta/interna')],
            'persistencia' => [new PersistenceException('conexión /ruta/interna')],
        ];
    }

    public function testExcepcionPdoNoDuplicadaNoSeReintenta(): void
    {
        $exception = new PDOException('SQLSTATE interno');
        $exception->errorInfo = ['HY000', 2002, 'detalle interno'];
        $model = $this->replaceIncidenciaModel([$exception, true]);

        $result = $this->controller->create($this->validIncidenciaPayload());

        $this->assertSame(1, $model->createCalls);
        $this->assertSame(500, $result['statusCode']);
    }

    /** @dataProvider programmingExceptionProvider */
    public function testErroresDeProgramacionSePropagan(Throwable $exception): void
    {
        $this->replaceIncidenciaModel([$exception]);
        $this->expectException($exception::class);

        $this->controller->create($this->validIncidenciaPayload());
    }

    public static function programmingExceptionProvider(): array
    {
        return [
            'runtime' => [new RuntimeException('error de programación')],
            'type error' => [new TypeError('tipo incorrecto')],
        ];
    }

    private function validIncidenciaPayload(): array
    {
        return [
            'descripcion' => 'Contenedor desbordado',
            'tipo_problema' => 'Contenedor Desbordado',
            'id_contenedor' => 1
        ];
    }

    private function replaceIncidenciaModel(array $outcomes = [true]): object
    {
        $model = new class($outcomes) {
            public $id_incidencia = 77;
            public $tracking_number, $descripcion, $fecha_reporte, $estado, $prioridad, $tipo_problema;
            public $id_contenedor, $id_ruta, $id_cuadrilla, $id_usuario;
            public int $createCalls = 0;
            public array $trackingNumbers = [];
            public function __construct(private array $outcomes) {}
            public function create(): bool {
                $this->createCalls++;
                $this->trackingNumbers[] = $this->tracking_number;
                $outcome = array_shift($this->outcomes);
                if ($outcome instanceof Throwable) throw $outcome;
                return (bool)$outcome;
            }
        };
        $property = new ReflectionProperty(IncidenciaController::class, 'incidencia');
        $property->setValue($this->controller, $model);
        return $model;
    }

    private function replaceSolicitudModel(object $model): void
    {
        $property = new ReflectionProperty(IncidenciaController::class, 'solicitud');
        $property->setValue($this->controller, $model);
    }

    private function duplicateException(): PDOException
    {
        $exception = new PDOException('duplicate key');
        $exception->errorInfo = ['23000', 1062, 'uk_incidencia_tracking_number'];
        return $exception;
    }

    private function statementWithRows(array $rows): object
    {
        return new class($rows) {
            public function __construct(private array $rows) {}
            public function fetchAll(int $mode): array { return $this->rows; }
        };
    }
}
