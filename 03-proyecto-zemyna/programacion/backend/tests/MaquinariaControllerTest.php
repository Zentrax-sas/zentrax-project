<?php

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../controllers/MaquinariaController.php';

class MaquinariaControllerTest extends TestCase
{
    private MaquinariaController $controller;
    private Maquinaria&MockObject $model;

    protected function setUp(): void
    {
        $this->model = $this->getMockBuilder(Maquinaria::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['read', 'findById', 'findDuplicate', 'findCentroById', 'create', 'update', 'delete'])
            ->getMock();
        $class = new ReflectionClass(MaquinariaController::class);
        $this->controller = $class->newInstanceWithoutConstructor();
        (new ReflectionProperty(MaquinariaController::class, 'maquinaria'))->setValue($this->controller, $this->model);
    }

    private function statement(array $rows): object
    {
        return new class($rows) {
            public function __construct(private array $rows) {}
            public function fetchAll(int $mode): array { return $this->rows; }
        };
    }

    private function valid(array $changes = []): array
    {
        return array_merge(['nombre' => ' TST ', 'tipo' => ' Elevador ', 'estado' => 'Disponible', 'id_centro' => 1], $changes);
    }

    private function updateData(array $changes = []): array
    {
        return $this->valid(array_merge(['id_maquinaria' => 9], $changes));
    }

    private function prepareCreate(): void
    {
        $this->model->method('findCentroById')->willReturn(['id_centro' => 1]);
        $this->model->method('findDuplicate')->willReturn(null);
    }

    private function prepareUpdate(): void
    {
        $this->model->method('findById')->willReturn(['id_maquinaria' => 9, 'activo' => 1]);
        $this->model->method('findCentroById')->willReturn(['id_centro' => 1]);
    }

    private function assertResponse(array $actual, bool $success, $data, string $message, array $errors, int $status): void
    {
        $this->assertSame(['success', 'data', 'message', 'errors', 'statusCode'], array_keys($actual));
        $this->assertSame($success, $actual['success']);
        $this->assertSame($data, $actual['data']);
        $this->assertSame($message, $actual['message']);
        $this->assertSame($errors, $actual['errors']);
        $this->assertSame($status, $actual['statusCode']);
    }

    public function testListadoNormal(): void
    {
        $rows = [['id_maquinaria' => 1, 'nombre' => 'Prensadora', 'activo' => 1]];
        $this->model->expects($this->once())->method('read')->willReturn($this->statement($rows));
        $this->assertResponse($this->controller->getAll(), true, $rows, 'Maquinaria cargada correctamente.', [], 200);
    }

    public function testListadoVacio(): void
    {
        $this->model->method('read')->willReturn($this->statement([]));
        $this->assertResponse($this->controller->getAll(), true, [], 'Maquinaria cargada correctamente.', [], 200);
    }

    /** @dataProvider readFailures */
    public function testListadoFallaDeFormaSegura($failure): void
    {
        if ($failure instanceof Throwable) $this->model->method('read')->willThrowException($failure);
        else $this->model->method('read')->willReturn($failure);
        $result = $this->controller->getAll();
        $this->assertResponse($result, false, [], 'No se pudo cargar la maquinaria.', [], 500);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result));
    }

    public static function readFailures(): array
    {
        return ['null' => [null], 'false' => [false], 'PDO' => [new PDOException('SQLSTATE ruta secreta')], 'persistencia' => [new PersistenceException('ruta privada')]];
    }

    public function testCreacionValidaNormalizaYAsignaCampos(): void
    {
        $this->model->expects($this->once())->method('findCentroById')->with(1)->willReturn(['id_centro' => 1]);
        $this->model->expects($this->once())->method('findDuplicate')->with('TST', 1)->willReturn(null);
        $this->model->expects($this->once())->method('create')->willReturnCallback(function (): bool {
            $this->assertSame('TST', $this->model->nombre);
            $this->assertSame('Elevador', $this->model->tipo);
            $this->assertSame('Disponible', $this->model->estado);
            $this->assertSame(1, $this->model->id_centro);
            $this->model->id_maquinaria = 15;
            return true;
        });
        $this->assertResponse($this->controller->create($this->valid()), true, ['id_maquinaria' => 15], 'Maquinaria registrada con éxito en Zemyna.', [], 201);
    }

    public function testCreacionRechazaCamposFaltantes(): void
    {
        $this->model->expects($this->never())->method('findCentroById');
        $result = $this->controller->create([]);
        $this->assertSame(400, $result['statusCode']);
        $this->assertSame(4, count($result['errors']));
    }

    /** @dataProvider invalidPayloads */
    public function testCreacionValidaCampos(array $changes, string $error): void
    {
        $this->model->expects($this->never())->method('create');
        $result = $this->controller->create($this->valid($changes));
        $this->assertSame(400, $result['statusCode']);
        $this->assertContains($error, $result['errors']);
    }

    public static function invalidPayloads(): array
    {
        return [
            'nombre largo' => [['nombre' => str_repeat('N', 51)], 'El nombre no puede superar los 50 caracteres.'],
            'tipo largo' => [['tipo' => str_repeat('T', 51)], 'El tipo no puede superar los 50 caracteres.'],
            'estado' => [['estado' => 'Activo'], 'El estado debe ser Disponible, En Uso o En Mantenimiento.'],
            'centro cero' => [['id_centro' => 0], 'El id_centro debe ser un entero positivo.'],
            'centro negativo' => [['id_centro' => -1], 'El id_centro debe ser un entero positivo.'],
            'centro decimal' => [['id_centro' => '1.5'], 'El id_centro debe ser un entero positivo.'],
            'centro texto' => [['id_centro' => 'uno'], 'El id_centro debe ser un entero positivo.'],
        ];
    }

    /** @dataProvider allowedStates */
    public function testCreacionAceptaCadaEstadoDelEsquema(string $estado): void
    {
        $this->prepareCreate();
        $this->model->method('create')->willReturnCallback(function (): bool { $this->model->id_maquinaria = 20; return true; });
        $this->assertSame(201, $this->controller->create($this->valid(['estado' => $estado]))['statusCode']);
        $this->assertSame($estado, $this->model->estado);
    }

    public static function allowedStates(): array
    {
        return [['Disponible'], ['En Uso'], ['En Mantenimiento']];
    }

    public function testCreacionDevuelve404SiCentroNoExiste(): void
    {
        $this->model->method('findCentroById')->willReturn(null);
        $this->model->expects($this->never())->method('findDuplicate');
        $this->assertResponse($this->controller->create($this->valid()), false, null, 'Centro no encontrado.', ['No existe un centro activo con el ID indicado.'], 404);
    }

    public function testCreacionDevuelve409SiEsDuplicada(): void
    {
        $this->model->method('findCentroById')->willReturn(['id_centro' => 1]);
        $this->model->method('findDuplicate')->willReturn(['id_maquinaria' => 2]);
        $this->model->expects($this->never())->method('create');
        $this->assertSame(409, $this->controller->create($this->valid())['statusCode']);
    }

    /** @dataProvider createFailures */
    public function testCreacionDevuelve500AntePersistencia(string $operation, $failure): void
    {
        if ($operation === 'centro') $this->model->method('findCentroById')->will($failure instanceof Throwable ? $this->throwException($failure) : $this->returnValue($failure));
        else {
            $this->model->method('findCentroById')->willReturn(['id_centro' => 1]);
            if ($operation === 'duplicate') $this->model->method('findDuplicate')->will($failure instanceof Throwable ? $this->throwException($failure) : $this->returnValue($failure));
            else {
                $this->model->method('findDuplicate')->willReturn(null);
                $this->model->method('create')->will($failure instanceof Throwable ? $this->throwException($failure) : $this->returnValue($failure));
            }
        }
        $result = $this->controller->create($this->valid());
        $this->assertSame(500, $result['statusCode']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result));
    }

    public static function createFailures(): array
    {
        return [['centro', false], ['centro', new PDOException('SQLSTATE secreto')], ['duplicate', false], ['duplicate', new PersistenceException('ruta')], ['create', false], ['create', new PDOException('SQLSTATE secreto')]];
    }

    /** @dataProvider invalidIds */
    public function testActualizacionRechazaIdInvalido($id): void
    {
        $this->model->expects($this->never())->method('findById');
        $result = $this->controller->update($this->updateData(['id_maquinaria' => $id]));
        $this->assertSame(400, $result['statusCode']);
        $this->assertContains('El id_maquinaria debe ser un entero positivo.', $result['errors']);
    }

    public static function invalidIds(): array
    {
        return [[''], [0], [-1], ['1.5'], ['abc']];
    }

    public function testActualizacionDevuelve404SiNoExiste(): void
    {
        $this->model->method('findById')->willReturn(null);
        $this->assertSame(404, $this->controller->update($this->updateData())['statusCode']);
    }

    public function testActualizacionDevuelve404SiCentroNoExiste(): void
    {
        $this->model->method('findById')->willReturn(['id_maquinaria' => 9, 'activo' => 1]);
        $this->model->method('findCentroById')->willReturn(null);
        $this->assertSame(404, $this->controller->update($this->updateData())['statusCode']);
    }

    public function testActualizacionPermiteConservarDuplicadoPropio(): void
    {
        $this->prepareUpdate();
        $this->model->method('findDuplicate')->willReturn(['id_maquinaria' => 9]);
        $this->model->expects($this->once())->method('update')->willReturn(true);
        $this->assertSame(200, $this->controller->update($this->updateData())['statusCode']);
    }

    public function testActualizacionRechazaDuplicadoAjeno(): void
    {
        $this->prepareUpdate();
        $this->model->method('findDuplicate')->willReturn(['id_maquinaria' => 10]);
        $this->model->expects($this->never())->method('update');
        $this->assertSame(409, $this->controller->update($this->updateData())['statusCode']);
    }

    public function testActualizacionValidaYAsigna(): void
    {
        $this->prepareUpdate();
        $this->model->method('findDuplicate')->willReturn(null);
        $this->model->expects($this->once())->method('update')->willReturnCallback(function (): bool {
            $this->assertSame(9, $this->model->id_maquinaria);
            $this->assertSame('TST', $this->model->nombre);
            $this->assertSame(1, $this->model->id_centro);
            return true;
        });
        $this->assertResponse($this->controller->update($this->updateData()), true, null, 'Maquinaria actualizada con éxito.', [], 200);
    }

    /** @dataProvider updateFailures */
    public function testActualizacionDevuelve500AntePersistencia(string $operation, $failure): void
    {
        if ($operation === 'find') $this->model->method('findById')->will($failure instanceof Throwable ? $this->throwException($failure) : $this->returnValue($failure));
        else {
            $this->prepareUpdate();
            $this->model->method('findDuplicate')->willReturn(null);
            $this->model->method('update')->will($failure instanceof Throwable ? $this->throwException($failure) : $this->returnValue($failure));
        }
        $result = $this->controller->update($this->updateData());
        $this->assertSame(500, $result['statusCode']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result));
    }

    public static function updateFailures(): array
    {
        return [['find', false], ['find', new PDOException('SQLSTATE secreto')], ['update', false], ['update', new PersistenceException('ruta')]];
    }

    /** @dataProvider invalidIds */
    public function testBajaRechazaIdInvalido($id): void
    {
        $this->model->expects($this->never())->method('findById');
        $this->assertSame(400, $this->controller->delete($id)['statusCode']);
    }

    public function testBajaDevuelve404SiNoExiste(): void
    {
        $this->model->method('findById')->willReturn(null);
        $this->assertSame(404, $this->controller->delete(9)['statusCode']);
    }

    public function testBajaLogicaCorrecta(): void
    {
        $this->model->method('findById')->willReturn(['id_maquinaria' => 9, 'activo' => 1]);
        $this->model->expects($this->once())->method('delete')->willReturnCallback(function (): bool { $this->assertSame(9, $this->model->id_maquinaria); return true; });
        $this->assertResponse($this->controller->delete(9), true, null, 'Maquinaria dada de baja lógicamente.', [], 200);
    }

    public function testBajaRepetidaEsIdempotente(): void
    {
        $this->model->method('findById')->willReturn(['id_maquinaria' => 9, 'activo' => 0]);
        $this->model->expects($this->never())->method('delete');
        $this->assertResponse($this->controller->delete(9), true, null, 'La maquinaria ya se encontraba dada de baja.', [], 200);
    }

    /** @dataProvider deleteFailures */
    public function testBajaDevuelve500AntePersistencia(string $operation, $failure): void
    {
        if ($operation === 'find') $this->model->method('findById')->will($failure instanceof Throwable ? $this->throwException($failure) : $this->returnValue($failure));
        else {
            $this->model->method('findById')->willReturn(['id_maquinaria' => 9, 'activo' => 1]);
            $this->model->method('delete')->will($failure instanceof Throwable ? $this->throwException($failure) : $this->returnValue($failure));
        }
        $result = $this->controller->delete(9);
        $this->assertSame(500, $result['statusCode']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result));
    }

    public static function deleteFailures(): array
    {
        return [['find', false], ['find', new PDOException('SQLSTATE secreto')], ['delete', false], ['delete', new PersistenceException('ruta')]];
    }

    public function testEndpointDeclaraPermisosYPropagaStatusCode(): void
    {
        $source = file_get_contents(__DIR__ . '/../api/maquinaria.php');
        foreach (['maquinaria.consultar', 'maquinaria.crear', 'maquinaria.modificar', 'maquinaria.baja'] as $permission) {
            $this->assertStringContainsString("requirePermission('{$permission}'", $source);
        }
        $this->assertStringContainsString("http_response_code(\$response['statusCode'])", $source);
        $this->assertStringContainsString("'statusCode' => 405", $source);
        $this->assertSame(3, substr_count($source, "'statusCode' => 400"));
    }

    public function testNoCapturaErroresDeProgramacionArbitrarios(): void
    {
        $this->model->method('read')->willThrowException(new RuntimeException('defecto de programación'));
        $this->expectException(RuntimeException::class);
        $this->controller->getAll();
    }
}
