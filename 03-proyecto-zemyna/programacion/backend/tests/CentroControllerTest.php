<?php

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../controllers/CentroController.php';

class CentroControllerTest extends TestCase
{
    private CentroController $controller;
    private Centro&MockObject $centro;

    protected function setUp(): void
    {
        $this->centro = $this->getMockBuilder(Centro::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['read', 'findById', 'findDuplicate', 'create', 'update', 'delete'])
            ->getMock();

        $class = new ReflectionClass(CentroController::class);
        $this->controller = $class->newInstanceWithoutConstructor();
        (new ReflectionProperty(CentroController::class, 'centro'))->setValue($this->controller, $this->centro);
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
            'nombre' => ' Centro Temporal ',
            'direccion' => ' Calle Ficticia 123 ',
            'telefono' => ' 099000111 ',
        ], $changes);
    }

    private function updateData(array $changes = []): array
    {
        return $this->validData(array_merge(['id_centro' => 7], $changes));
    }

    private function assertResponse(array $result, bool $success, $data, string $message, array $errors, int $status): void
    {
        $this->assertSame($success, $result['success']);
        $this->assertSame($data, $result['data']);
        $this->assertSame($message, $result['message']);
        $this->assertSame($errors, $result['errors']);
        $this->assertSame($status, $result['statusCode']);
    }

    public function testGetAllDevuelveListado(): void
    {
        $rows = [['id_centro' => 1, 'nombre' => 'Centro Norte', 'activo' => 1]];
        $this->centro->expects($this->once())->method('read')->willReturn($this->statement($rows));
        $this->assertResponse($this->controller->getAll(), true, $rows, 'Centros cargados correctamente.', [], 200);
    }

    public function testGetAllDevuelveListaVacia(): void
    {
        $this->centro->method('read')->willReturn($this->statement([]));
        $this->assertResponse($this->controller->getAll(), true, [], 'Centros cargados correctamente.', [], 200);
    }

    /** @dataProvider readFailureProvider */
    public function testGetAllDevuelve500AnteFalloDelModelo($failure): void
    {
        if ($failure instanceof Throwable) $this->centro->method('read')->willThrowException($failure);
        else $this->centro->method('read')->willReturn($failure);
        $result = $this->controller->getAll();
        $this->assertResponse($result, false, [], 'No se pudieron cargar los centros.', [], 500);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result));
    }

    public static function readFailureProvider(): array
    {
        return ['null' => [null], 'false' => [false], 'PDO' => [new PDOException('SQLSTATE ruta interna')]];
    }

    public function testCreateNormalizaYRegistraCentro(): void
    {
        $this->centro->expects($this->once())->method('findDuplicate')->with('Centro Temporal', 'Calle Ficticia 123')->willReturn(null);
        $this->centro->expects($this->once())->method('create')->willReturnCallback(function (): bool {
            $this->assertSame('Centro Temporal', $this->centro->nombre);
            $this->assertSame('Calle Ficticia 123', $this->centro->direccion);
            $this->assertSame('099000111', $this->centro->telefono);
            $this->centro->id_centro = 15;
            return true;
        });
        $this->assertResponse($this->controller->create($this->validData()), true, ['id_centro' => 15], 'Centro registrado con éxito en Zemyna.', [], 201);
    }

    public function testCreateAceptaTelefonoOpcional(): void
    {
        $this->centro->method('findDuplicate')->willReturn(null);
        $this->centro->method('create')->willReturnCallback(function (): bool {
            $this->assertNull($this->centro->telefono);
            $this->centro->id_centro = 16;
            return true;
        });
        $this->assertSame(201, $this->controller->create($this->validData(['telefono' => '']))['statusCode']);
    }

    public function testCreateRechazaCamposObligatorios(): void
    {
        $this->centro->expects($this->never())->method('findDuplicate');
        $this->assertResponse($this->controller->create([]), false, null, 'No se pudo registrar el centro.', [
            'El nombre es obligatorio.', 'La dirección es obligatoria.'
        ], 400);
    }

    /** @dataProvider invalidFieldProvider */
    public function testCreateValidaLongitudesYTipo(array $changes, string $error): void
    {
        $this->centro->expects($this->never())->method('create');
        $result = $this->controller->create($this->validData($changes));
        $this->assertResponse($result, false, null, 'No se pudo registrar el centro.', [$error], 400);
    }

    public static function invalidFieldProvider(): array
    {
        return [
            'nombre largo' => [['nombre' => str_repeat('N', 101)], 'El nombre no puede superar los 100 caracteres.'],
            'dirección larga' => [['direccion' => str_repeat('D', 151)], 'La dirección no puede superar los 150 caracteres.'],
            'teléfono largo' => [['telefono' => str_repeat('1', 21)], 'El teléfono no puede superar los 20 caracteres.'],
            'teléfono no textual' => [['telefono' => 123], 'El teléfono debe ser texto.'],
        ];
    }

    public function testCreateDevuelve409ParaDuplicado(): void
    {
        $this->centro->method('findDuplicate')->willReturn(['id_centro' => 2]);
        $this->centro->expects($this->never())->method('create');
        $this->assertResponse($this->controller->create($this->validData()), false, null,
            'Ya existe un centro con el mismo nombre y dirección.', ['El centro ya está registrado.'], 409);
    }

    /** @dataProvider createPersistenceFailureProvider */
    public function testCreateDevuelve500AnteFalloDePersistencia(string $operation, $failure): void
    {
        if ($operation === 'find') {
            $this->centro->method('findDuplicate')->willThrowException($failure);
        } else {
            $this->centro->method('findDuplicate')->willReturn(null);
            if ($failure instanceof Throwable) $this->centro->method('create')->willThrowException($failure);
            else $this->centro->method('create')->willReturn($failure);
        }
        $result = $this->controller->create($this->validData());
        $this->assertSame(500, $result['statusCode']);
        $this->assertFalse($result['success']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result));
    }

    public static function createPersistenceFailureProvider(): array
    {
        return [
            'consulta' => ['find', new PDOException('SQLSTATE secreto')],
            'resultado false' => ['create', false],
            'excepción' => ['create', new PersistenceException('ruta interna')],
        ];
    }

    /** @dataProvider invalidIdProvider */
    public function testUpdateRechazaIdInvalido($id): void
    {
        $this->centro->expects($this->never())->method('findById');
        $result = $this->controller->update($this->updateData(['id_centro' => $id]));
        $this->assertSame(400, $result['statusCode']);
        $this->assertContains('El id_centro debe ser un entero positivo.', $result['errors']);
    }

    public static function invalidIdProvider(): array
    {
        return ['faltante' => [null], 'cero' => [0], 'negativo' => [-1], 'decimal' => ['1.5'], 'texto' => ['centro']];
    }

    public function testUpdateDevuelve404SiNoExiste(): void
    {
        $this->centro->method('findById')->with(7)->willReturn(null);
        $this->assertResponse($this->controller->update($this->updateData()), false, null,
            'Centro no encontrado.', ['No existe el centro solicitado.'], 404);
    }

    public function testUpdateRechazaDuplicadoDeOtroCentro(): void
    {
        $this->centro->method('findById')->willReturn(['id_centro' => 7, 'activo' => 1]);
        $this->centro->method('findDuplicate')->willReturn(['id_centro' => 8]);
        $this->centro->expects($this->never())->method('update');
        $this->assertSame(409, $this->controller->update($this->updateData())['statusCode']);
    }

    public function testUpdatePermiteConservarNombreYDireccion(): void
    {
        $this->centro->method('findById')->willReturn(['id_centro' => 7, 'activo' => 1]);
        $this->centro->method('findDuplicate')->willReturn(['id_centro' => 7]);
        $this->centro->expects($this->once())->method('update')->willReturn(true);
        $this->assertSame(200, $this->controller->update($this->updateData())['statusCode']);
    }

    public function testUpdateActualizaCorrectamente(): void
    {
        $this->centro->method('findById')->willReturn(['id_centro' => 7, 'activo' => 1]);
        $this->centro->method('findDuplicate')->willReturn(null);
        $this->centro->expects($this->once())->method('update')->willReturnCallback(function (): bool {
            $this->assertSame(7, $this->centro->id_centro);
            $this->assertSame('Centro Temporal', $this->centro->nombre);
            return true;
        });
        $this->assertResponse($this->controller->update($this->updateData()), true, null, 'Centro actualizado con éxito.', [], 200);
    }

    /** @dataProvider updateFailureProvider */
    public function testUpdateDevuelve500AnteFalloDePersistencia(string $operation, Throwable|bool $failure): void
    {
        if ($operation === 'find') $this->centro->method('findById')->willThrowException($failure);
        else {
            $this->centro->method('findById')->willReturn(['id_centro' => 7, 'activo' => 1]);
            $this->centro->method('findDuplicate')->willReturn(null);
            if ($failure instanceof Throwable) $this->centro->method('update')->willThrowException($failure);
            else $this->centro->method('update')->willReturn(false);
        }
        $result = $this->controller->update($this->updateData());
        $this->assertSame(500, $result['statusCode']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result));
    }

    public static function updateFailureProvider(): array
    {
        return [
            'buscar' => ['find', new PDOException('SQLSTATE interno')],
            'actualizar false' => ['update', false],
            'actualizar excepción' => ['update', new PersistenceException('interno')],
        ];
    }

    /** @dataProvider invalidIdProvider */
    public function testDeleteRechazaIdInvalido($id): void
    {
        $this->centro->expects($this->never())->method('findById');
        $result = $this->controller->delete($id);
        $this->assertSame(400, $result['statusCode']);
        $this->assertFalse($result['success']);
    }

    public function testDeleteDevuelve404SiNoExiste(): void
    {
        $this->centro->method('findById')->willReturn(null);
        $this->assertResponse($this->controller->delete(7), false, null, 'Centro no encontrado.', ['No existe el centro solicitado.'], 404);
    }

    public function testDeleteRealizaBajaLogica(): void
    {
        $this->centro->method('findById')->willReturn(['id_centro' => 7, 'activo' => 1]);
        $this->centro->expects($this->once())->method('delete')->willReturnCallback(function (): bool {
            $this->assertSame(7, $this->centro->id_centro);
            return true;
        });
        $this->assertResponse($this->controller->delete(7), true, null, 'Centro dado de baja lógicamente.', [], 200);
    }

    public function testDeleteEsIdempotenteSiYaEstabaInactivo(): void
    {
        $this->centro->method('findById')->willReturn(['id_centro' => 7, 'activo' => 0]);
        $this->centro->expects($this->never())->method('delete');
        $this->assertResponse($this->controller->delete(7), true, null, 'El centro ya se encontraba dado de baja.', [], 200);
    }

    /** @dataProvider deleteFailureProvider */
    public function testDeleteDevuelve500AnteFalloDePersistencia(string $operation, Throwable|bool $failure): void
    {
        if ($operation === 'find') $this->centro->method('findById')->willThrowException($failure);
        else {
            $this->centro->method('findById')->willReturn(['id_centro' => 7, 'activo' => 1]);
            if ($failure instanceof Throwable) $this->centro->method('delete')->willThrowException($failure);
            else $this->centro->method('delete')->willReturn(false);
        }
        $result = $this->controller->delete(7);
        $this->assertSame(500, $result['statusCode']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result));
    }

    public static function deleteFailureProvider(): array
    {
        return [
            'buscar' => ['find', new PDOException('SQLSTATE interno')],
            'baja false' => ['delete', false],
            'baja excepción' => ['delete', new PersistenceException('interno')],
        ];
    }

    public function testEndpointUsaPermisosCanonicosLugarYStatusCode(): void
    {
        $source = file_get_contents(__DIR__ . '/../api/centros.php');
        foreach (['lugar.consultar', 'lugar.crear', 'lugar.modificar', 'lugar.baja'] as $permission) {
            $this->assertStringContainsString("requirePermission('{$permission}'", $source);
        }
        $this->assertStringNotContainsString("requirePermission('centro.", $source);
        $this->assertSame(4, substr_count($source, "http_response_code(\$response['statusCode'])"));
    }
}
