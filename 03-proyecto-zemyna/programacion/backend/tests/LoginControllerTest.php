<?php

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../controllers/LoginController.php';

class LoginControllerTest extends TestCase
{
    private Usuario&MockObject $usuarioModel;
    private LoginController $controller;
    private array $usuarioValido;
    private array $roles;
    private array $permisos;
    private array $autorizaciones;

    protected function setUp(): void
    {
        $this->usuarioModel = $this->createUsuarioMock();
        $this->controller = new LoginController($this->usuarioModel);
        $this->usuarioValido = [
            'id_usuario' => '7',
            'nombre' => 'Ana',
            'apellido' => 'Pereyra',
            'email' => 'ana@zemyna.com',
            'contrasena' => password_hash('clave-segura', PASSWORD_BCRYPT),
            'id_centro' => '2',
            'activo' => 'Activo',
        ];
        $this->roles = [
            ['id_usuario_rol' => 10, 'id_rol' => 3, 'nombre' => 'Operario', 'sector' => ' operaciones '],
            ['id_usuario_rol' => 11, 'id_rol' => 4, 'nombre' => 'Inspector', 'sector' => ' inspeccion '],
        ];
        $this->permisos = [' incidencia.crear ', 'contenedor.consultar'];
        $this->autorizaciones = [
            ['rol' => ' Operario ', 'sector' => ' operaciones ', 'permiso' => ' incidencia.crear '],
            ['rol' => 'Inspector', 'sector' => ' inspeccion ', 'permiso' => ' contenedor.consultar '],
        ];
    }

    private function createUsuarioMock(): Usuario&MockObject
    {
        return $this->getMockBuilder(Usuario::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'findByEmail',
                'getRolesVigentes',
                'getPermisosVigentes',
                'getAutorizacionesVigentes',
            ])
            ->getMock();
    }

    private function configureValidUser(?array $roles = null): void
    {
        $this->usuarioModel->method('findByEmail')->willReturn($this->usuarioValido);
        $this->usuarioModel->method('getRolesVigentes')->willReturn($roles ?? $this->roles);
        $this->usuarioModel->method('getPermisosVigentes')->willReturn($this->permisos);
        $this->usuarioModel->method('getAutorizacionesVigentes')->willReturn($this->autorizaciones);
    }

    public function testRechazaEmailVacio(): void
    {
        $this->usuarioModel->expects($this->never())->method('findByEmail');

        $result = $this->controller->authenticate('   ', 'clave-segura');

        $this->assertSame(400, $result['statusCode']);
        $this->assertSame('Email y contraseña son obligatorios.', $result['message']);
    }

    public function testRechazaContrasenaVacia(): void
    {
        $this->usuarioModel->expects($this->never())->method('findByEmail');

        $result = $this->controller->authenticate('ana@zemyna.com', '   ');

        $this->assertSame(400, $result['statusCode']);
        $this->assertSame('Email y contraseña son obligatorios.', $result['message']);
    }

    public function testRechazaUsuarioInexistente(): void
    {
        $this->usuarioModel->expects($this->once())->method('findByEmail')->with('nadie@zemyna.com')->willReturn(null);

        $result = $this->controller->authenticate('nadie@zemyna.com', 'clave-segura');

        $this->assertSame(401, $result['statusCode']);
        $this->assertSame('Credenciales inválidas.', $result['message']);
        $this->assertSame(['Email o contraseña incorrectos.'], $result['errors']);
    }

    public function testRechazaContrasenaIncorrecta(): void
    {
        $this->usuarioModel->method('findByEmail')->willReturn($this->usuarioValido);

        $result = $this->controller->authenticate('ana@zemyna.com', 'incorrecta');

        $this->assertSame(401, $result['statusCode']);
        $this->assertSame('Credenciales inválidas.', $result['message']);
        $this->assertSame(['Email o contraseña incorrectos.'], $result['errors']);
    }

    public function testRechazaUsuarioInactivo(): void
    {
        $usuario = $this->usuarioValido;
        $usuario['activo'] = 'Inactivo';
        $this->usuarioModel->method('findByEmail')->willReturn($usuario);

        $result = $this->controller->authenticate('ana@zemyna.com', 'clave-segura');

        $this->assertSame(403, $result['statusCode']);
        $this->assertSame('La cuenta se encuentra inactiva.', $result['message']);
    }

    public function testRechazaUsuarioSinRolesVigentes(): void
    {
        $this->usuarioModel->method('findByEmail')->willReturn($this->usuarioValido);
        $this->usuarioModel->method('getRolesVigentes')->willReturn([]);

        $result = $this->controller->authenticate('ana@zemyna.com', 'clave-segura');

        $this->assertSame(403, $result['statusCode']);
        $this->assertSame('El usuario no posee roles vigentes.', $result['message']);
    }

    public function testAutenticaCorrectamenteUsuarioValido(): void
    {
        $this->configureValidUser();

        $result = $this->controller->authenticate(' ana@zemyna.com ', ' clave-segura ');

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['statusCode']);
        $this->assertSame('Inicio de sesión correcto.', $result['message']);
        $this->assertSame(7, $result['data']['id_usuario']);
        $this->assertSame(2, $result['data']['id_centro']);
    }

    public function testNormalizaCorrectamenteNombresDeRoles(): void
    {
        $roles = [
            ['nombre' => 'Superusuario'],
            ['nombre' => ' responsable-sectorial '],
            ['nombre' => 'Inspector'],
        ];
        $this->configureValidUser($roles);

        $result = $this->controller->authenticate('ana@zemyna.com', 'clave-segura');

        $this->assertSame(
            ['ADMINISTRADOR_TI', 'RESPONSABLE_SECTORIAL', 'INSPECTOR'],
            $result['sessionUser']['roles']
        );
        $this->assertSame($roles, $result['data']['roles']);
    }

    public function testCargaAsignacionesPermisosYAutorizaciones(): void
    {
        $this->configureValidUser();

        $result = $this->controller->authenticate('ana@zemyna.com', 'clave-segura');

        $this->assertSame($this->roles, $result['sessionUser']['asignaciones']);
        $this->assertSame(['incidencia.crear', 'contenedor.consultar'], $result['data']['permisos']);
        $this->assertSame($result['data']['permisos'], $result['sessionUser']['permisos']);
        $this->assertSame($result['data']['autorizaciones'], $result['sessionUser']['autorizaciones']);
    }

    public function testNormalizaSectorYPermiso(): void
    {
        $this->configureValidUser();

        $result = $this->controller->authenticate('ana@zemyna.com', 'clave-segura');

        $this->assertSame([
            ['rol' => 'OPERARIO', 'sector' => 'OPERACIONES', 'permiso' => 'incidencia.crear'],
            ['rol' => 'INSPECTOR', 'sector' => 'INSPECCION', 'permiso' => 'contenedor.consultar'],
        ], $result['data']['autorizaciones']);
    }

    public function testDataNoContieneContrasena(): void
    {
        $this->configureValidUser();

        $result = $this->controller->authenticate('ana@zemyna.com', 'clave-segura');

        $this->assertArrayNotHasKey('contrasena', $result['data']);
        $this->assertStringNotContainsString($this->usuarioValido['contrasena'], json_encode($result['data']));
    }

    public function testSessionUserNoContieneContrasena(): void
    {
        $this->configureValidUser();

        $result = $this->controller->authenticate('ana@zemyna.com', 'clave-segura');

        $this->assertArrayNotHasKey('contrasena', $result['sessionUser']);
        $this->assertStringNotContainsString($this->usuarioValido['contrasena'], json_encode($result['sessionUser']));
    }

    public function testDevuelveExactamenteStatusCodeYMensajesAnteriores(): void
    {
        $this->assertSame(
            ['success' => false, 'message' => 'Email y contraseña son obligatorios.', 'statusCode' => 400],
            $this->controller->authenticate('', '')
        );

        $model = $this->createUsuarioMock();
        $model->method('findByEmail')->willReturn(null);
        $this->assertSame([
            'success' => false,
            'message' => 'Credenciales inválidas.',
            'errors' => ['Email o contraseña incorrectos.'],
            'statusCode' => 401,
        ], (new LoginController($model))->authenticate('nadie@zemyna.com', 'clave'));

        $model = $this->createUsuarioMock();
        $inactivo = $this->usuarioValido;
        $inactivo['activo'] = 'Inactivo';
        $model->method('findByEmail')->willReturn($inactivo);
        $this->assertSame(
            ['success' => false, 'message' => 'La cuenta se encuentra inactiva.', 'statusCode' => 403],
            (new LoginController($model))->authenticate('ana@zemyna.com', 'clave-segura')
        );

        $model = $this->createUsuarioMock();
        $model->method('findByEmail')->willReturn($this->usuarioValido);
        $model->method('getRolesVigentes')->willReturn([]);
        $this->assertSame(
            ['success' => false, 'message' => 'El usuario no posee roles vigentes.', 'statusCode' => 403],
            (new LoginController($model))->authenticate('ana@zemyna.com', 'clave-segura')
        );
    }
}
