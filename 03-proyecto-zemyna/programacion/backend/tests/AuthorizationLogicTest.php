<?php
require_once __DIR__ . '/../helpers/auth.php';

$passed = 0;
$failed = 0;

function assertCondition(bool $condition, string $message): void {
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "PASS: $message\n";
        return;
    }

    $failed++;
    echo "FAIL: $message\n";
}

function runCase(string $name, callable $callback): void {
    try {
        $callback();
        echo "CASE: $name\n";
    } catch (Throwable $e) {
        global $failed;
        $failed++;
        echo "ERROR: $name => " . $e->getMessage() . "\n";
    }
}

runCase('normalize role Superusuario', function () {
    assertCondition(normalizeRoleName('Superusuario') === 'ADMINISTRADOR_TI', 'Superusuario normaliza a ADMINISTRADOR_TI');
    assertCondition(normalizeRoleName('Administrador') === 'ADMINISTRADOR_TI', 'Administrador normaliza a ADMINISTRADOR_TI');
    assertCondition(normalizeRoleName('Operario') === 'OPERARIO', 'Operario conserva su valor');
    assertCondition(normalizeRoleName('Inspector') === 'INSPECTOR', 'Inspector conserva su valor');
});

runCase('normalize role list', function () {
    $roles = normalizeRoleList(['Superusuario', 'Administrador', 'Operario', 'Operario', 'Inspector']);
    assertCondition($roles === ['ADMINISTRADOR_TI', 'OPERARIO', 'INSPECTOR'], 'La lista normalizada elimina duplicados y unifica roles legacy');
    assertCondition(normalizeRoleList(null) === [], 'Listado nulo se normaliza a arreglo vacío');
});

runCase('normalize permissions', function () {
    assertCondition(normalizePermissionName('usuario.crear') === 'usuario.crear', 'Permiso simple se conserva');
    assertCondition(normalizePermissionName(' usuario.modificar ') === 'usuario.modificar', 'Permiso con espacios se limpia');
});

runCase('admin access and sector guard', function () {
    $_SESSION = [];
    $_SESSION['usuario'] = [
        'roles' => ['ADMINISTRADOR_TI'],
        'autorizaciones' => [
            ['permiso' => 'usuario.crear', 'sector' => 'TI'],
        ],
    ];

    assertCondition(hasEffectivePermission('usuario.crear', ['TI']) === true, 'ADMINISTRADOR_TI tiene permiso en TI');
    assertCondition(hasEffectivePermission('usuario.crear', ['LOGISTICA']) === true, 'ADMINISTRADOR_TI tiene permiso sin restringir sector');
    assertCondition(hasEffectivePermission('contenedor.consultar', ['LOGISTICA']) === true, 'ADMINISTRADOR_TI conserva acceso global al sistema');
});

runCase('responsable sectorial with matching sector', function () {
    $_SESSION = [];
    $_SESSION['usuario'] = [
        'roles' => ['RESPONSABLE_SECTORIAL'],
        'autorizaciones' => [
            ['permiso' => 'contenedor.consultar', 'sector' => 'PUNTOS_Y_DESTINOS'],
            ['permiso' => 'vehiculo.consultar', 'sector' => 'LOGISTICA'],
        ],
    ];

    assertCondition(hasEffectivePermission('contenedor.consultar', ['PUNTOS_Y_DESTINOS']) === true, 'Responsable sectorial accede a su sector');
    assertCondition(hasEffectivePermission('vehiculo.consultar', ['LOGISTICA']) === true, 'Responsable sectorial accede a un sector distinto asignado');
    assertCondition(hasEffectivePermission('contenedor.consultar', ['LOGISTICA']) === false, 'Responsable sectorial no accede a otro sector sin permisos');
});

runCase('operario with valid and invalid sectors', function () {
    $_SESSION = [];
    $_SESSION['usuario'] = [
        'roles' => ['OPERARIO'],
        'autorizaciones' => [
            ['permiso' => 'contenedor.cambiar_estado', 'sector' => 'OPERACIONES'],
            ['permiso' => 'incidencia.adjuntar_evidencia', 'sector' => 'INSPECCION'],
        ],
    ];

    assertCondition(hasEffectivePermission('contenedor.cambiar_estado', ['OPERACIONES']) === true, 'Operario cambia estado en su sector');
    assertCondition(hasEffectivePermission('incidencia.adjuntar_evidencia', ['INSPECCION']) === true, 'Operario adjunta evidencia en inspección');
    assertCondition(hasEffectivePermission('contenedor.cambiar_estado', ['INSPECCION']) === false, 'Operario no cambia estado fuera de su sector');
});

runCase('inspector read-only guards', function () {
    $_SESSION = [];
    $_SESSION['usuario'] = [
        'roles' => ['INSPECTOR'],
        'autorizaciones' => [
            ['permiso' => 'incidencia.consultar', 'sector' => 'INSPECCION'],
        ],
    ];

    assertCondition(hasEffectivePermission('incidencia.consultar', ['INSPECCION']) === true, 'Inspector consulta incidencias en su sector');
    assertCondition(hasEffectivePermission('usuario.modificar', ['TI']) === false, 'Inspector no puede modificar usuarios');
    assertCondition(hasEffectivePermission('contenedor.modificar', ['PUNTOS_Y_DESTINOS']) === false, 'Inspector no modifica recursos maestros');
});

runCase('legacy compatibility maps old roles', function () {
    assertCondition(normalizeRoleName('SUPERUSUARIO') === 'ADMINISTRADOR_TI', 'SUPERUSUARIO se mapea a ADMINISTRADOR_TI');
    assertCondition(normalizeRoleName('ADMINISTRADOR') === 'ADMINISTRADOR_TI', 'ADMINISTRADOR se mapea a ADMINISTRADOR_TI');
    assertCondition(normalizeRoleName('RESPONSABLE') === 'RESPONSABLE_SECTORIAL', 'RESPONSABLE se mapea a RESPONSABLE_SECTORIAL');
    assertCondition(normalizeRoleName('TI') === 'ADMINISTRADOR_TI', 'TI se mapea a ADMINISTRADOR_TI');
});

runCase('geographic and general sector normalization', function () {
    assertCondition(normalizeRoleName('logistica') === 'LOGISTICA', 'Sector con lowercase se normaliza');
    assertCondition(normalizeRoleName(' operaciones ') === 'OPERACIONES', 'Sector con espacios se normaliza');
    assertCondition(normalizeRoleName('   ') === '', 'Espacios vacíos se convierten en vacío');
});

runCase('permission checks on array of roles', function () {
    $_SESSION = [];
    $_SESSION['usuario'] = [
        'roles' => ['OPERARIO', 'INSPECTOR'],
        'autorizaciones' => [
            ['permiso' => 'incidencia.crear', 'sector' => 'INSPECCION'],
        ],
    ];

    assertCondition(hasEffectivePermission('incidencia.crear', ['INSPECCION']) === true, 'Usuario con roles múltiples mantiene permiso válido');
    assertCondition(hasEffectivePermission('usuario.crear', ['TI']) === false, 'Usuario con roles no admin no obtiene permisos de TI sin asignación');
});

runCase('duplicate entries and empty values guard', function () {
    $_SESSION = [];
    $_SESSION['usuario'] = [
        'roles' => ['ADMINISTRADOR_TI', 'ADMINISTRADOR_TI'],
        'autorizaciones' => [
            ['permiso' => 'usuario.consultar', 'sector' => 'TI'],
            ['permiso' => 'usuario.consultar', 'sector' => 'TI'],
        ],
    ];

    assertCondition(hasEffectivePermission('usuario.consultar', ['TI']) === true, 'Permisos duplicados no rompen la validación');
    assertCondition(normalizeRoleList(['', '   ', 'OPERARIO']) === ['OPERARIO'], 'Valores vacíos se ignoraron');
});

runCase('negative grant scenarios', function () {
    $_SESSION = [];
    $_SESSION['usuario'] = [
        'roles' => ['OPERARIO'],
        'autorizaciones' => [
            ['permiso' => 'incidencia.crear', 'sector' => 'OPERACIONES'],
        ],
    ];

    assertCondition(hasEffectivePermission('usuario.modificar', ['TI']) === false, 'Operario no puede modificar usuarios');
    assertCondition(hasEffectivePermission('vehiculo.modificar', ['LOGISTICA']) === false, 'Operario no puede modificar vehículos');
    assertCondition(hasEffectivePermission('contenedor.baja', ['PUNTOS_Y_DESTINOS']) === false, 'Operario no puede dar de baja contenedores');
});

runCase('admin compatibility with legacy name', function () {
    $_SESSION = [];
    $_SESSION['usuario'] = [
        'roles' => ['Superusuario'],
        'autorizaciones' => [],
    ];

    assertCondition(hasEffectivePermission('usuario.crear', ['TI']) === true, 'Legacy Superusuario mantiene acceso TI');
    assertCondition(hasEffectivePermission('usuario.crear', ['LOGISTICA']) === true, 'Legacy Superusuario conserva permisos generales');
});

runCase('empty session denies access', function () {
    $_SESSION = [];
    assertCondition(hasEffectivePermission('usuario.consultar', ['TI']) === false, 'Sesión vacía niega permiso');
});

runCase('special roles remain uppercase-safe', function () {
    assertCondition(normalizeRoleName('administRativo_operativo') === 'ADMINISTRATIVO_OPERATIVO', 'Nombre con mayúsculas mezcladas normaliza');
    assertCondition(normalizeRoleName('responsable_sectorial') === 'RESPONSABLE_SECTORIAL', 'Nombre con underscore normaliza');
    assertCondition(normalizeRoleName(' pUnToS_y_dEstInOs ') === 'PUNTOS_Y_DESTINOS', 'Nombre con espacios y case mix normaliza');
});

runCase('permission-type normalization with exact names', function () {
    assertCondition(normalizePermissionName('contenedor.cambiar_estado') === 'contenedor.cambiar_estado', 'Permiso exacto se conserva');
    assertCondition(normalizePermissionName('  vehiculo.asignar  ') === 'vehiculo.asignar', 'Permiso con espacios se normaliza');
    assertCondition(normalizePermissionName('') === '', 'Permiso vacío se normaliza a vacío');
});

runCase('security matrix valid scenarios', function () {
    $_SESSION = [];
    $_SESSION['usuario'] = [
        'roles' => ['RESPONSABLE_SECTORIAL'],
        'autorizaciones' => [
            ['permiso' => 'lugar.crear', 'sector' => 'PUNTOS_Y_DESTINOS'],
            ['permiso' => 'vehiculo.asignar', 'sector' => 'LOGISTICA'],
        ],
    ];

    assertCondition(hasEffectivePermission('lugar.crear', ['PUNTOS_Y_DESTINOS']) === true, 'Responsable puede crear lugares en su sector');
    assertCondition(hasEffectivePermission('vehiculo.asignar', ['LOGISTICA']) === true, 'Responsable puede asignar vehículos en su sector');
    assertCondition(hasEffectivePermission('lugar.crear', ['LOGISTICA']) === false, 'Responsable no crea lugar en sector ajeno');
});

runCase('role list generalization', function () {
    $roles = normalizeRoleList(['RESPONSABLE', 'admin', 'Inspector', 'OPERARIO', '']);
    assertCondition($roles === ['RESPONSABLE_SECTORIAL', 'ADMINISTRADOR_TI', 'INSPECTOR', 'OPERARIO'], 'Lista generaliza roles legacy y nuevos');
});

runCase('negative empty auth data', function () {
    $_SESSION = [];
    $_SESSION['usuario'] = ['roles' => [], 'autorizaciones' => []];
    assertCondition(hasEffectivePermission('usuario.consultar', ['TI']) === false, 'Sin roles no hay acceso');
    assertCondition(hasEffectivePermission('contenedor.consultar', null) === false, 'Sin autorizaciones no hay acceso sin sector');
});

runCase('sector names with different encodings are normalized', function () {
    assertCondition(normalizeRoleName('puntos_y_destinos') === 'PUNTOS_Y_DESTINOS', 'Sector con guion bajo se normaliza');
    assertCondition(normalizeRoleName('MANTENIMIENTO') === 'MANTENIMIENTO', 'Sector ya normalizado se mantiene');
    assertCondition(normalizeRoleName('inspeccion') === 'INSPECCION', 'Sector lowercase se normaliza');
});

runCase('cross-sector check for inspector', function () {
    $_SESSION = [];
    $_SESSION['usuario'] = [
        'roles' => ['INSPECTOR'],
        'autorizaciones' => [
            ['permiso' => 'incidencia.consultar', 'sector' => 'INSPECCION'],
        ],
    ];

    assertCondition(hasEffectivePermission('incidencia.consultar', ['INSPECCION']) === true, 'Inspector puede consultar en inspección');
    assertCondition(hasEffectivePermission('incidencia.consultar', ['MANTENIMIENTO']) === false, 'Inspector no consulta fuera de inspección');
    assertCondition(hasEffectivePermission('maquinaria.consultar', ['MANTENIMIENTO']) === false, 'Inspector no consulta maquinaria fuera de su sector');
});

runCase('permission grant when sector list is null', function () {
    $_SESSION = [];
    $_SESSION['usuario'] = [
        'roles' => ['ADMINISTRADOR_TI'],
        'autorizaciones' => [
            ['permiso' => 'usuario.consultar', 'sector' => 'TI'],
        ],
    ];

    assertCondition(hasEffectivePermission('usuario.consultar', null) === true, 'Si no hay sector, el permiso se evalúa por autorización efectiva');
});

runCase('permission array values are not mutated', function () {
    $original = ['usuario.crear', 'contenedor.consultar'];
    $copy = $original;
    $_SESSION = [];
    $_SESSION['usuario'] = [
        'roles' => ['ADMINISTRADOR_TI'],
        'autorizaciones' => [
            ['permiso' => 'usuario.crear', 'sector' => 'TI'],
            ['permiso' => 'contenedor.consultar', 'sector' => 'PUNTOS_Y_DESTINOS'],
        ],
    ];

    assertCondition($copy === $original, 'Arreglos originales no se mutan');
    assertCondition(hasEffectivePermission('usuario.crear', ['TI']) === true, 'Permiso de admin se mantiene estable');
});

runCase('normalization of several names with extra spaces', function () {
    assertCondition(normalizeRoleName('   responsable_sectorial   ') === 'RESPONSABLE_SECTORIAL', 'Nombre con espacios se limpia');
    assertCondition(normalizeRoleName('  admin  ') === 'ADMINISTRADOR_TI', 'Rol admin se normaliza');
    assertCondition(normalizeRoleName(' logistica ') === 'LOGISTICA', 'Nombre con espacios y lowercase se corrige');
});

runCase('unique reduction for admin and legacy aliases', function () {
    $roles = normalizeRoleList(['ADMINISTRADOR_TI', 'Superusuario', 'Administrador', 'TI']);
    assertCondition($roles === ['ADMINISTRADOR_TI'], 'Alias del administrador se reducen a una sola entrada');
});

runCase('last negative check for no sector match', function () {
    $_SESSION = [];
    $_SESSION['usuario'] = [
        'roles' => ['RESPONSABLE_SECTORIAL'],
        'autorizaciones' => [
            ['permiso' => 'maquinaria.consultar', 'sector' => 'MANTENIMIENTO'],
        ],
    ];

    assertCondition(hasEffectivePermission('maquinaria.consultar', ['LOGISTICA']) === false, 'Responsable no tiene acceso a maquinaria en otro sector');
    assertCondition(hasEffectivePermission('maquinaria.consultar', ['MANTENIMIENTO']) === true, 'Responsable sí tiene acceso en su sector');
});

$summary = "Total tests: " . ($passed + $failed) . "; Passed: $passed; Failed: $failed";
if ($failed > 0) {
    echo "\nSUMMARY: $summary\n";
    exit(1);
}

echo "\nSUMMARY: $summary\n";
