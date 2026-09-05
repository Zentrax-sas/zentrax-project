<?php

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../controllers/VehiculoController.php';

class VehiculoControllerTest extends TestCase
{
    private VehiculoController $controller;
    private Vehiculo&MockObject $vehiculo;

    protected function setUp(): void
    {
        $this->vehiculo = $this->getMockBuilder(Vehiculo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['read', 'findById', 'findByMatricula', 'create', 'update', 'delete'])
            ->getMock();

        $class = new ReflectionClass(VehiculoController::class);
        $this->controller = $class->newInstanceWithoutConstructor();
        $property = new ReflectionProperty(VehiculoController::class, 'vehiculo');
        $property->setValue($this->controller, $this->vehiculo);
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
            'matricula' => ' STC1234 ', 'marca' => ' Mercedes-Benz ', 'modelo' => ' Atego ',
            'capacidad_carga' => '8500.50', 'estado' => 'Disponible', 'id_tipo_residuo' => '2',
        ], $changes);
    }

    private function updateData(array $changes = []): array
    {
        return $this->validData(array_merge(['id_vehiculo' => 7], $changes));
    }

    private function assertResponse(array $result, bool $success, $data, string $message, array $errors, int $status): void
    {
        $this->assertSame($success, $result['success']);
        $this->assertSame($data, $result['data']);
        $this->assertSame($message, $result['message']);
        $this->assertSame($errors, $result['errors']);
        $this->assertSame($status, $result['statusCode']);
    }

    private function expectExistingVehicle(int $id = 7): void
    {
        $this->vehiculo->expects($this->once())->method('findById')->with($id)
            ->willReturn(['id_vehiculo' => $id, 'matricula' => 'STC1234']);
    }

    private function integrityException(int $driverCode): PDOException
    {
        $exception = new PDOException('SQLSTATE[23000] información interna');
        $exception->errorInfo = ['23000', $driverCode, 'detalle interno'];
        return $exception;
    }

    public function testGetAllDevuelveListado(): void
    {
        $rows = [['id_vehiculo' => 1, 'matricula' => 'STC1234']];
        $this->vehiculo->expects($this->once())->method('read')->willReturn($this->statement($rows));
        $result = $this->controller->getAll();
        $this->assertResponse($result, true, $rows, 'Vehículos cargados correctamente.', [], 200);
    }

    public function testGetAllDevuelveListaVacia(): void
    {
        $this->vehiculo->expects($this->once())->method('read')->willReturn($this->statement([]));
        $result = $this->controller->getAll();
        $this->assertResponse($result, true, [], 'Vehículos cargados correctamente.', [], 200);
    }

    /** @dataProvider failedResultProvider */
    public function testGetAllDevuelve500SiReadFalla($modelResult): void
    {
        $this->vehiculo->expects($this->once())->method('read')->willReturn($modelResult);
        $result = $this->controller->getAll();
        $this->assertResponse($result, false, [], 'No se pudieron cargar los vehículos.', [], 500);
    }

    public static function failedResultProvider(): array
    {
        return ['null' => [null], 'false' => [false]];
    }

    public function testGetAllOcultaExcepcionDePersistencia(): void
    {
        $this->vehiculo->expects($this->once())->method('read')
            ->willThrowException(new PDOException('SQLSTATE password=secreto /ruta/interna'));
        $result = $this->controller->getAll();
        $this->assertResponse($result, false, [], 'No se pudieron cargar los vehículos.', [], 500);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result));
        $this->assertStringNotContainsString('secreto', json_encode($result));
    }

    public function testCreateNormalizaYRegistraVehiculo(): void
    {
        $this->vehiculo->expects($this->once())->method('findByMatricula')->with('STC1234')->willReturn(null);
        $this->vehiculo->expects($this->once())->method('create')->willReturnCallback(function (): bool {
            $this->assertSame('STC1234', $this->vehiculo->matricula);
            $this->assertSame('Mercedes-Benz', $this->vehiculo->marca);
            $this->assertSame('Atego', $this->vehiculo->modelo);
            $this->assertSame(8500.5, $this->vehiculo->capacidad_carga);
            $this->assertSame('Disponible', $this->vehiculo->estado);
            $this->assertSame(2, $this->vehiculo->id_tipo_residuo);
            return true;
        });
        $result = $this->controller->create($this->validData());
        $this->assertResponse($result, true, null, 'Vehículo registrado con éxito en Zemyna.', [], 201);
    }

    public function testCreateRechazaCamposObligatorios(): void
    {
        $this->vehiculo->expects($this->never())->method('create');
        $result = $this->controller->create([]);
        $this->assertResponse($result, false, null, 'No se pudo registrar el vehículo.', [
            'La matrícula es obligatoria.', 'La marca es obligatoria.', 'El modelo es obligatorio.',
            'La capacidad de carga debe ser un número positivo.',
            'El estado debe ser Disponible, En Servicio o En Mantenimiento.',
            'El id_tipo_residuo debe ser un entero positivo.',
        ], 400);
    }

    /** @dataProvider excessiveLengthProvider */
    public function testCreateRechazaLongitudesMayoresAlEsquema(array $changes, string $error): void
    {
        $this->vehiculo->expects($this->never())->method('findByMatricula');
        $result = $this->controller->create($this->validData($changes));
        $this->assertResponse($result, false, null, 'No se pudo registrar el vehículo.', [$error], 400);
    }

    public static function excessiveLengthProvider(): array
    {
        return [
            'matrícula' => [['matricula' => 'ABCDEFGHIJK'], 'La matrícula no puede superar los 10 caracteres.'],
            'marca' => [['marca' => str_repeat('M', 51)], 'La marca no puede superar los 50 caracteres.'],
            'modelo' => [['modelo' => str_repeat('X', 51)], 'El modelo no puede superar los 50 caracteres.'],
        ];
    }

    /** @dataProvider invalidCapacityProvider */
    public function testCreateRechazaCapacidadInvalida($capacity): void
    {
        $result = $this->controller->create($this->validData(['capacidad_carga' => $capacity]));
        $this->assertResponse($result, false, null, 'No se pudo registrar el vehículo.', [
            'La capacidad de carga debe ser un número positivo.',
        ], 400);
    }

    public static function invalidCapacityProvider(): array
    {
        return ['no numérica' => ['mucho'], 'cero' => [0], 'negativa' => [-1]];
    }

    /** @dataProvider invalidTypeProvider */
    public function testCreateRechazaTipoResiduoInvalido($type): void
    {
        $result = $this->controller->create($this->validData(['id_tipo_residuo' => $type]));
        $this->assertResponse($result, false, null, 'No se pudo registrar el vehículo.', [
            'El id_tipo_residuo debe ser un entero positivo.',
        ], 400);
    }

    public static function invalidTypeProvider(): array
    {
        return ['faltante' => [null], 'cero' => [0], 'negativo' => [-1], 'decimal' => [1.5], 'texto' => ['orgánico']];
    }

    /** @dataProvider validStateProvider */
    public function testCreateAceptaCadaEstadoPermitido(string $state): void
    {
        $this->vehiculo->expects($this->once())->method('findByMatricula')->willReturn(null);
        $this->vehiculo->expects($this->once())->method('create')->willReturn(true);
        $result = $this->controller->create($this->validData(['estado' => $state]));
        $this->assertResponse($result, true, null, 'Vehículo registrado con éxito en Zemyna.', [], 201);
        $this->assertSame($state, $this->vehiculo->estado);
    }

    public static function validStateProvider(): array
    {
        return ['Disponible' => ['Disponible'], 'En Servicio' => ['En Servicio'], 'En Mantenimiento' => ['En Mantenimiento']];
    }

    public function testCreateRechazaEstadoInvalido(): void
    {
        $result = $this->controller->create($this->validData(['estado' => 'Fuera de Servicio']));
        $this->assertResponse($result, false, null, 'No se pudo registrar el vehículo.', [
            'El estado debe ser Disponible, En Servicio o En Mantenimiento.',
        ], 400);
    }

    public function testCreateRechazaMatriculaDuplicada(): void
    {
        $this->vehiculo->expects($this->once())->method('findByMatricula')->with('STC1234')
            ->willReturn(['id_vehiculo' => 8]);
        $this->vehiculo->expects($this->never())->method('create');
        $result = $this->controller->create($this->validData());
        $this->assertResponse($result, false, null, 'La matrícula ya está registrada.', ['La matrícula ya existe.'], 409);
    }

    public function testCreateClasificaDuplicadoConcurrente1062Como409(): void
    {
        $this->vehiculo->expects($this->once())->method('findByMatricula')->willReturn(null);
        $this->vehiculo->expects($this->once())->method('create')
            ->willThrowException($this->integrityException(1062));
        $result = $this->controller->create($this->validData());
        $this->assertResponse($result, false, null, 'La matrícula ya está registrada.', ['La matrícula ya existe.'], 409);
        $this->assertStringNotContainsString('intern', json_encode($result));
    }

    public function testCreateClasificaClaveForanea1452Como400(): void
    {
        $this->vehiculo->expects($this->once())->method('findByMatricula')->willReturn(null);
        $this->vehiculo->expects($this->once())->method('create')
            ->willThrowException($this->integrityException(1452));
        $result = $this->controller->create($this->validData());
        $this->assertResponse($result, false, null, 'El tipo de residuo indicado no es válido.', [
            'El id_tipo_residuo no corresponde a un registro existente.',
        ], 400);
        $this->assertStringNotContainsString('detalle interno', json_encode($result));
    }

    public function testCreateDevuelve500SiFallaBusquedaDeMatricula(): void
    {
        $this->vehiculo->expects($this->once())->method('findByMatricula')
            ->willThrowException(new PersistenceException('ruta y credencial interna'));
        $this->vehiculo->expects($this->never())->method('create');
        $result = $this->controller->create($this->validData());
        $this->assertResponse($result, false, null, 'No se pudo registrar el vehículo.', [], 500);
        $this->assertStringNotContainsString('credencial', json_encode($result));
    }

    /** @dataProvider persistenceExceptionProvider */
    public function testCreateDevuelve500AnteFalloOExcepcion($exception): void
    {
        $this->vehiculo->expects($this->once())->method('findByMatricula')->willReturn(null);
        if ($exception) {
            $this->vehiculo->expects($this->once())->method('create')->willThrowException($exception);
        } else {
            $this->vehiculo->expects($this->once())->method('create')->willReturn(false);
        }
        $result = $this->controller->create($this->validData());
        $this->assertResponse($result, false, null, 'Error al registrar el vehículo.', [], 500);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result));
    }

    public static function persistenceExceptionProvider(): array
    {
        return ['false' => [null], 'excepción' => [new PDOException('SQLSTATE secreto')]];
    }

    /** @dataProvider invalidIdProvider */
    public function testUpdateRechazaIdInvalido($id): void
    {
        $this->vehiculo->expects($this->never())->method('findById');
        $result = $this->controller->update($this->updateData(['id_vehiculo' => $id]));
        $this->assertResponse($result, false, null, 'No se pudo actualizar el vehículo.', [
            'El id_vehiculo debe ser un entero positivo.',
        ], 400);
    }

    public static function invalidIdProvider(): array
    {
        return ['faltante' => [null], 'cero' => [0], 'negativo' => [-1], 'decimal' => [1.5], 'texto' => ['siete']];
    }

    public function testUpdateDevuelve404SiVehiculoNoExiste(): void
    {
        $this->vehiculo->expects($this->once())->method('findById')->with(7)->willReturn(null);
        $this->vehiculo->expects($this->never())->method('findByMatricula');
        $result = $this->controller->update($this->updateData());
        $this->assertResponse($result, false, null, 'Vehículo no encontrado.', ['No existe el vehículo solicitado.'], 404);
    }

    /** @dataProvider invalidUpdatePayloadProvider */
    public function testUpdateValidaPayloadAntesDeConsultarElModelo(array $changes, array $errors): void
    {
        $this->vehiculo->expects($this->never())->method('findById');
        $this->vehiculo->expects($this->never())->method('update');
        $result = $this->controller->update($this->updateData($changes));
        $this->assertResponse($result, false, null, 'No se pudo actualizar el vehículo.', $errors, 400);
    }

    public static function invalidUpdatePayloadProvider(): array
    {
        return [
            'matrícula extensa' => [
                ['matricula' => 'ABCDEFGHIJK'], ['La matrícula no puede superar los 10 caracteres.'],
            ],
            'capacidad no numérica' => [
                ['capacidad_carga' => 'mucho'], ['La capacidad de carga debe ser un número positivo.'],
            ],
            'estado inválido' => [
                ['estado' => 'Fuera de Servicio'], ['El estado debe ser Disponible, En Servicio o En Mantenimiento.'],
            ],
            'tipo inválido' => [
                ['id_tipo_residuo' => 0], ['El id_tipo_residuo debe ser un entero positivo.'],
            ],
        ];
    }

    public function testUpdatePermiteLaMismaMatriculaYActualiza(): void
    {
        $this->expectExistingVehicle();
        $this->vehiculo->expects($this->once())->method('findByMatricula')->with('STC1234')
            ->willReturn(['id_vehiculo' => 7]);
        $this->vehiculo->expects($this->once())->method('update')->willReturnCallback(function (): bool {
            $this->assertSame(7, $this->vehiculo->id_vehiculo);
            $this->assertSame('STC1234', $this->vehiculo->matricula);
            return true;
        });
        $result = $this->controller->update($this->updateData());
        $this->assertResponse($result, true, null, 'Vehículo actualizado con éxito.', [], 200);
    }

    public function testUpdateRechazaMatriculaDeOtroVehiculo(): void
    {
        $this->expectExistingVehicle();
        $this->vehiculo->expects($this->once())->method('findByMatricula')->willReturn(['id_vehiculo' => 8]);
        $this->vehiculo->expects($this->never())->method('update');
        $result = $this->controller->update($this->updateData());
        $this->assertResponse($result, false, null, 'La matrícula pertenece a otro vehículo.', ['La matrícula ya existe.'], 409);
    }

    public function testUpdateClasificaDuplicadoConcurrente1062Como409(): void
    {
        $this->expectExistingVehicle();
        $this->vehiculo->expects($this->once())->method('findByMatricula')->willReturn(null);
        $this->vehiculo->expects($this->once())->method('update')
            ->willThrowException($this->integrityException(1062));
        $result = $this->controller->update($this->updateData());
        $this->assertResponse($result, false, null, 'La matrícula ya está registrada.', ['La matrícula ya existe.'], 409);
    }

    public function testUpdateClasificaClaveForanea1452Como400(): void
    {
        $this->expectExistingVehicle();
        $this->vehiculo->expects($this->once())->method('findByMatricula')->willReturn(null);
        $this->vehiculo->expects($this->once())->method('update')
            ->willThrowException($this->integrityException(1452));
        $result = $this->controller->update($this->updateData());
        $this->assertResponse($result, false, null, 'El tipo de residuo indicado no es válido.', [
            'El id_tipo_residuo no corresponde a un registro existente.',
        ], 400);
    }

    public function testUpdateDevuelve500SiFallaBusquedaDeMatricula(): void
    {
        $this->expectExistingVehicle();
        $this->vehiculo->expects($this->once())->method('findByMatricula')
            ->willThrowException(new PDOException('SQL interno'));
        $this->vehiculo->expects($this->never())->method('update');
        $result = $this->controller->update($this->updateData());
        $this->assertResponse($result, false, null, 'No se pudo actualizar el vehículo.', [], 500);
        $this->assertStringNotContainsString('SQL interno', json_encode($result));
    }

    /** @dataProvider persistenceExceptionProvider */
    public function testUpdateDevuelve500AnteFalloOExcepcion($exception): void
    {
        $this->expectExistingVehicle();
        $this->vehiculo->expects($this->once())->method('findByMatricula')->willReturn(null);
        if ($exception) $this->vehiculo->expects($this->once())->method('update')->willThrowException($exception);
        else $this->vehiculo->expects($this->once())->method('update')->willReturn(false);
        $result = $this->controller->update($this->updateData());
        $this->assertResponse($result, false, null, 'Error al actualizar el vehículo.', [], 500);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result));
    }

    public function testUpdateDevuelve500SiFallaBusquedaDeExistencia(): void
    {
        $this->vehiculo->expects($this->once())->method('findById')
            ->willThrowException(new PDOException('SQLSTATE secreto'));
        $result = $this->controller->update($this->updateData());
        $this->assertResponse($result, false, null, 'No se pudo actualizar el vehículo.', [], 500);
        $this->assertStringNotContainsString('secreto', json_encode($result));
    }

    public function testRuntimeExceptionNoRelacionadaSePropaga(): void
    {
        $this->vehiculo->expects($this->once())->method('findById')
            ->willThrowException(new RuntimeException('error de programación'));
        $this->expectException(RuntimeException::class);
        $this->controller->update($this->updateData());
    }

    public function testTypeErrorSePropaga(): void
    {
        $this->vehiculo->expects($this->once())->method('findById')
            ->willThrowException(new TypeError('tipo incorrecto'));
        $this->expectException(TypeError::class);
        $this->controller->update($this->updateData());
    }

    /** @dataProvider activeValueProvider */
    public function testDeleteRealizaBajaLogica($active): void
    {
        $this->vehiculo->expects($this->once())->method('findById')->with(7)
            ->willReturn(['id_vehiculo' => 7, 'activo' => $active]);
        $this->vehiculo->expects($this->once())->method('delete')->willReturnCallback(function (): bool {
            $this->assertSame(7, $this->vehiculo->id_vehiculo);
            return true;
        });
        $result = $this->controller->delete('7');
        $this->assertResponse($result, true, null, 'Vehículo dado de baja lógicamente.', [], 200);
    }

    public static function activeValueProvider(): array
    {
        return ['entero' => [1], 'cadena' => ['1']];
    }

    /** @dataProvider invalidIdProvider */
    public function testDeleteRechazaIdInvalido($id): void
    {
        $this->vehiculo->expects($this->never())->method('findById');
        $result = $this->controller->delete($id);
        $this->assertResponse($result, false, null, 'El id_vehiculo no es válido.', [
            'El id_vehiculo debe ser un entero positivo.',
        ], 400);
    }

    public function testDeleteDevuelve404SiVehiculoNoExiste(): void
    {
        $this->vehiculo->expects($this->once())->method('findById')->with(7)->willReturn(null);
        $this->vehiculo->expects($this->never())->method('delete');
        $result = $this->controller->delete(7);
        $this->assertResponse($result, false, null, 'Vehículo no encontrado.', ['No existe el vehículo solicitado.'], 404);
    }

    /** @dataProvider inactiveValueProvider */
    public function testDeleteDeVehiculoInactivoEsIdempotente($inactive): void
    {
        $this->vehiculo->expects($this->once())->method('findById')->with(7)
            ->willReturn(['id_vehiculo' => 7, 'activo' => $inactive]);
        $this->vehiculo->expects($this->never())->method('delete');
        $result = $this->controller->delete(7);
        $this->assertResponse($result, true, null, 'El vehículo ya se encontraba dado de baja.', [], 200);
    }

    public static function inactiveValueProvider(): array
    {
        return ['entero' => [0], 'cadena' => ['0']];
    }

    public function testDeleteDevuelve500SiFallaBusquedaDeExistencia(): void
    {
        $this->vehiculo->expects($this->once())->method('findById')
            ->willThrowException(new PersistenceException('conexión interna'));
        $this->vehiculo->expects($this->never())->method('delete');
        $result = $this->controller->delete(7);
        $this->assertResponse($result, false, null, 'No se pudo dar de baja el vehículo.', [], 500);
        $this->assertStringNotContainsString('conexión interna', json_encode($result));
    }

    /** @dataProvider persistenceExceptionProvider */
    public function testDeleteDevuelve500AnteFalloOExcepcion($exception): void
    {
        $this->expectExistingVehicle();
        if ($exception) $this->vehiculo->expects($this->once())->method('delete')->willThrowException($exception);
        else $this->vehiculo->expects($this->once())->method('delete')->willReturn(false);
        $result = $this->controller->delete(7);
        $this->assertResponse($result, false, null, 'No se pudo dar de baja el vehículo.', [], 500);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result));
    }
}
