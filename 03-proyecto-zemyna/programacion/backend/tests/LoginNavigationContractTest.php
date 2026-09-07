<?php

use PHPUnit\Framework\TestCase;

class LoginNavigationContractTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(__DIR__ . '/../../frontend/public/login.html');
    }

    /** @dataProvider internalRoleProvider */
    public function testContemplaCadaRolInternoVigente(string $role): void
    {
        $this->assertStringContainsString("'{$role}'", $this->source);
    }

    public static function internalRoleProvider(): array
    {
        return [
            ['ADMINISTRADOR_TI'],
            ['RESPONSABLE_SECTORIAL'],
            ['ADMINISTRATIVO_OPERATIVO'],
            ['OPERARIO'],
            ['INSPECTOR'],
        ];
    }

    public function testEvaluaTodosLosRolesYNormalizaElNombre(): void
    {
        $this->assertStringContainsString('data.roles.some(', $this->source);
        $this->assertStringContainsString('normalizarNombreRol(nombre)', $this->source);
        $this->assertStringContainsString("rol.nombre", $this->source);
        $this->assertStringContainsString("replace(/[ .-]/g, '_')", $this->source);
        $this->assertStringContainsString("replace(/_+/g, '_')", $this->source);
    }

    public function testRedirigeSolamenteAlPanelTrasValidarRol(): void
    {
        $validation = strpos($this->source, 'if (!tieneRolInternoVigente(json.data))');
        $redirect = strpos($this->source, "window.location.href = buildFrontendUrl('admin.html')");
        $this->assertNotFalse($validation);
        $this->assertNotFalse($redirect);
        $this->assertGreaterThan($validation, $redirect);
        $this->assertStringNotContainsString("buildFrontendUrl('landing.html')", $this->source);
    }

    public function testNoAutorizaPorEmailNiIdNumericoDeRol(): void
    {
        $this->assertStringNotContainsString("json.data?.email ===", $this->source);
        $this->assertStringNotContainsString('rol === 1', $this->source);
        $this->assertStringNotContainsString('facu@zemyna.com', $this->source);
    }

    public function testRolVacioODesconocidoMuestraErrorYDetieneElFlujo(): void
    {
        $this->assertStringContainsString('Tu cuenta no tiene un rol interno vigente reconocido.', $this->source);
        $this->assertMatchesRegularExpression('/if \(!tieneRolInternoVigente\(json\.data\)\) \{[\s\S]*?return;[\s\S]*?\}/', $this->source);
    }
}
