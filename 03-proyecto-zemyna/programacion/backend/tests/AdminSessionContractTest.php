<?php

use PHPUnit\Framework\TestCase;

class AdminSessionContractTest extends TestCase
{
    private string $adminHtml;
    private string $adminJs;

    protected function setUp(): void
    {
        $this->adminHtml = file_get_contents(__DIR__ . '/../../frontend/public/admin.html');
        $this->adminJs = file_get_contents(__DIR__ . '/../../frontend/public/assets/js/admin.js');
    }

    public function testSaludoUsaNombreDeSesionYTextContent(): void
    {
        $this->assertStringContainsString("/backend/api/session.php", $this->adminJs);
        $this->assertStringContainsString("getElementById('welcomeGreeting').textContent", $this->adminJs);
        $this->assertStringContainsString('${greetingForHour(new Date().getHours())}, ${nombre}', $this->adminJs);
        $this->assertStringNotContainsString('Facundo Bustamante', $this->adminHtml);
        $this->assertStringNotContainsString('Buenas tardes, Facundo', $this->adminHtml);
    }

    public function testSesionExpiradaRedirigeAlLogin(): void
    {
        $this->assertMatchesRegularExpression('/response\.status\s*===\s*401[^;]+redirectToLogin\(\)/', $this->adminJs);
        $this->assertStringContainsString("window.location.replace(loginUrl())", $this->adminJs);
        $sessionApi = file_get_contents(__DIR__ . '/../api/session.php');
        $this->assertStringContainsString('requireAuth();', $sessionApi);
    }

    public function testLogoutEsPostDestruyeSesionYCancelaCookie(): void
    {
        $logoutApi = file_get_contents(__DIR__ . '/../api/logout.php');
        $this->assertStringContainsString("REQUEST_METHOD'] !== 'POST'", $logoutApi);
        $this->assertStringContainsString('http_response_code(405)', $logoutApi);
        $this->assertStringContainsString('requireAuth();', $logoutApi);
        $this->assertStringContainsString('$_SESSION = [];', $logoutApi);
        $this->assertStringContainsString('session_get_cookie_params()', $logoutApi);
        $this->assertStringContainsString('session_destroy()', $logoutApi);
        $this->assertStringContainsString("method: 'POST'", $this->adminJs);
        $this->assertStringContainsString('logoutButton.disabled = true', $this->adminJs);
    }

    public function testFormularioCargaRolesYNoLosHardcodea(): void
    {
        $this->assertStringContainsString('name="id_rol" required', $this->adminHtml);
        $this->assertStringContainsString('name="sector" required', $this->adminHtml);
        $this->assertStringContainsString('name="fecha_desde" type="date" required', $this->adminHtml);
        $this->assertStringContainsString('/backend/api/roles.php', $this->adminJs);
        $this->assertStringContainsString('option.textContent = String(record[labelKey])', $this->adminJs);
        $this->assertStringNotContainsString('<option value="OPERARIO">', $this->adminHtml);
    }

    public function testAsignacionDeRolesExigePermisoEspecifico(): void
    {
        $rolesApi = file_get_contents(__DIR__ . '/../api/roles.php');
        $usuariosApi = file_get_contents(__DIR__ . '/../api/usuarios.php');
        $this->assertStringContainsString("requirePermission('usuario.asignar_rol', ['TI'])", $rolesApi);
        $this->assertStringContainsString("requirePermission('usuario.crear', ['TI'])", $usuariosApi);
        $this->assertStringContainsString("requirePermission('usuario.asignar_rol', ['TI'])", $usuariosApi);
        $this->assertStringContainsString("requirePermission('usuario.modificar', ['TI'])", $usuariosApi);
        $this->assertStringContainsString('array_intersect($assignmentFields, array_keys($data ?? []))', $usuariosApi);
    }

    public function testPostIncluyeRolYPutNoAlteraAsignaciones(): void
    {
        $this->assertStringContainsString('payload.id_rol = Number(payload.id_rol)', $this->adminJs);
        $this->assertStringContainsString('field.disabled = !enabled', $this->adminJs);
        $this->assertStringContainsString('setRoleFieldsEnabled(true)', $this->adminJs);
        $this->assertStringContainsString('if (isUpdate) payload.id_usuario = idUsuario', $this->adminJs);
        $this->assertStringContainsString('userForm.dataset.originalAssignment', $this->adminJs);
    }

    public function testFechaYHoraDelPanelSonDinamicasYDeMontevideo(): void
    {
        $this->assertStringNotContainsString('20 de julio de 2026', $this->adminHtml);
        $this->assertStringNotContainsString('Última sincronización: 19:42', $this->adminHtml);
        $this->assertStringContainsString("new Intl.DateTimeFormat('es-UY'", $this->adminJs);
        $this->assertStringContainsString("timeZone: 'America/Montevideo'", $this->adminJs);
        $this->assertStringContainsString("getElementById('currentDate').textContent", $this->adminJs);
        $this->assertStringContainsString("getElementById('localTime').textContent", $this->adminJs);
        $this->assertStringContainsString('window.clearInterval(panelClockInterval)', $this->adminJs);
    }
}
