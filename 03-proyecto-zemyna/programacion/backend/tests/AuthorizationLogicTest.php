<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/auth.php';

class AuthorizationLogicTest extends TestCase
{
    private string $originalSessionSavePath;
    private bool $sessionStartedByTest = false;
    private bool $sessionWasActive = false;
    private array $originalSessionData = [];

    protected function setUp(): void
    {
        $this->originalSessionSavePath = session_save_path();
        $this->sessionWasActive = session_status() === PHP_SESSION_ACTIVE;

        if ($this->sessionWasActive) {
            $this->originalSessionData = $_SESSION;
        } else {
            session_save_path(sys_get_temp_dir());
            session_start();
            $this->sessionStartedByTest = true;
        }

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];

        if ($this->sessionStartedByTest) {
            session_unset();
            session_destroy();
            session_save_path($this->originalSessionSavePath);
        } elseif ($this->sessionWasActive) {
            $_SESSION = $this->originalSessionData;
        }

        $this->sessionStartedByTest = false;
        $this->sessionWasActive = false;
        $this->originalSessionData = [];
        parent::tearDown();
    }

    private function setUser(array $roles, array $authorizations = []): void
    {
        $_SESSION['usuario'] = ['roles' => $roles, 'autorizaciones' => $authorizations];
    }

    public function testNormalizeRoleSuperusuario(): void
    {
        $this->assertSame('ADMINISTRADOR_TI', normalizeRoleName('Superusuario'));
        $this->assertSame('ADMINISTRADOR_TI', normalizeRoleName('Administrador'));
        $this->assertSame('OPERARIO', normalizeRoleName('Operario'));
        $this->assertSame('INSPECTOR', normalizeRoleName('Inspector'));
    }

    public function testNormalizeRoleList(): void
    {
        $this->assertSame(['ADMINISTRADOR_TI', 'OPERARIO', 'INSPECTOR'], normalizeRoleList(['Superusuario', 'Administrador', 'Operario', 'Operario', 'Inspector']));
        $this->assertSame([], normalizeRoleList(null));
    }

    public function testNormalizePermissions(): void
    {
        $this->assertSame('usuario.crear', normalizePermissionName('usuario.crear'));
        $this->assertSame('usuario.modificar', normalizePermissionName(' usuario.modificar '));
    }

    public function testAdminAccessAndSectorGuard(): void
    {
        $this->setUser(['ADMINISTRADOR_TI'], [['permiso' => 'usuario.crear', 'sector' => 'TI']]);
        $this->assertTrue(hasEffectivePermission('usuario.crear', ['TI']));
        $this->assertTrue(hasEffectivePermission('usuario.crear', ['LOGISTICA']));
        $this->assertTrue(hasEffectivePermission('contenedor.consultar', ['LOGISTICA']));
    }

    public function testResponsableSectorialWithMatchingSector(): void
    {
        $this->setUser(['RESPONSABLE_SECTORIAL'], [
            ['permiso' => 'contenedor.consultar', 'sector' => 'PUNTOS_Y_DESTINOS'],
            ['permiso' => 'vehiculo.consultar', 'sector' => 'LOGISTICA'],
        ]);
        $this->assertTrue(hasEffectivePermission('contenedor.consultar', ['PUNTOS_Y_DESTINOS']));
        $this->assertTrue(hasEffectivePermission('vehiculo.consultar', ['LOGISTICA']));
        $this->assertFalse(hasEffectivePermission('contenedor.consultar', ['LOGISTICA']));
    }

    public function testOperarioWithValidAndInvalidSectors(): void
    {
        $this->setUser(['OPERARIO'], [
            ['permiso' => 'contenedor.cambiar_estado', 'sector' => 'OPERACIONES'],
            ['permiso' => 'incidencia.adjuntar_evidencia', 'sector' => 'INSPECCION'],
        ]);
        $this->assertTrue(hasEffectivePermission('contenedor.cambiar_estado', ['OPERACIONES']));
        $this->assertTrue(hasEffectivePermission('incidencia.adjuntar_evidencia', ['INSPECCION']));
        $this->assertFalse(hasEffectivePermission('contenedor.cambiar_estado', ['INSPECCION']));
    }

    public function testInspectorReadOnlyGuards(): void
    {
        $this->setUser(['INSPECTOR'], [['permiso' => 'incidencia.consultar', 'sector' => 'INSPECCION']]);
        $this->assertTrue(hasEffectivePermission('incidencia.consultar', ['INSPECCION']));
        $this->assertFalse(hasEffectivePermission('usuario.modificar', ['TI']));
        $this->assertFalse(hasEffectivePermission('contenedor.modificar', ['PUNTOS_Y_DESTINOS']));
    }

    public function testLegacyCompatibilityMapsOldRoles(): void
    {
        $this->assertSame('ADMINISTRADOR_TI', normalizeRoleName('SUPERUSUARIO'));
        $this->assertSame('ADMINISTRADOR_TI', normalizeRoleName('ADMINISTRADOR'));
        $this->assertSame('RESPONSABLE_SECTORIAL', normalizeRoleName('RESPONSABLE'));
        $this->assertSame('ADMINISTRADOR_TI', normalizeRoleName('TI'));
    }

    public function testGeographicAndGeneralSectorNormalization(): void
    {
        $this->assertSame('LOGISTICA', normalizeRoleName('logistica'));
        $this->assertSame('OPERACIONES', normalizeRoleName(' operaciones '));
        $this->assertSame('', normalizeRoleName('   '));
    }

    public function testPermissionChecksOnArrayOfRoles(): void
    {
        $this->setUser(['OPERARIO', 'INSPECTOR'], [['permiso' => 'incidencia.crear', 'sector' => 'INSPECCION']]);
        $this->assertTrue(hasEffectivePermission('incidencia.crear', ['INSPECCION']));
        $this->assertFalse(hasEffectivePermission('usuario.crear', ['TI']));
    }

    public function testDuplicateEntriesAndEmptyValuesGuard(): void
    {
        $this->setUser(['ADMINISTRADOR_TI', 'ADMINISTRADOR_TI'], [
            ['permiso' => 'usuario.consultar', 'sector' => 'TI'],
            ['permiso' => 'usuario.consultar', 'sector' => 'TI'],
        ]);
        $this->assertTrue(hasEffectivePermission('usuario.consultar', ['TI']));
        $this->assertSame(['OPERARIO'], normalizeRoleList(['', '   ', 'OPERARIO']));
    }

    public function testNegativeGrantScenarios(): void
    {
        $this->setUser(['OPERARIO'], [['permiso' => 'incidencia.crear', 'sector' => 'OPERACIONES']]);
        $this->assertFalse(hasEffectivePermission('usuario.modificar', ['TI']));
        $this->assertFalse(hasEffectivePermission('vehiculo.modificar', ['LOGISTICA']));
        $this->assertFalse(hasEffectivePermission('contenedor.baja', ['PUNTOS_Y_DESTINOS']));
    }

    public function testAdminCompatibilityWithLegacyName(): void
    {
        $this->setUser(['Superusuario']);
        $this->assertTrue(hasEffectivePermission('usuario.crear', ['TI']));
        $this->assertTrue(hasEffectivePermission('usuario.crear', ['LOGISTICA']));
    }

    public function testEmptySessionDeniesAccess(): void
    {
        $this->assertFalse(hasEffectivePermission('usuario.consultar', ['TI']));
    }

    public function testSpecialRolesRemainUppercaseSafe(): void
    {
        $this->assertSame('ADMINISTRATIVO_OPERATIVO', normalizeRoleName('administRativo_operativo'));
        $this->assertSame('RESPONSABLE_SECTORIAL', normalizeRoleName('responsable_sectorial'));
        $this->assertSame('PUNTOS_Y_DESTINOS', normalizeRoleName(' pUnToS_y_dEstInOs '));
    }

    public function testPermissionTypeNormalizationWithExactNames(): void
    {
        $this->assertSame('contenedor.cambiar_estado', normalizePermissionName('contenedor.cambiar_estado'));
        $this->assertSame('vehiculo.asignar', normalizePermissionName('  vehiculo.asignar  '));
        $this->assertSame('', normalizePermissionName(''));
    }

    public function testSecurityMatrixValidScenarios(): void
    {
        $this->setUser(['RESPONSABLE_SECTORIAL'], [
            ['permiso' => 'lugar.crear', 'sector' => 'PUNTOS_Y_DESTINOS'],
            ['permiso' => 'vehiculo.asignar', 'sector' => 'LOGISTICA'],
        ]);
        $this->assertTrue(hasEffectivePermission('lugar.crear', ['PUNTOS_Y_DESTINOS']));
        $this->assertTrue(hasEffectivePermission('vehiculo.asignar', ['LOGISTICA']));
        $this->assertFalse(hasEffectivePermission('lugar.crear', ['LOGISTICA']));
    }

    public function testRoleListGeneralization(): void
    {
        $this->assertSame(['RESPONSABLE_SECTORIAL', 'ADMINISTRADOR_TI', 'INSPECTOR', 'OPERARIO'], normalizeRoleList(['RESPONSABLE', 'admin', 'Inspector', 'OPERARIO', '']));
    }

    public function testNegativeEmptyAuthData(): void
    {
        $this->setUser([]);
        $this->assertFalse(hasEffectivePermission('usuario.consultar', ['TI']));
        $this->assertFalse(hasEffectivePermission('contenedor.consultar', null));
    }

    public function testSectorNamesWithDifferentEncodingsAreNormalized(): void
    {
        $this->assertSame('PUNTOS_Y_DESTINOS', normalizeRoleName('puntos_y_destinos'));
        $this->assertSame('MANTENIMIENTO', normalizeRoleName('MANTENIMIENTO'));
        $this->assertSame('INSPECCION', normalizeRoleName('inspeccion'));
    }

    public function testCrossSectorCheckForInspector(): void
    {
        $this->setUser(['INSPECTOR'], [['permiso' => 'incidencia.consultar', 'sector' => 'INSPECCION']]);
        $this->assertTrue(hasEffectivePermission('incidencia.consultar', ['INSPECCION']));
        $this->assertFalse(hasEffectivePermission('incidencia.consultar', ['MANTENIMIENTO']));
        $this->assertFalse(hasEffectivePermission('maquinaria.consultar', ['MANTENIMIENTO']));
    }

    public function testPermissionGrantWhenSectorListIsNull(): void
    {
        $this->setUser(['ADMINISTRADOR_TI'], [['permiso' => 'usuario.consultar', 'sector' => 'TI']]);
        $this->assertTrue(hasEffectivePermission('usuario.consultar', null));
    }

    public function testPermissionArrayValuesAreNotMutated(): void
    {
        $original = ['usuario.crear', 'contenedor.consultar'];
        $copy = $original;
        $this->setUser(['ADMINISTRADOR_TI'], [
            ['permiso' => 'usuario.crear', 'sector' => 'TI'],
            ['permiso' => 'contenedor.consultar', 'sector' => 'PUNTOS_Y_DESTINOS'],
        ]);
        $this->assertSame($original, $copy);
        $this->assertTrue(hasEffectivePermission('usuario.crear', ['TI']));
    }

    public function testNormalizationOfSeveralNamesWithExtraSpaces(): void
    {
        $this->assertSame('RESPONSABLE_SECTORIAL', normalizeRoleName('   responsable_sectorial   '));
        $this->assertSame('ADMINISTRADOR_TI', normalizeRoleName('  admin  '));
        $this->assertSame('LOGISTICA', normalizeRoleName(' logistica '));
    }

    public function testUniqueReductionForAdminAndLegacyAliases(): void
    {
        $this->assertSame(['ADMINISTRADOR_TI'], normalizeRoleList(['ADMINISTRADOR_TI', 'Superusuario', 'Administrador', 'TI']));
    }

    public function testLastNegativeCheckForNoSectorMatch(): void
    {
        $this->setUser(['RESPONSABLE_SECTORIAL'], [['permiso' => 'maquinaria.consultar', 'sector' => 'MANTENIMIENTO']]);
        $this->assertFalse(hasEffectivePermission('maquinaria.consultar', ['LOGISTICA']));
        $this->assertTrue(hasEffectivePermission('maquinaria.consultar', ['MANTENIMIENTO']));
    }
}
