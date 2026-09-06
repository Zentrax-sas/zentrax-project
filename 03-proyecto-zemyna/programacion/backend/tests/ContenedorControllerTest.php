<?php

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../controllers/ContenedorController.php';

class ContenedorControllerTest extends TestCase
{
    private ContenedorController $controller;
    private Contenedor&MockObject $contenedor;

    protected function setUp(): void
    {
        $this->contenedor = $this->getMockBuilder(Contenedor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['read', 'readAdmin', 'countAdmin', 'readForMap', 'findByCodigo', 'create', 'update', 'delete'])
            ->getMock();

        $controllerClass = new ReflectionClass(ContenedorController::class);
        $this->controller = $controllerClass->newInstanceWithoutConstructor();
        $property = new ReflectionProperty(ContenedorController::class, 'contenedor');
        $property->setValue($this->controller, $this->contenedor);
    }

    private function statement(array $rows): object
    {
        return new class($rows) {
            public function __construct(private array $rows) {}
            public function fetchAll(int $mode): array { return $this->rows; }
        };
    }

    private function validData(array $changes = []): array
    {
        return array_merge([
            'codigo' => ' CTN-001 ',
            'capacidad' => '2000.50',
            'direccion' => ' Av. Ejemplo 123 ',
            'latitud' => '-34.9011',
            'longitud' => '-56.1645',
            'estado' => 'Disponible',
            'id_tipo_residuo' => '2',
            'id_ruta' => '3',
        ], $changes);
    }

    private function validUpdateData(array $changes = []): array
    {
        return $this->validData(array_merge(['id_contenedor' => 7], $changes));
    }

    private function validViewport(array $changes = []): array
    {
        return array_merge([
            'north' => '-34.85',
            'south' => '-34.95',
            'east' => '-56.10',
            'west' => '-56.25',
            'zoom' => '14',
        ], $changes);
    }

    private function publicContainer(int $id): array
    {
        return [
            'id_contenedor' => $id,
            'codigo' => 'IDM-' . $id,
            'direccion' => 'Ubicación de prueba',
            'latitud' => '-34.9000000',
            'longitud' => '-56.1600000',
            'estado' => 'Disponible',
        ];
    }

    private function assertResponse(array $result, bool $success, int $statusCode, string $message): void
    {
        $this->assertSame($success, $result['success']);
        $this->assertSame($statusCode, $result['statusCode']);
        $this->assertSame($message, $result['message']);
    }

    private function assertValidationResponse(array $result, string $message, array $errors): void
    {
        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertSame($message, $result['message']);
        $this->assertSame($errors, $result['errors']);
    }

    public function testGetAllUsaPaginacionPredeterminadaYMetadata(): void
    {
        $rows = [['id_contenedor' => 1, 'codigo' => 'CTN-001']];
        $this->contenedor->expects($this->once())->method('readAdmin')->with([], 1, 25)
            ->willReturn($this->statement($rows));
        $this->contenedor->expects($this->once())->method('countAdmin')->with([])->willReturn(11214);
        $result = $this->controller->getAll();
        $this->assertSame(true, $result['success']);
        $this->assertSame(200, $result['statusCode']);
        $this->assertSame($rows, $result['data']);
        $this->assertSame(['page' => 1, 'limit' => 25, 'total' => 11214, 'totalPages' => 449, 'returned' => 1], $result['meta']);
        $this->assertSame([], $result['errors']);
    }

    /** @dataProvider validAdminLimitsProvider */
    public function testGetAllAceptaLimitesAdministrativos(int $limit): void
    {
        $this->contenedor->expects($this->once())->method('readAdmin')->with([], 2, $limit)
            ->willReturn($this->statement([]));
        $this->contenedor->expects($this->once())->method('countAdmin')->willReturn(125);
        $result = $this->controller->getAll(['page' => '2', 'limit' => (string) $limit]);
        $this->assertSame($limit, $result['meta']['limit']);
        $this->assertSame((int) ceil(125 / $limit), $result['meta']['totalPages']);
    }

    public static function validAdminLimitsProvider(): array
    {
        return [[25], [50], [100]];
    }

    /** @dataProvider invalidPaginationProvider */
    public function testGetAllRechazaPaginacionInvalida(array $filters, string $error): void
    {
        $this->contenedor->expects($this->never())->method('readAdmin');
        $this->contenedor->expects($this->never())->method('countAdmin');
        $result = $this->controller->getAll($filters);
        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['statusCode']);
        $this->assertArrayHasKey($error, $result['errors']);
    }

    public static function invalidPaginationProvider(): array
    {
        return [
            'page zero' => [['page' => 0], 'page'],
            'page negative' => [['page' => -1], 'page'],
            'page decimal' => [['page' => '1.5'], 'page'],
            'page text' => [['page' => 'dos'], 'page'],
            'limit zero' => [['limit' => 0], 'limit'],
            'limit above maximum' => [['limit' => 101], 'limit'],
        ];
    }

    public function testGetAllCombinaBusquedaEstadoTipoYRuta(): void
    {
        $filters = ['search' => 'IDM-10%_=', 'estado' => 'Disponible', 'id_tipo_residuo' => '1', 'id_ruta' => '7'];
        $normalized = ['search' => 'IDM-10%_=', 'estado' => 'Disponible', 'id_tipo_residuo' => 1, 'id_ruta' => 7];
        $this->contenedor->expects($this->once())->method('readAdmin')->with($normalized, 1, 25)
            ->willReturn($this->statement([]));
        $this->contenedor->expects($this->once())->method('countAdmin')->with($normalized)->willReturn(0);
        $result = $this->controller->getAll($filters);
        $this->assertSame(['page' => 1, 'limit' => 25, 'total' => 0, 'totalPages' => 0, 'returned' => 0], $result['meta']);
    }

    /** @dataProvider invalidAdminFilterProvider */
    public function testGetAllRechazaFiltrosInvalidos(array $filters, string $field): void
    {
        $this->contenedor->expects($this->never())->method('readAdmin');
        $result = $this->controller->getAll($filters);
        $this->assertSame(400, $result['statusCode']);
        $this->assertArrayHasKey($field, $result['errors']);
    }

    public static function invalidAdminFilterProvider(): array
    {
        return [
            'state' => [['estado' => 'Operativo'], 'estado'],
            'type' => [['id_tipo_residuo' => '1.5'], 'id_tipo_residuo'],
            'route' => [['id_ruta' => '-2'], 'id_ruta'],
            'search array' => [['search' => ['IDM']], 'search'],
        ];
    }

    public function testGetAllPermitePaginaPosteriorAlTotal(): void
    {
        $this->contenedor->expects($this->once())->method('readAdmin')->with([], 999, 25)
            ->willReturn($this->statement([]));
        $this->contenedor->expects($this->once())->method('countAdmin')->willReturn(30);
        $result = $this->controller->getAll(['page' => 999]);
        $this->assertSame([], $result['data']);
        $this->assertSame(2, $result['meta']['totalPages']);
        $this->assertSame(0, $result['meta']['returned']);
    }

    /** @dataProvider adminFailureProvider */
    public function testGetAllDevuelve500SeguroAnteFalloDeDatos(mixed $failure): void
    {
        $expectation = $this->contenedor->expects($this->once())->method('readAdmin');
        $failure instanceof Throwable ? $expectation->willThrowException($failure) : $expectation->willReturn($failure);
        $this->contenedor->expects($this->never())->method('countAdmin');
        $result = $this->controller->getAll();
        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['statusCode']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result));
    }

    public static function adminFailureProvider(): array
    {
        return ['null' => [null], 'false' => [false], 'pdo' => [new PDOException('SQLSTATE secret')]];
    }

    /** @dataProvider adminCountFailureProvider */
    public function testGetAllDevuelve500SeguroAnteFalloDelConteo(mixed $failure): void
    {
        $this->contenedor->expects($this->once())->method('readAdmin')->willReturn($this->statement([]));
        $expectation = $this->contenedor->expects($this->once())->method('countAdmin');
        $failure instanceof Throwable ? $expectation->willThrowException($failure) : $expectation->willReturn($failure);
        $result = $this->controller->getAll();
        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['statusCode']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result));
    }

    public static function adminCountFailureProvider(): array
    {
        return ['null' => [null], 'pdo' => [new PDOException('SQLSTATE count secret')]];
    }

    public function testAdminSearchEscapaComodinesDeLike(): void
    {
        $model = (new ReflectionClass(Contenedor::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Contenedor::class, 'buildAdminWhere');
        [$where, $params] = $method->invoke($model, ['search' => '100%_=']);

        $this->assertStringContainsString("ESCAPE '='", $where);
        $this->assertSame('%100=%=_==%', $params[':search_codigo'][0]);
        $this->assertSame($params[':search_codigo'], $params[':search_direccion']);
        $this->assertSame($params[':search_codigo'], $params[':search_ruta']);
    }
    public function testGetMapConsultaConViewportValidoYLimiteInalterable(): void
    {
        $row = $this->publicContainer(1);
        $this->contenedor->expects($this->once())->method('readForMap')
            ->with(-34.95, -34.85, -56.25, -56.10, 501)
            ->willReturn($this->statement([$row]));

        $result = $this->controller->getMap($this->validViewport(['limit' => 11000]));

        $this->assertResponse($result, true, 200, 'Contenedores del mapa cargados correctamente.');
        $this->assertSame([$row], $result['data']);
        $this->assertSame(['returned' => 1, 'hasMore' => false, 'limit' => 500], $result['meta']);
    }

    /** @dataProvider missingViewportFieldProvider */
    public function testGetMapRechazaLimitesFaltantes(string $field): void
    {
        $filters = $this->validViewport();
        unset($filters[$field]);
        $this->contenedor->expects($this->never())->method('readForMap');

        $result = $this->controller->getMap($filters);

        $this->assertResponse($result, false, 400, 'Los límites del mapa no son válidos.');
        $this->assertArrayHasKey($field, $result['errors']);
    }

    public static function missingViewportFieldProvider(): array
    {
        return [['north'], ['south'], ['east'], ['west'], ['zoom']];
    }

    /** @dataProvider invalidViewportValueProvider */
    public function testGetMapRechazaValoresInvalidos(array $changes, string $errorKey): void
    {
        $this->contenedor->expects($this->never())->method('readForMap');

        $result = $this->controller->getMap($this->validViewport($changes));

        $this->assertResponse($result, false, 400, 'Los límites del mapa no son válidos.');
        $this->assertArrayHasKey($errorKey, $result['errors']);
    }

    public static function invalidViewportValueProvider(): array
    {
        return [
            'no numérico' => [['north' => 'norte'], 'north'],
            'latitud menor' => [['south' => -91], 'south'],
            'latitud mayor' => [['north' => 91], 'north'],
            'longitud menor' => [['west' => -181], 'west'],
            'longitud mayor' => [['east' => 181], 'east'],
            'norte igual a sur' => [['north' => -34.95], 'viewport'],
            'norte menor que sur' => [['north' => -35], 'viewport'],
            'este igual a oeste' => [['east' => -56.25], 'viewport'],
            'zoom decimal' => [['zoom' => 13.5], 'zoom'],
            'zoom mayor' => [['zoom' => 20], 'zoom'],
            'viewport excesivo' => [['north' => -34, 'south' => -35], 'viewport'],
        ];
    }

    public function testGetMapEnZoomBajoPideAcercarSinConsultarModelo(): void
    {
        $this->contenedor->expects($this->never())->method('readForMap');

        $result = $this->controller->getMap($this->validViewport(['zoom' => 12]));

        $this->assertResponse($result, true, 200, 'Acercá el mapa para ver los contenedores.');
        $this->assertSame([], $result['data']);
        $this->assertSame(['returned' => 0, 'hasMore' => false, 'limit' => 500], $result['meta']);
    }

    public function testGetMapLimita501FilasEInformaHasMore(): void
    {
        $rows = array_map(fn(int $id): array => $this->publicContainer($id), range(1, 501));
        $this->contenedor->expects($this->once())->method('readForMap')
            ->willReturn($this->statement($rows));

        $result = $this->controller->getMap($this->validViewport());

        $this->assertCount(500, $result['data']);
        $this->assertSame(['returned' => 500, 'hasMore' => true, 'limit' => 500], $result['meta']);
        $this->assertSame('Hay más contenedores en esta zona. Acercá el mapa para ver un área menor.', $result['message']);
    }

    /** @dataProvider mapRowsWithoutOverflowProvider */
    public function testGetMapNoInformaHasMoreCon500FilasOMenos(int $count): void
    {
        $rows = $count === 0 ? [] : array_map(fn(int $id): array => $this->publicContainer($id), range(1, $count));
        $this->contenedor->expects($this->once())->method('readForMap')
            ->willReturn($this->statement($rows));

        $result = $this->controller->getMap($this->validViewport());

        $this->assertFalse($result['meta']['hasMore']);
        $this->assertSame($count, $result['meta']['returned']);
    }

    public static function mapRowsWithoutOverflowProvider(): array
    {
        return ['vacío' => [0], 'uno' => [1], 'límite exacto' => [500]];
    }

    public function testGetMapExponeSolamenteCamposPublicosMinimos(): void
    {
        $row = $this->publicContainer(1) + [
            'id_ruta' => 7,
            'id_tipo_residuo' => 2,
            'activo' => 1,
            'created_at' => '2026-09-04 10:00:00',
        ];
        $this->contenedor->expects($this->once())->method('readForMap')
            ->willReturn($this->statement([$row]));

        $result = $this->controller->getMap($this->validViewport());

        $this->assertSame(
            ['id_contenedor', 'codigo', 'direccion', 'latitud', 'longitud', 'estado'],
            array_keys($result['data'][0])
        );
    }

    /** @dataProvider mapFailureProvider */
    public function testGetMapDevuelve500SeguroAnteFalloDelModelo(mixed $failure): void
    {
        $expectation = $this->contenedor->expects($this->once())->method('readForMap');
        if ($failure instanceof Throwable) {
            $expectation->willThrowException($failure);
        } else {
            $expectation->willReturn($failure);
        }

        $result = $this->controller->getMap($this->validViewport());
        $serialized = json_encode($result);

        $this->assertResponse($result, false, 500, 'No se pudieron cargar los contenedores del mapa.');
        $this->assertStringNotContainsString('SQLSTATE', $serialized);
        $this->assertStringNotContainsString('password', $serialized);
        $this->assertStringNotContainsString('/ruta/interna', $serialized);
    }

    public static function mapFailureProvider(): array
    {
        return [
            'sin conexión' => [null],
            'fallo falso' => [false],
            'excepción PDO' => [new PDOException('SQLSTATE password=/ruta/interna')],
        ];
    }

    public function testCreateRegistraContenedorConDatosNormalizados(): void
    {
        $this->contenedor->expects($this->once())->method('findByCodigo')->with('CTN-001')->willReturn(null);
        $this->contenedor->expects($this->once())->method('create')->willReturnCallback(function (): bool {
            $this->assertSame('CTN-001', $this->contenedor->codigo);
            $this->assertSame(2000.5, $this->contenedor->capacidad);
            $this->assertSame('Av. Ejemplo 123', $this->contenedor->direccion);
            $this->assertSame(-34.9011, $this->contenedor->latitud);
            $this->assertSame(-56.1645, $this->contenedor->longitud);
            $this->assertSame('Disponible', $this->contenedor->estado);
            $this->assertSame(2, $this->contenedor->id_tipo_residuo);
            $this->assertSame(3, $this->contenedor->id_ruta);
            return true;
        });

        $result = $this->controller->create($this->validData());

        $this->assertResponse($result, true, 201, 'Contenedor urbano registrado con exito en Zemyna.');
        $this->assertNull($result['data']);
        $this->assertSame([], $result['errors']);
    }

    public function testCreateRechazaCamposObligatorios(): void
    {
        $this->contenedor->expects($this->never())->method('findByCodigo');
        $this->contenedor->expects($this->never())->method('create');

        $result = $this->controller->create([]);

        $this->assertValidationResponse($result, 'No se pudo registrar el contenedor.', [
            'El código es obligatorio.', 'La dirección es obligatoria.', 'La capacidad es obligatoria.',
            'La latitud es obligatoria y debe estar entre -90 y 90.',
            'La longitud es obligatoria y debe estar entre -180 y 180.', 'El estado es obligatorio.',
            'El id_tipo_residuo debe ser un número entero válido.',
            'El id_ruta debe ser un número entero válido.',
        ]);
    }

    public function testCreateRechazaCodigoDuplicado(): void
    {
        $this->contenedor->expects($this->once())->method('findByCodigo')->with('CTN-001')
            ->willReturn(['id_contenedor' => 77, 'codigo' => 'CTN-001']);
        $this->contenedor->expects($this->never())->method('create');

        $result = $this->controller->create($this->validData());

        $this->assertResponse($result, false, 409, 'El código del contenedor ya existe.');
        $this->assertSame(['El código ya está registrado.'], $result['errors']);
    }

    /** @dataProvider invalidCapacityProvider */
    public function testCreateRechazaCapacidadInvalida($capacity): void
    {
        $this->contenedor->expects($this->never())->method('create');
        $result = $this->controller->create($this->validData(['capacidad' => $capacity]));

        $this->assertValidationResponse($result, 'No se pudo registrar el contenedor.', [
            'La capacidad debe ser un número positivo.',
        ]);
    }

    public static function invalidCapacityProvider(): array
    {
        return ['cero' => [0], 'negativa' => [-1], 'texto' => ['grande']];
    }

    /** @dataProvider invalidCoordinatesProvider */
    public function testCreateRechazaLatitudOLongitudInvalida(array $changes, string $error): void
    {
        $this->contenedor->expects($this->never())->method('create');
        $result = $this->controller->create($this->validData($changes));

        $this->assertValidationResponse($result, 'No se pudo registrar el contenedor.', [$error]);
    }

    public static function invalidCoordinatesProvider(): array
    {
        return [
            'latitud menor' => [['latitud' => -90.01], 'La latitud es obligatoria y debe estar entre -90 y 90.'],
            'latitud mayor' => [['latitud' => 90.01], 'La latitud es obligatoria y debe estar entre -90 y 90.'],
            'longitud menor' => [['longitud' => -180.01], 'La longitud es obligatoria y debe estar entre -180 y 180.'],
            'longitud mayor' => [['longitud' => 180.01], 'La longitud es obligatoria y debe estar entre -180 y 180.'],
        ];
    }

    public function testCreateAceptaValoresLimiteDeCoordenadas(): void
    {
        $this->contenedor->expects($this->once())->method('findByCodigo')->willReturn(null);
        $this->contenedor->expects($this->once())->method('create')->willReturnCallback(function (): bool {
            $this->assertSame(-90.0, $this->contenedor->latitud);
            $this->assertSame(180.0, $this->contenedor->longitud);
            return true;
        });

        $result = $this->controller->create($this->validData(['latitud' => -90, 'longitud' => 180]));

        $this->assertResponse($result, true, 201, 'Contenedor urbano registrado con exito en Zemyna.');
    }

    public function testCreateRechazaEstadoInvalido(): void
    {
        $this->contenedor->expects($this->never())->method('create');
        $result = $this->controller->create($this->validData(['estado' => 'En reparación']));

        $this->assertValidationResponse($result, 'No se pudo registrar el contenedor.', ['El estado no es válido.']);
    }

    /** @dataProvider invalidRelationsProvider */
    public function testCreateRechazaTipoResiduoORutaInvalido(array $changes, string $error): void
    {
        $this->contenedor->expects($this->never())->method('create');
        $result = $this->controller->create($this->validData($changes));

        $this->assertValidationResponse($result, 'No se pudo registrar el contenedor.', [$error]);
    }

    public static function invalidRelationsProvider(): array
    {
        return [
            'tipo faltante' => [['id_tipo_residuo' => null], 'El id_tipo_residuo debe ser un número entero válido.'],
            'tipo inválido' => [['id_tipo_residuo' => 'orgánico'], 'El id_tipo_residuo debe ser un número entero válido.'],
            'ruta faltante' => [['id_ruta' => null], 'El id_ruta debe ser un número entero válido.'],
            'ruta inválida' => [['id_ruta' => 'norte'], 'El id_ruta debe ser un número entero válido.'],
        ];
    }

    public function testCreateDevuelveErrorCuandoFallaElModelo(): void
    {
        $this->contenedor->expects($this->once())->method('findByCodigo')->willReturn(null);
        $this->contenedor->expects($this->once())->method('create')->willReturn(false);

        $result = $this->controller->create($this->validData());

        $this->assertResponse($result, false, 500, 'Error al registrar el contenedor.');
        $this->assertNull($result['data']);
        $this->assertSame([], $result['errors']);
    }

    public function testUpdateActualizaContenedor(): void
    {
        $this->contenedor->expects($this->once())->method('findByCodigo')->with('CTN-001')
            ->willReturn(['id_contenedor' => 7]);
        $this->contenedor->expects($this->once())->method('update')->willReturnCallback(function (): bool {
            $this->assertSame(7, $this->contenedor->id_contenedor);
            $this->assertSame('CTN-001', $this->contenedor->codigo);
            $this->assertSame(2, $this->contenedor->id_tipo_residuo);
            $this->assertSame(3, $this->contenedor->id_ruta);
            return true;
        });

        $result = $this->controller->update($this->validUpdateData());

        $this->assertResponse($result, true, 200, 'Contenedor actualizado con exito.');
        $this->assertSame([], $result['errors']);
    }

    public function testUpdateRechazaIdFaltante(): void
    {
        $data = $this->validData();
        $this->contenedor->expects($this->never())->method('findByCodigo');
        $this->contenedor->expects($this->never())->method('update');

        $result = $this->controller->update($data);

        $this->assertValidationResponse($result, 'No se pudo actualizar el contenedor.', [
            'El id_contenedor es obligatorio para actualizar.',
        ]);
    }

    public function testUpdateRechazaCodigoDeOtroContenedor(): void
    {
        $this->contenedor->expects($this->once())->method('findByCodigo')->with('CTN-001')
            ->willReturn(['id_contenedor' => 8]);
        $this->contenedor->expects($this->never())->method('update');

        $result = $this->controller->update($this->validUpdateData());

        $this->assertResponse($result, false, 409, 'El código del contenedor ya existe para otro registro.');
        $this->assertSame(['El código ya está registrado.'], $result['errors']);
    }

    public function testUpdateAplicaValidacionDeCampos(): void
    {
        $this->contenedor->expects($this->never())->method('findByCodigo');
        $this->contenedor->expects($this->never())->method('update');
        $result = $this->controller->update($this->validUpdateData(['estado' => 'Desconocido']));

        $this->assertValidationResponse($result, 'No se pudo actualizar el contenedor.', ['El estado no es válido.']);
    }

    public function testUpdateDevuelveNotFoundCuandoElModeloNoActualiza(): void
    {
        $this->contenedor->expects($this->once())->method('findByCodigo')->willReturn(null);
        $this->contenedor->expects($this->once())->method('update')->willReturn(false);

        $result = $this->controller->update($this->validUpdateData());

        $this->assertResponse($result, false, 404, 'Contenedor no encontrado.');
        $this->assertSame(['No existe el contenedor solicitado.'], $result['errors']);
    }

    public function testDeleteDelegaLaBajaLogicaAlModelo(): void
    {
        $this->contenedor->expects($this->once())->method('delete')->willReturnCallback(function (): bool {
            $this->assertSame(7, $this->contenedor->id_contenedor);
            return true;
        });

        $result = $this->controller->delete(7);

        $this->assertResponse($result, true, 200, 'Contenedor dado de baja lógicamente.');
        $this->assertNull($result['data']);
        $this->assertSame([], $result['errors']);
    }

    public function testDeleteDevuelveNotFoundCuandoElModeloNoDaDeBaja(): void
    {
        $this->contenedor->expects($this->once())->method('delete')->willReturn(false);

        $result = $this->controller->delete(99);

        $this->assertResponse($result, false, 404, 'Contenedor no encontrado.');
        $this->assertSame(['No existe el contenedor solicitado.'], $result['errors']);
    }
}
