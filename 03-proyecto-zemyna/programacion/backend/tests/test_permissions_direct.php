<?php
/**
 * Test de Permisos Directos - ZEMYNA
 * 
 * Prueba la lógica de permisos contra la base de datos limpia
 * sin necesidad de un servidor HTTP.
 * 
 * Ejecución:
 *   cd 03-proyecto-zemyna/programacion/backend
 *   php tests/test_permissions_direct.php
 */

require_once 'config/bootstrap.php';
require_once 'helpers/auth.php';
require_once 'models/Usuario.php';
require_once 'models/UsuarioRol.php';

// Instanciar conexión a la base de datos
$db = new Database();
$pdo = $db->getConnection();

if (!$pdo) {
    die("❌ No se pudo conectar a la base de datos\n");
}

// =====================================
// Datos de Test
// =====================================

$test_users = [
    'sistemas@zemyna.com' => ['password' => 'zentrax123', 'rol' => 'ADMINISTRADOR_TI', 'sector' => 'TI'],
    'facu@zemyna.com' => ['password' => 'zentrax123', 'rol' => 'ADMINISTRATIVO_OPERATIVO', 'sector' => 'OPERACIONES'],
    'diego@zemyna.com' => ['password' => 'zentrax123', 'rol' => 'OPERARIO', 'sector' => 'OPERACIONES'],
    'andrea@zemyna.com' => ['password' => 'zentrax123', 'rol' => 'INSPECTOR', 'sector' => 'INSPECCION']
];

// Matriz de permisos esperados
$expected_permissions = [
    'ADMINISTRADOR_TI' => [
        'usuario.consultar', 'usuario.crear', 'usuario.modificar', 'usuario.suspender',
        'usuario.asignar_rol', 'usuario.asignar_sector',
        'contenedor.baja', 'contenedor.cambiar_estado', 'contenedor.consultar', 'contenedor.crear', 'contenedor.modificar',
        'lugar.baja', 'lugar.cambiar_estado', 'lugar.consultar', 'lugar.crear', 'lugar.modificar',
        'maquinaria.baja', 'maquinaria.cambiar_estado', 'maquinaria.consultar', 'maquinaria.crear', 'maquinaria.modificar',
        'vehiculo.baja', 'vehiculo.cambiar_estado', 'vehiculo.consultar', 'vehiculo.crear', 'vehiculo.modificar', 'vehiculo.asignar',
        'incidencia.consultar', 'incidencia.crear', 'incidencia.modificar', 'incidencia.adjuntar_evidencia',
    ],
    'ADMINISTRATIVO_OPERATIVO' => [
        'contenedor.consultar',
        'cuadrilla.consultar', 'cuadrilla.crear', 'cuadrilla.modificar',
        'incidencia.consultar', 'incidencia.crear', 'incidencia.modificar',
        'lugar.consultar',
        'mantenimiento.consultar', 'mantenimiento.crear', 'mantenimiento.modificar',
        'recorrido.consultar', 'recorrido.crear', 'recorrido.modificar',
        'ruta.consultar', 'ruta.crear', 'ruta.modificar',
        'vehiculo.asignar', 'vehiculo.consultar'
    ],
    'OPERARIO' => [
        'contenedor.cambiar_estado', 'contenedor.consultar',
        'cuadrilla.consultar',
        'incidencia.adjuntar_evidencia', 'incidencia.consultar', 'incidencia.crear',
        'mantenimiento.consultar',
        'maquinaria.consultar',
        'recorrido.consultar',
        'ruta.consultar',
        'vehiculo.consultar'
    ],
    'INSPECTOR' => [
        'contenedor.consultar',
        'cuadrilla.consultar',
        'incidencia.adjuntar_evidencia', 'incidencia.consultar', 'incidencia.crear',
        'mantenimiento.consultar',
        'maquinaria.consultar',
        'recorrido.consultar',
        'ruta.consultar',
        'vehiculo.consultar'
    ]
];

// Tests de acceso denegado
$denied_tests = [
    'OPERARIO' => ['usuario.crear', 'usuario.suspender', 'ruta.crear', 'vehiculo.crear'],
    'INSPECTOR' => ['usuario.crear', 'cuadrilla.crear', 'ruta.crear'],
    'ADMINISTRATIVO_OPERATIVO' => ['usuario.crear', 'usuario.modificar', 'vehiculo.crear']
];

// =====================================
// Funciones Auxiliares
// =====================================

function simulate_session($user_email, $usuario_model) {
    global $pdo;
    
    // Obtener datos del usuario
    $stmt = $pdo->prepare("SELECT id_usuario, nombre, apellido, id_centro, activo FROM usuario WHERE email = ?");
    $stmt->execute([$user_email]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        return false;
    }
    
    $id_usuario = $usuario['id_usuario'];
    
    // Obtener roles
    $roles = $usuario_model->getRolesVigentes($id_usuario);
    $nombresRoles = array_map(function ($rol) {
        return normalizeRoleName($rol['nombre'] ?? $rol);
    }, $roles);
    
    // Obtener permisos
    $permisos = array_map('trim', $usuario_model->getPermisosVigentes($id_usuario));
    
    // Obtener autorizaciones (rol + sector + permiso)
    $autorizaciones = array_map(function ($autorizacion) {
        return [
            'rol' => normalizeRoleName($autorizacion['rol'] ?? ''),
            'sector' => strtoupper(trim((string) ($autorizacion['sector'] ?? ''))),
            'permiso' => trim((string) ($autorizacion['permiso'] ?? '')),
        ];
    }, $usuario_model->getAutorizacionesVigentes($id_usuario));
    
    // Simular sesión PHP
    $_SESSION = [
        'usuario' => [
            'id_usuario' => (int)$id_usuario,
            'nombre' => $usuario['nombre'],
            'apellido' => $usuario['apellido'],
            'email' => $user_email,
            'id_centro' => (int)$usuario['id_centro'],
            'activo' => $usuario['activo'],
            'roles' => $nombresRoles,
            'asignaciones' => $roles,
            'permisos' => $permisos,
            'autorizaciones' => $autorizaciones
        ]
    ];
    
    return true;
}

function check_permission($permiso_nombre, $usuario_id) {
    global $pdo;
    
    // Usar la función de auth (que usa $_SESSION)
    return hasEffectivePermission($permiso_nombre, null);
}

// =====================================
// Inicio de Tests
// =====================================

if (php_sapi_name() !== 'cli') {
    die("Este script debe ejecutarse desde línea de comandos\n");
}

echo "╔═══════════════════════════════════════════════════════════════════╗\n";
echo "║     TEST DE PERMISOS DIRECTOS - ZEMYNA (Base Limpia)            ║\n";
echo "╚═══════════════════════════════════════════════════════════════════╝\n\n";

$total_tests = 0;
$passed_tests = 0;
$failed_tests = [];

try {
    $usuario_model = new Usuario($pdo);
    
    foreach ($test_users as $email => $user_data) {
        echo "───────────────────────────────────────────────────────────────────\n";
        echo "Usuario: $email ({$user_data['rol']})\n";
        echo "Sector:  {$user_data['sector']}\n";
        echo "───────────────────────────────────────────────────────────────────\n";
        
        // Simular sesión
        if (!simulate_session($email, $usuario_model)) {
            echo "  ❌ No se pudo cargar la sesión del usuario\n\n";
            continue;
        }
        
        $id_usuario = $_SESSION['usuario']['id_usuario'];
        $expected_perms = $expected_permissions[$user_data['rol']] ?? [];
        
        // Test 1: Permisos que DEBE tener
        echo "  ✓ Permisos esperados:\n";
        foreach ($expected_perms as $perm) {
            $total_tests++;
            
            $has_perm = check_permission($perm, $id_usuario);
            
            if ($has_perm) {
                echo "    ✅ $perm\n";
                $passed_tests++;
            } else {
                echo "    ❌ $perm (NO DEBERÍA FALTAR)\n";
                $failed_tests[] = [
                    'email' => $email,
                    'rol' => $user_data['rol'],
                    'permiso' => $perm,
                    'tipo' => 'FALTA_PERMISO_ESPERADO'
                ];
            }
        }
        
        // Test 2: Permisos que NO DEBE tener
        echo "\n  ✗ Permisos denegados:\n";
        $denied_perms = $denied_tests[$user_data['rol']] ?? [];
        foreach ($denied_perms as $perm) {
            $total_tests++;
            
            $has_perm = check_permission($perm, $id_usuario);
            
            if (!$has_perm) {
                echo "    ✅ $perm (correctamente denegado)\n";
                $passed_tests++;
            } else {
                echo "    ❌ $perm (DEBERÍA ESTAR DENEGADO)\n";
                $failed_tests[] = [
                    'email' => $email,
                    'rol' => $user_data['rol'],
                    'permiso' => $perm,
                    'tipo' => 'PERMISO_NO_DEBERIA_EXISTIR'
                ];
            }
        }
        
        // Test 3: Validar sector correcto
        echo "\n  ⊗ Sector y autorizaciones:\n";
        $total_tests++;
        
        $autorizaciones = $_SESSION['usuario']['autorizaciones'] ?? [];
        $tiene_sector = false;
        foreach ($autorizaciones as $auth) {
            if ($auth['sector'] === $user_data['sector']) {
                $tiene_sector = true;
                break;
            }
        }
        
        if ($tiene_sector) {
            echo "    ✅ Sector {$user_data['sector']} asignado\n";
            $passed_tests++;
        } else {
            echo "    ❌ Sector {$user_data['sector']} NO asignado\n";
            $failed_tests[] = [
                'email' => $email,
                'rol' => $user_data['rol'],
                'permiso' => "sector:{$user_data['sector']}",
                'tipo' => 'SECTOR_NO_ASIGNADO'
            ];
        }
        
        echo "\n";
    }
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n\n";
    exit(1);
}

// =====================================
// Reporte Final
// =====================================

echo "╔═══════════════════════════════════════════════════════════════════╗\n";
echo "║                    RESUMEN DE PRUEBAS                           ║\n";
echo "╚═══════════════════════════════════════════════════════════════════╝\n\n";

$success_rate = ($total_tests > 0) ? round(($passed_tests / $total_tests) * 100, 1) : 0;

echo "Total de pruebas:   $total_tests\n";
echo "Exitosas:          $passed_tests ✅\n";
echo "Fallidas:          " . count($failed_tests) . " ❌\n";
echo "Tasa de éxito:      $success_rate%\n\n";

if (count($failed_tests) > 0) {
    echo "DETALLE DE FALLOS:\n";
    echo "─────────────────────────────────────────────────────────────────\n\n";
    
    foreach ($failed_tests as $fail) {
        $tipo = $fail['tipo'];
        $tipo_str = match($tipo) {
            'FALTA_PERMISO_ESPERADO' => '❌ PERMISO FALTANTE',
            'PERMISO_NO_DEBERIA_EXISTIR' => '⚠️ PERMISO EXCESIVO',
            'SECTOR_NO_ASIGNADO' => '⚠️ SECTOR NO ASIGNADO',
            default => '⚠️ ERROR DESCONOCIDO'
        };
        
        echo "$tipo_str\n";
        echo "  Usuario:  {$fail['email']} ({$fail['rol']})\n";
        echo "  Permiso:  {$fail['permiso']}\n";
        echo "\n";
    }
}

echo "──────────────────────────────────────────────────────────────────\n";
echo "✓ Test completado.\n";
echo "\nSiguientes pasos:\n";
echo "  1. Revisar fallos e identificar inconsistencias\n";
echo "  2. Ajustar rol_permiso si es necesario\n";
echo "  3. Ejecutar test nuevamente para validar correcciones\n";
echo "  4. Proceder con tests de endpoints HTTP\n\n";

exit(count($failed_tests) === 0 ? 0 : 1);
?>
