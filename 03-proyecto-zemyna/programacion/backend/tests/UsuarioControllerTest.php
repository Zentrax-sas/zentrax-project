<?php

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../controllers/UsuarioController.php';

class UsuarioControllerTest extends TestCase
{
    private UsuarioController $controller;
    private Usuario&MockObject $usuario;

    protected function setUp(): void
    {
        $this->usuario = $this->getMockBuilder(Usuario::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'read', 'findByEmail', 'create', 'update', 'delete', 'activar',
                'getRolesVigentes', 'getHistorialRoles', 'findRoleById',
                'findSectorByName', 'getRolesDisponibles', 'getSectoresDisponibles',
                'beginTransaction', 'commit', 'rollBack', 'assignRole',
                'getAsignacionVigente', 'finalizarAsignacion',
                'actualizarVigenciaAsignacion',
                'actualizarAsignacion',
            ])
            ->getMock();

        $controllerClass = new ReflectionClass(UsuarioController::class);
        $this->controller = $controllerClass->newInstanceWithoutConstructor();
        $property = new ReflectionProperty(UsuarioController::class, 'usuario');
        $property->setValue($this->controller, $this->usuario);
    }

    private function statement(array $rows): object
    {
        return new class($rows) {
            public function __construct(private array $rows) {}
            public function fetchAll(int $mode): array { return $this->rows; }
        };
    }

    private function validCreateData(): array
    {
        return [
            'nombre' => ' Ana ', 'apellido' => ' Pereyra ',
            'email' => ' ana@zemyna.com ', 'contrasena' => 'secret123',
            'telefono' => ' 099123456 ', 'id_centro' => '2', 'activo' => 'Activo',
            'id_rol' => '4', 'sector' => ' operaciones ',
            'fecha_desde' => '2026-09-06', 'fecha_hasta' => '',
        ];
    }

    private function expectValidRole(): void
    {
        $this->usuario->expects($this->once())->method('findRoleById')->with(4)
            ->willReturn(['id_rol' => 4, 'nombre' => 'OPERARIO']);
        $this->usuario->expects($this->once())->method('findSectorByName')->with('OPERACIONES')
            ->willReturn(['nombre' => 'OPERACIONES']);
    }

    private function validUpdateData(array $changes = []): array
    {
        return array_merge([
            'id_usuario' => 7, 'nombre' => 'Ana', 'apellido' => 'Pereyra',
            'email' => 'ana@zemyna.com', 'telefono' => '099123456',
            'id_centro' => '2', 'activo' => 'Activo',
        ], $changes);
    }

    private function expectExistingUser(int $id = 7): void
    {
        $this->usuario->expects($this->once())
            ->method('read')->with($id, 1, 1)
            ->willReturn($this->statement([['id_usuario' => $id]]));
    }

    private function assertResponse(array $result, bool $success, int $status, string $message): void
    {
        $this->assertSame($success, $result['success']);
        $this->assertSame($status, $result['statusCode']);
        $this->assertSame($message, $result['message']);
    }

    public function testGetAllListaUsuariosConRolesSinExponerContrasena(): void
    {
        $rows = [['id_usuario' => 1, 'email' => 'ana@zemyna.com', 'contrasena' => 'hash']];
        $this->usuario->expects($this->once())->method('read')->with(null, 1, 20)
            ->willReturn($this->statement($rows));
        $this->usuario->expects($this->once())->method('getRolesVigentes')->with(1)
            ->willReturn([['nombre' => 'Administrador']]);

        $result = $this->controller->getAll();

        $this->assertResponse($result, true, 200, 'Usuarios cargados correctamente.');
        $this->assertSame([['id_usuario' => 1, 'email' => 'ana@zemyna.com', 'roles' => [['nombre' => 'Administrador']]]], $result['data']);
        $this->assertArrayNotHasKey('contrasena', $result['data'][0]);
    }

    public function testGetAllAplicaIdFiltrosYPaginacionNormalizados(): void
    {
        $this->usuario->expects($this->once())->method('read')->with(9, 1, 100)
            ->willReturn($this->statement([['id_usuario' => 9]]));
        $this->usuario->expects($this->once())->method('getRolesVigentes')->with(9)->willReturn([]);

        $result = $this->controller->getAll(['id' => '9', 'page' => '-4', 'limit' => '500']);

        $this->assertResponse($result, true, 200, 'Usuarios cargados correctamente.');
        $this->assertSame(9, $result['data'][0]['id_usuario']);
    }

    public function testGetAllDevuelveNotFoundCuandoElIdNoExiste(): void
    {
        $this->usuario->expects($this->once())->method('read')->with(99, 1, 20)
            ->willReturn($this->statement([]));
        $this->usuario->expects($this->never())->method('getRolesVigentes');

        $result = $this->controller->getAll(['id' => 99]);

        $this->assertResponse($result, false, 404, 'Usuario no encontrado.');
        $this->assertSame([], $result['data']);
    }

    public function testGetAllDevuelveErrorCuandoFallaElModelo(): void
    {
        $this->usuario->expects($this->once())->method('read')->with(null, 1, 20)->willReturn(false);

        $result = $this->controller->getAll();

        $this->assertResponse($result, false, 500, 'No se pudieron cargar los usuarios.');
        $this->assertSame([], $result['data']);
    }

    public function testCreateRegistraUsuarioConDatosNormalizadosYHash(): void
    {
        $this->usuario->expects($this->once())->method('findByEmail')->with('ana@zemyna.com')->willReturn(false);
        $this->expectValidRole();
        $this->usuario->expects($this->once())->method('beginTransaction')->willReturn(true);
        $this->usuario->expects($this->once())->method('create')->willReturnCallback(function (): bool {
            $this->assertSame('Ana', $this->usuario->nombre);
            $this->assertSame('Pereyra', $this->usuario->apellido);
            $this->assertSame('ana@zemyna.com', $this->usuario->email);
            $this->assertSame('099123456', $this->usuario->telefono);
            $this->assertSame(2, $this->usuario->id_centro);
            $this->assertSame('Activo', $this->usuario->activo);
            $this->assertNotSame('secret123', $this->usuario->contrasena);
            $this->assertTrue(password_verify('secret123', $this->usuario->contrasena));
            $this->usuario->id_usuario = 42;
            return true;
        });
        $this->usuario->expects($this->once())->method('assignRole')
            ->with(42, 4, 'OPERACIONES', '2026-09-06', null)->willReturn(true);
        $this->usuario->expects($this->once())->method('commit')->willReturn(true);
        $this->usuario->expects($this->never())->method('rollBack');

        $result = $this->controller->create($this->validCreateData());

        $this->assertResponse($result, true, 201, 'Usuario y rol registrados con éxito.');
        $this->assertSame(['id_usuario' => 42], $result['data']);
        $this->assertSame([], $result['errors']);
        $this->assertArrayNotHasKey('contrasena', $result['data']);
    }

    public function testCreateRechazaCamposObligatoriosVacios(): void
    {
        $this->usuario->expects($this->never())->method('create');
        $result = $this->controller->create([]);

        $this->assertResponse($result, false, 400, 'No se pudo registrar el usuario.');
        $this->assertSame([
            'El nombre es obligatorio.', 'El apellido es obligatorio.', 'El email es obligatorio.',
            'La contraseña es obligatoria.', 'El teléfono es obligatorio.',
            'El id_centro debe ser un número entero válido.',
            'El rol es obligatorio.', 'El sector es obligatorio.',
        ], $result['errors']);
    }

    public function testCreateRechazaEmailInvalido(): void
    {
        $data = $this->validCreateData();
        $data['email'] = 'email-invalido';
        $this->usuario->expects($this->never())->method('findByEmail');

        $result = $this->controller->create($data);

        $this->assertResponse($result, false, 400, 'No se pudo registrar el usuario.');
        $this->assertSame(['El email no tiene un formato válido.'], $result['errors']);
    }

    public function testCreateRechazaEmailDuplicado(): void
    {
        $this->usuario->expects($this->once())->method('findByEmail')->with('ana@zemyna.com')
            ->willReturn(['id_usuario' => 5]);
        $this->usuario->expects($this->never())->method('create');

        $result = $this->controller->create($this->validCreateData());

        $this->assertResponse($result, false, 409, 'El email ya está registrado.');
        $this->assertSame(['El email ya existe.'], $result['errors']);
    }

    public function testCreateRechazaContrasenaDemasiadoCorta(): void
    {
        $data = $this->validCreateData();
        $data['contrasena'] = '12345';
        $this->usuario->expects($this->never())->method('create');

        $result = $this->controller->create($data);

        $this->assertResponse($result, false, 400, 'No se pudo registrar el usuario.');
        $this->assertSame(['La contraseña debe tener entre 6 y 72 caracteres.'], $result['errors']);
    }

    /** @dataProvider invalidCenterProvider */
    public function testCreateRechazaCentroInvalidoOFaltante(array $changes): void
    {
        $data = array_merge($this->validCreateData(), $changes);
        $this->usuario->expects($this->never())->method('create');

        $result = $this->controller->create($data);

        $this->assertResponse($result, false, 400, 'No se pudo registrar el usuario.');
        $this->assertContains('El id_centro debe ser un número entero válido.', $result['errors']);
    }

    public static function invalidCenterProvider(): array
    {
        return ['faltante' => [['id_centro' => null]], 'inválido' => [['id_centro' => 'centro']]];
    }

    public function testCreateDevuelveErrorCuandoFallaElModelo(): void
    {
        $this->usuario->expects($this->once())->method('findByEmail')->willReturn(false);
        $this->expectValidRole();
        $this->usuario->expects($this->once())->method('beginTransaction')->willReturn(true);
        $this->usuario->expects($this->once())->method('create')->willReturn(false);
        $this->usuario->expects($this->once())->method('rollBack');

        $result = $this->controller->create($this->validCreateData());

        $this->assertResponse($result, false, 500, 'Error al registrar el usuario.');
        $this->assertNull($result['data']);
        $this->assertSame([], $result['errors']);
    }

    public function testCreateRechazaRolFaltante(): void
    {
        $data = $this->validCreateData();
        unset($data['id_rol']);
        $this->usuario->expects($this->never())->method('create');

        $result = $this->controller->create($data);

        $this->assertResponse($result, false, 400, 'No se pudo registrar el usuario.');
        $this->assertSame(['El rol es obligatorio.'], $result['errors']);
    }

    public function testCreateRechazaRolInexistente(): void
    {
        $this->usuario->expects($this->once())->method('findByEmail')->willReturn(false);
        $this->usuario->expects($this->once())->method('findRoleById')->with(4)->willReturn(null);
        $this->usuario->expects($this->never())->method('create');

        $result = $this->controller->create($this->validCreateData());

        $this->assertResponse($result, false, 404, 'El rol seleccionado no existe.');
        $this->assertSame(['Seleccioná un rol válido.'], $result['errors']);
    }

    public function testCreateRechazaSectorInexistente(): void
    {
        $this->usuario->expects($this->once())->method('findByEmail')->willReturn(false);
        $this->usuario->expects($this->once())->method('findRoleById')->willReturn(['id_rol' => 4]);
        $this->usuario->expects($this->once())->method('findSectorByName')->with('OPERACIONES')->willReturn(null);
        $this->usuario->expects($this->never())->method('create');

        $result = $this->controller->create($this->validCreateData());

        $this->assertResponse($result, false, 400, 'El sector seleccionado no es válido.');
        $this->assertSame(['Seleccioná un sector admitido.'], $result['errors']);
    }

    /** @dataProvider invalidRoleDatesProvider */
    public function testCreateRechazaFechasDeRolInvalidas(array $changes, string $expectedError): void
    {
        $result = $this->controller->create(array_merge($this->validCreateData(), $changes));

        $this->assertResponse($result, false, 400, 'No se pudo registrar el usuario.');
        $this->assertContains($expectedError, $result['errors']);
    }

    public static function invalidRoleDatesProvider(): array
    {
        return [
            'desde inválida' => [['fecha_desde' => '06/09/2026'], 'La fecha_desde no es válida.'],
            'hasta inválida' => [['fecha_hasta' => 'mañana'], 'La fecha_hasta no es válida.'],
            'rango invertido' => [['fecha_desde' => '2026-09-06', 'fecha_hasta' => '2026-09-05'], 'La fecha_hasta no puede ser anterior a fecha_desde.'],
        ];
    }

    public function testCreateRevierteSiFallaAsignacionDeRol(): void
    {
        $this->usuario->expects($this->once())->method('findByEmail')->willReturn(false);
        $this->expectValidRole();
        $this->usuario->expects($this->once())->method('beginTransaction')->willReturn(true);
        $this->usuario->expects($this->once())->method('create')->willReturnCallback(function (): bool {
            $this->usuario->id_usuario = 42;
            return true;
        });
        $this->usuario->expects($this->once())->method('assignRole')->willReturn(false);
        $this->usuario->expects($this->never())->method('commit');
        $this->usuario->expects($this->once())->method('rollBack')->willReturn(true);

        $result = $this->controller->create($this->validCreateData());

        $this->assertResponse($result, false, 500, 'Error al registrar el usuario.');
        $this->assertNull($result['data']);
    }

    public function testGetRoleOptionsDevuelveCatalogosDelModelo(): void
    {
        $roles = [['id_rol' => 4, 'nombre' => 'OPERARIO']];
        $sectores = [['nombre' => 'OPERACIONES']];
        $this->usuario->expects($this->once())->method('getRolesDisponibles')->willReturn($roles);
        $this->usuario->expects($this->once())->method('getSectoresDisponibles')->willReturn($sectores);

        $result = $this->controller->getRoleOptions();

        $this->assertResponse($result, true, 200, 'Roles y sectores cargados correctamente.');
        $this->assertSame(['roles' => $roles, 'sectores' => $sectores], $result['data']);
    }

    private function validAssignment(array $changes = []): array
    {
        return array_merge([
            'id_usuario_rol' => 12, 'id_rol' => 4, 'sector' => 'OPERACIONES',
            'fecha_desde' => '2026-01-01', 'fecha_hasta' => null,
        ], $changes);
    }

    private function validUpdateWithAssignment(array $changes = []): array
    {
        return array_merge($this->validUpdateData(), [
            'id_rol' => 4, 'sector' => 'OPERACIONES',
            'fecha_desde' => '2026-01-01', 'fecha_hasta' => '',
        ], $changes);
    }

    public function testUpdateConAsignacionIdenticaNoLaDuplica(): void
    {
        $this->expectExistingUser();
        $this->usuario->method('findByEmail')->willReturn(['id_usuario' => 7]);
        $this->usuario->expects($this->once())->method('getAsignacionVigente')->with(7)->willReturn($this->validAssignment());
        $this->usuario->method('findRoleById')->willReturn(['id_rol' => 4]);
        $this->usuario->method('findSectorByName')->willReturn(['nombre' => 'OPERACIONES']);
        $this->usuario->expects($this->once())->method('update')->willReturn(true);
        $this->usuario->expects($this->never())->method('assignRole');
        $this->usuario->expects($this->never())->method('finalizarAsignacion');

        $result = $this->controller->update($this->validUpdateWithAssignment());

        $this->assertResponse($result, true, 200, 'Usuario actualizado con éxito.');
    }

    /** @dataProvider changedAssignmentProvider */
    public function testUpdateCambiaAsignacionEnUnaTransaccion(array $changes): void
    {
        $this->expectExistingUser();
        $this->usuario->method('findByEmail')->willReturn(['id_usuario' => 7]);
        $this->usuario->method('getAsignacionVigente')->willReturn($this->validAssignment());
        $this->usuario->method('findRoleById')->willReturn(['id_rol' => $changes['id_rol'] ?? 4]);
        $this->usuario->method('findSectorByName')->willReturn(['nombre' => $changes['sector'] ?? 'OPERACIONES']);
        $this->usuario->expects($this->once())->method('beginTransaction')->willReturn(true);
        $this->usuario->expects($this->once())->method('update')->willReturn(true);
        $this->usuario->expects($this->once())->method('finalizarAsignacion')->with(12, '2026-01-31')->willReturn(true);
        $this->usuario->expects($this->once())->method('assignRole')->willReturn(true);
        $this->usuario->expects($this->once())->method('commit')->willReturn(true);

        $result = $this->controller->update($this->validUpdateWithAssignment(array_merge(['fecha_desde' => '2026-02-01'], $changes)));

        $this->assertResponse($result, true, 200, 'Usuario actualizado con éxito.');
    }

    public static function changedAssignmentProvider(): array
    {
        return [
            'rol' => [['id_rol' => 5]],
            'sector' => [['sector' => 'INSPECCION']],
            'rol y sector' => [['id_rol' => 5, 'sector' => 'INSPECCION']],
        ];
    }

    public function testUpdateModificaSoloFechaHastaSinDuplicarAsignacion(): void
    {
        $this->expectExistingUser();
        $this->usuario->method('findByEmail')->willReturn(false);
        $this->usuario->method('getAsignacionVigente')->willReturn($this->validAssignment());
        $this->usuario->method('findRoleById')->willReturn(['id_rol' => 4]);
        $this->usuario->method('findSectorByName')->willReturn(['nombre' => 'OPERACIONES']);
        $this->usuario->method('beginTransaction')->willReturn(true);
        $this->usuario->method('update')->willReturn(true);
        $this->usuario->expects($this->once())->method('actualizarVigenciaAsignacion')->with(12, '2026-12-31')->willReturn(true);
        $this->usuario->expects($this->never())->method('finalizarAsignacion');
        $this->usuario->expects($this->never())->method('assignRole');
        $this->usuario->expects($this->once())->method('commit')->willReturn(true);
        $result = $this->controller->update($this->validUpdateWithAssignment(['fecha_hasta' => '2026-12-31']));
        $this->assertResponse($result, true, 200, 'Usuario actualizado con éxito.');
    }

    public function testUpdateRechazaUsuarioSinAsignacionVigente(): void
    {
        $this->expectExistingUser();
        $this->usuario->method('findByEmail')->willReturn(false);
        $this->usuario->expects($this->once())->method('getAsignacionVigente')->willReturn(null);
        $result = $this->controller->update($this->validUpdateWithAssignment());
        $this->assertResponse($result, false, 404, 'El usuario no posee una asignación vigente.');
    }

    /** @dataProvider assignmentLookupFailureProvider */
    public function testUpdateRechazaRolOSectorInexistente(string $missing, string $message): void
    {
        $this->expectExistingUser();
        $this->usuario->method('findByEmail')->willReturn(false);
        $this->usuario->method('getAsignacionVigente')->willReturn($this->validAssignment());
        $this->usuario->method('findRoleById')->willReturn($missing === 'rol' ? null : ['id_rol' => 4]);
        $this->usuario->method('findSectorByName')->willReturn($missing === 'sector' ? null : ['nombre' => 'OPERACIONES']);
        $result = $this->controller->update($this->validUpdateWithAssignment());
        $this->assertResponse($result, false, 404, $message);
    }

    public static function assignmentLookupFailureProvider(): array
    {
        return [['rol', 'El rol seleccionado no existe.'], ['sector', 'El sector seleccionado no existe.']];
    }

    /** @dataProvider assignmentWriteFailureProvider */
    public function testUpdateRevierteAnteFalloDeAsignacion(string $failure): void
    {
        $this->expectExistingUser();
        $this->usuario->method('findByEmail')->willReturn(false);
        $this->usuario->method('getAsignacionVigente')->willReturn($this->validAssignment());
        $this->usuario->method('findRoleById')->willReturn(['id_rol' => 5]);
        $this->usuario->method('findSectorByName')->willReturn(['nombre' => 'INSPECCION']);
        $this->usuario->method('beginTransaction')->willReturn(true);
        $this->usuario->method('update')->willReturn(true);
        $this->usuario->method('finalizarAsignacion')->willReturn($failure !== 'cierre');
        $this->usuario->method('assignRole')->willReturn($failure !== 'creación');
        $this->usuario->expects($this->once())->method('rollBack')->willReturn(true);

        $result = $this->controller->update($this->validUpdateWithAssignment(['id_rol' => 5, 'sector' => 'INSPECCION', 'fecha_desde' => '2026-02-01']));

        $this->assertResponse($result, false, 500, 'No se pudo actualizar el usuario.');
    }

    public static function assignmentWriteFailureProvider(): array
    {
        return [['cierre'], ['creación']];
    }

    /** @dataProvider invalidUpdateAssignmentProvider */
    public function testUpdateRechazaAsignacionInvalida(array $changes, string $error): void
    {
        $this->usuario->expects($this->never())->method('read');
        $result = $this->controller->update($this->validUpdateWithAssignment($changes));
        $this->assertResponse($result, false, 400, 'No se pudo actualizar el usuario.');
        $this->assertContains($error, $result['errors']);
    }

    public static function invalidUpdateAssignmentProvider(): array
    {
        return [
            'rol inválido' => [['id_rol' => 0], 'El rol es obligatorio.'],
            'fecha inicial inválida' => [['fecha_desde' => 'mañana'], 'La fecha_desde no es válida.'],
            'rango inválido' => [['fecha_hasta' => '2025-12-31'], 'La fecha_hasta no puede ser anterior a fecha_desde.'],
        ];
    }

    public function testUpdateRechazaReemplazoSinIntervaloTemporalDisponible(): void
    {
        $this->expectExistingUser();
        $this->usuario->method('findByEmail')->willReturn(false);
        $this->usuario->method('getAsignacionVigente')->willReturn($this->validAssignment());
        $this->usuario->method('findRoleById')->willReturn(['id_rol' => 5]);
        $this->usuario->method('findSectorByName')->willReturn(['nombre' => 'OPERACIONES']);
        $result = $this->controller->update($this->validUpdateWithAssignment(['id_rol' => 5]));
        $this->assertResponse($result, false, 409, 'La nueva asignación debe comenzar después de la asignación vigente.');
    }

    /** @dataProvider sameDayAssignmentProvider */
    public function testUpdateReutilizaAsignacionQueComenzoHoy(array $changes): void
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone('America/Montevideo')))->format('Y-m-d');
        $this->expectExistingUser();
        $this->usuario->method('findByEmail')->willReturn(false);
        $this->usuario->method('getAsignacionVigente')->willReturn($this->validAssignment(['fecha_desde' => $today]));
        $this->usuario->method('findRoleById')->willReturn(['id_rol' => $changes['id_rol'] ?? 4]);
        $this->usuario->method('findSectorByName')->willReturn(['nombre' => $changes['sector'] ?? 'OPERACIONES']);
        $this->usuario->method('beginTransaction')->willReturn(true);
        $this->usuario->method('update')->willReturn(true);
        $this->usuario->expects($this->once())->method('actualizarAsignacion')
            ->with(12, $changes['id_rol'] ?? 4, $changes['sector'] ?? 'OPERACIONES', $today, null)->willReturn(true);
        $this->usuario->expects($this->never())->method('finalizarAsignacion');
        $this->usuario->expects($this->never())->method('assignRole');
        $this->usuario->expects($this->once())->method('commit')->willReturn(true);

        $result = $this->controller->update($this->validUpdateWithAssignment(array_merge(['fecha_desde' => $today], $changes)));

        $this->assertResponse($result, true, 200, 'Usuario actualizado con éxito.');
    }

    public static function sameDayAssignmentProvider(): array
    {
        return [
            'rol' => [['id_rol' => 5]],
            'sector' => [['sector' => 'INSPECCION']],
            'rol y sector' => [['id_rol' => 5, 'sector' => 'INSPECCION']],
        ];
    }

    public function testUpdateRevierteSiFallaReutilizacionDelMismoDia(): void
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone('America/Montevideo')))->format('Y-m-d');
        $this->expectExistingUser();
        $this->usuario->method('findByEmail')->willReturn(false);
        $this->usuario->method('getAsignacionVigente')->willReturn($this->validAssignment(['fecha_desde' => $today]));
        $this->usuario->method('findRoleById')->willReturn(['id_rol' => 5]);
        $this->usuario->method('findSectorByName')->willReturn(['nombre' => 'OPERACIONES']);
        $this->usuario->method('beginTransaction')->willReturn(true);
        $this->usuario->method('update')->willReturn(true);
        $this->usuario->expects($this->once())->method('actualizarAsignacion')->willReturn(false);
        $this->usuario->expects($this->never())->method('assignRole');
        $this->usuario->expects($this->once())->method('rollBack')->willReturn(true);

        $result = $this->controller->update($this->validUpdateWithAssignment(['id_rol' => 5, 'fecha_desde' => $today]));

        $this->assertResponse($result, false, 500, 'No se pudo actualizar el usuario.');
    }

    public function testUpdateSinNuevaContrasenaConservaElHashActual(): void
    {
        $this->expectExistingUser();
        $this->usuario->expects($this->once())->method('findByEmail')->with('ana@zemyna.com')
            ->willReturn(['id_usuario' => 7]);
        $this->usuario->expects($this->once())->method('update')->willReturnCallback(function (): bool {
            $this->assertSame(7, $this->usuario->id_usuario);
            $this->assertNull($this->usuario->contrasena);
            return true;
        });

        $result = $this->controller->update($this->validUpdateData());

        $this->assertResponse($result, true, 200, 'Usuario actualizado con éxito.');
        $this->assertNull($result['data']);
        $this->assertSame([], $result['errors']);
    }

    /** @dataProvider invalidUpdateIdProvider */
    public function testUpdateRechazaIdFaltanteOInvalido($id): void
    {
        $data = $this->validUpdateData(['id_usuario' => $id]);
        $this->usuario->expects($this->never())->method('read');
        $this->usuario->expects($this->never())->method('update');

        $result = $this->controller->update($data);

        $this->assertResponse($result, false, 400, 'No se pudo actualizar el usuario.');
        $this->assertContains('El id_usuario debe ser un número entero positivo.', $result['errors']);
    }

    public static function invalidUpdateIdProvider(): array
    {
        return ['faltante' => [null], 'texto' => ['abc'], 'cero' => [0], 'negativo' => [-2]];
    }

    public function testUpdateDevuelveNotFoundCuandoUsuarioNoExiste(): void
    {
        $this->usuario->expects($this->once())->method('read')->with(7, 1, 1)
            ->willReturn($this->statement([]));
        $this->usuario->expects($this->never())->method('update');

        $result = $this->controller->update($this->validUpdateData());

        $this->assertResponse($result, false, 404, 'No se pudo actualizar el usuario.');
        $this->assertSame(['El usuario no existe o la actualización falló.'], $result['errors']);
    }

    public function testUpdateRechazaEmailInvalido(): void
    {
        $this->usuario->expects($this->never())->method('read');
        $result = $this->controller->update($this->validUpdateData(['email' => 'incorrecto']));

        $this->assertResponse($result, false, 400, 'No se pudo actualizar el usuario.');
        $this->assertSame(['El email no tiene un formato válido.'], $result['errors']);
    }

    public function testUpdateRechazaEmailDeOtroUsuario(): void
    {
        $this->expectExistingUser();
        $this->usuario->expects($this->once())->method('findByEmail')->with('ana@zemyna.com')
            ->willReturn(['id_usuario' => 8]);
        $this->usuario->expects($this->never())->method('update');

        $result = $this->controller->update($this->validUpdateData());

        $this->assertResponse($result, false, 409, 'El email ya está registrado.');
        $this->assertSame(['El email ya existe.'], $result['errors']);
    }

    public function testUpdateHasheaNuevaContrasenaYVerificaLaOriginal(): void
    {
        $this->expectExistingUser();
        $this->usuario->expects($this->once())->method('findByEmail')->willReturn(false);
        $this->usuario->expects($this->once())->method('update')->willReturnCallback(function (): bool {
            $this->assertNotSame('nuevaClave9', $this->usuario->contrasena);
            $this->assertTrue(password_verify('nuevaClave9', $this->usuario->contrasena));
            return true;
        });

        $result = $this->controller->update($this->validUpdateData(['contrasena' => 'nuevaClave9']));

        $this->assertResponse($result, true, 200, 'Usuario actualizado con éxito.');
        $this->assertArrayNotHasKey('contrasena', $result);
    }

    /** @dataProvider failedReadProvider */
    public function testUpdateDevuelveErrorCuandoReadRetornaNullOFalse($modelResult): void
    {
        $this->usuario->expects($this->once())->method('read')->willReturn($modelResult);
        $this->usuario->expects($this->never())->method('update');

        $result = $this->controller->update($this->validUpdateData());

        $this->assertResponse($result, false, 500, 'No se pudo actualizar el usuario.');
        $this->assertSame(['La verificación del usuario falló.'], $result['errors']);
    }

    public static function failedReadProvider(): array
    {
        return ['null' => [null], 'false' => [false]];
    }

    /** @dataProvider persistenceExceptionProvider */
    public function testUpdateConvierteExcepcionDePersistenciaEnErrorSeguro(RuntimeException $exception): void
    {
        $this->usuario->expects($this->once())->method('read')
            ->willThrowException($exception);
        $this->usuario->expects($this->never())->method('update');

        $result = $this->controller->update($this->validUpdateData());

        $this->assertResponse($result, false, 500, 'No se pudo actualizar el usuario.');
        $this->assertSame(['La verificación del usuario falló.'], $result['errors']);
        $serialized = json_encode($result);
        $this->assertStringNotContainsString('SQLSTATE', $serialized);
        $this->assertStringNotContainsString('secreto', $serialized);
        $this->assertStringNotContainsString('ruta/interna', $serialized);
    }

    public static function persistenceExceptionProvider(): array
    {
        return [
            'PDOException' => [new PDOException('SQLSTATE[HY000] password=secreto ruta/interna')],
            'RuntimeException de persistencia' => [new RuntimeException('SQLSTATE[HY000] password=secreto ruta/interna')],
        ];
    }

    public function testUpdateNoOcultaErroresDeProgramacion(): void
    {
        $this->usuario->expects($this->once())->method('read')
            ->willThrowException(new TypeError('Defecto de programación'));
        $this->expectException(TypeError::class);

        $this->controller->update($this->validUpdateData());
    }

    public function testUpdateConvierteExcepcionPdoAlBuscarEmailEnErrorSeguro(): void
    {
        $this->expectExistingUser();
        $this->usuario->expects($this->once())->method('findByEmail')
            ->willThrowException(new PDOException('SQL interno sensible'));
        $this->usuario->expects($this->never())->method('update');

        $result = $this->controller->update($this->validUpdateData());

        $this->assertResponse($result, false, 500, 'No se pudo actualizar el usuario.');
        $this->assertSame(['La verificación del email falló.'], $result['errors']);
        $this->assertStringNotContainsString('SQL interno sensible', json_encode($result));
    }

    public function testUpdateDevuelveErrorCuandoFallaGuardadoDelModelo(): void
    {
        $this->expectExistingUser();
        $this->usuario->expects($this->once())->method('findByEmail')->willReturn(false);
        $this->usuario->expects($this->once())->method('update')->willReturn(false);

        $result = $this->controller->update($this->validUpdateData());

        $this->assertResponse($result, false, 500, 'No se pudo actualizar el usuario.');
        $this->assertSame(['La actualización del usuario falló.'], $result['errors']);
    }

    public function testDeleteDesactivaUsuarioExistente(): void
    {
        $this->expectExistingUser();
        $this->usuario->expects($this->once())->method('delete')->willReturnCallback(function (): bool {
            $this->assertSame(7, $this->usuario->id_usuario);
            return true;
        });

        $result = $this->controller->delete('7');

        $this->assertResponse($result, true, 200, 'Usuario desactivado con éxito.');
        $this->assertSame([], $result['errors']);
    }

    public function testDeleteRechazaIdInvalido(): void
    {
        $this->usuario->expects($this->never())->method('delete');
        $result = $this->controller->delete('abc');

        $this->assertResponse($result, false, 400, 'El id_usuario no es válido.');
        $this->assertSame(['El id_usuario debe ser un número entero positivo.'], $result['errors']);
    }

    public function testDeleteDevuelveNotFoundCuandoUsuarioNoExiste(): void
    {
        $this->usuario->expects($this->once())->method('read')->willReturn($this->statement([]));
        $this->usuario->expects($this->never())->method('delete');
        $result = $this->controller->delete(7);

        $this->assertResponse($result, false, 404, 'Usuario no encontrado.');
        $this->assertSame(['No existe el usuario solicitado.'], $result['errors']);
    }

    public function testDeleteDevuelveErrorCuandoFallaElModelo(): void
    {
        $this->expectExistingUser();
        $this->usuario->expects($this->once())->method('delete')->willReturn(false);
        $result = $this->controller->delete(7);

        $this->assertResponse($result, false, 500, 'No se pudo desactivar el usuario.');
        $this->assertSame(['La desactivación del usuario falló.'], $result['errors']);
    }

    public function testDeleteConvierteExcepcionPdoEnErrorSeguro(): void
    {
        $this->expectExistingUser();
        $this->usuario->expects($this->once())->method('delete')
            ->willThrowException(new PDOException('SQL de baja sensible'));

        $result = $this->controller->delete(7);

        $this->assertResponse($result, false, 500, 'No se pudo desactivar el usuario.');
        $this->assertSame(['La desactivación del usuario falló.'], $result['errors']);
        $this->assertStringNotContainsString('SQL de baja sensible', json_encode($result));
    }

    public function testActivarReactivaUsuarioExistente(): void
    {
        $this->expectExistingUser();
        $this->usuario->expects($this->once())->method('activar')->willReturnCallback(function (): bool {
            $this->assertSame(7, $this->usuario->id_usuario);
            return true;
        });
        $result = $this->controller->activar(7);

        $this->assertResponse($result, true, 200, 'Usuario activado con éxito.');
        $this->assertSame([], $result['errors']);
    }

    public function testActivarRechazaIdInvalido(): void
    {
        $this->usuario->expects($this->never())->method('activar');
        $result = $this->controller->activar(0);

        $this->assertResponse($result, false, 400, 'El id_usuario no es válido.');
        $this->assertSame(['El id_usuario debe ser un número entero positivo.'], $result['errors']);
    }

    public function testActivarDevuelveNotFoundCuandoUsuarioNoExiste(): void
    {
        $this->usuario->expects($this->once())->method('read')->willReturn($this->statement([]));
        $this->usuario->expects($this->never())->method('activar');
        $result = $this->controller->activar(7);

        $this->assertResponse($result, false, 404, 'No se pudo activar el usuario.');
        $this->assertSame(['No existe el usuario solicitado.'], $result['errors']);
    }

    public function testActivarDevuelveErrorCuandoFallaElModelo(): void
    {
        $this->expectExistingUser();
        $this->usuario->expects($this->once())->method('activar')->willReturn(false);
        $result = $this->controller->activar(7);

        $this->assertResponse($result, false, 500, 'No se pudo activar el usuario.');
        $this->assertSame(['La activación del usuario falló.'], $result['errors']);
    }

    public function testActivarConvierteExcepcionPdoEnErrorSeguro(): void
    {
        $this->expectExistingUser();
        $this->usuario->expects($this->once())->method('activar')
            ->willThrowException(new PDOException('SQL de activación sensible'));

        $result = $this->controller->activar(7);

        $this->assertResponse($result, false, 500, 'No se pudo activar el usuario.');
        $this->assertSame(['La activación del usuario falló.'], $result['errors']);
        $this->assertStringNotContainsString('SQL de activación sensible', json_encode($result));
    }

    public function testHistorialRolesDevuelveDatosDelModelo(): void
    {
        $historial = [['rol' => 'Administrador', 'fecha_inicio' => '2026-01-01']];
        $this->usuario->expects($this->once())->method('getHistorialRoles')->with(7)
            ->willReturn($historial);

        $result = $this->controller->historialRoles('7');

        $this->assertResponse($result, true, 200, 'Historial de roles cargado correctamente.');
        $this->assertSame($historial, $result['data']);
    }
}
