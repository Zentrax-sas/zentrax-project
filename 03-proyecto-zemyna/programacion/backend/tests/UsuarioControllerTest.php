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
                'getRolesVigentes', 'getHistorialRoles',
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
        ];
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

        $result = $this->controller->create($this->validCreateData());

        $this->assertResponse($result, true, 201, 'Usuario registrado con éxito.');
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
        $this->usuario->expects($this->once())->method('create')->willReturn(false);

        $result = $this->controller->create($this->validCreateData());

        $this->assertResponse($result, false, 500, 'Error al registrar el usuario.');
        $this->assertNull($result['data']);
        $this->assertSame([], $result['errors']);
    }

    public function testUpdateActualizaSinCambiarContrasena(): void
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

    public function testUpdateDevuelveErrorCuandoFallaComprobacionDelModelo(): void
    {
        $this->usuario->expects($this->once())->method('read')->willReturn(false);
        $this->usuario->expects($this->never())->method('update');

        $result = $this->controller->update($this->validUpdateData());

        $this->assertResponse($result, false, 500, 'No se pudo actualizar el usuario.');
        $this->assertSame(['La verificación del usuario falló.'], $result['errors']);
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
