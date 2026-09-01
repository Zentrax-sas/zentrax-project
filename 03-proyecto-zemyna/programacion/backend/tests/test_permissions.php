<?php
/**
 * Test de Permisos por Rol
 * 
 * Valida que los endpoints respeten los permisos asignados a cada rol
 * en una base de datos limpia.
 * 
 * Ejecución:
 *   php -S localhost:8000 &
 *   php tests/test_permissions.php
 */

// =====================================
// Configuración de Test
// =====================================

define('TEST_BASE_URL', 'http://localhost:8000');
define('TEST_PASSWORD', password_hash('zentrax123', PASSWORD_BCRYPT));
define('TEST_TIMEOUT', 5);

$test_users = [
    [
        'email' => 'sistemas@zemyna.com',
        'password' => 'zentrax123',
        'rol' => 'ADMINISTRADOR_TI',
        'sector' => 'TI'
    ],
    [
        'email' => 'facu@zemyna.com',
        'password' => 'zentrax123',
        'rol' => 'ADMINISTRATIVO_OPERATIVO',
        'sector' => 'OPERACIONES'
    ],
    [
        'email' => 'diego@zemyna.com',
        'password' => 'zentrax123',
        'rol' => 'OPERARIO',
        'sector' => 'OPERACIONES'
    ],
    [
        'email' => 'andrea@zemyna.com',
        'password' => 'zentrax123',
        'rol' => 'INSPECTOR',
        'sector' => 'INSPECCION'
    ]
];

// Matriz de permisos esperados por rol
$expected_permissions = [
    'ADMINISTRADOR_TI' => [
        'usuario.consultar',
        'usuario.crear',
        'usuario.modificar',
        'usuario.suspender',
        'usuario.asignar_rol',
        'usuario.asignar_sector',
    ],
    'ADMINISTRATIVO_OPERATIVO' => [
        'cuadrilla.consultar',
        'cuadrilla.crear',
        'cuadrilla.modificar',
        'ruta.consultar',
        'ruta.crear',
        'ruta.modificar',
    ],
    'OPERARIO' => [
        'contenedor.cambiar_estado',
        'incidencia.consultar',
        'incidencia.crear',
        'recorrido.consultar',
    ],
    'INSPECTOR' => [
        'incidencia.consultar',
        'incidencia.crear',
        'incidencia.adjuntar_evidencia',
    ]
];

// Matriz de endpoints a probar (permiso => endpoint)
$test_endpoints = [
    'usuario.consultar' => [
        'method' => 'GET',
        'url' => '/api/usuarios.php',
        'expected_status' => 200
    ],
    'cuadrilla.crear' => [
        'method' => 'POST',
        'url' => '/api/cuadrillas.php',
        'data' => [
            'nombre' => 'Test Cuadrilla',
            'descripcion' => 'Cuadrilla de prueba'
        ],
        'expected_status' => [200, 201, 400] // 400 si faltan datos
    ],
    'ruta.consultar' => [
        'method' => 'GET',
        'url' => '/api/rutas.php',
        'expected_status' => 200
    ],
    'incidencia.consultar' => [
        'method' => 'GET',
        'url' => '/api/incidencias.php',
        'expected_status' => 200
    ]
];

// =====================================
// Funciones Auxiliares
// =====================================

function curl_request($url, $method = 'GET', $data = null, $headers = []) {
    $ch = curl_init();
    
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => TEST_TIMEOUT,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_COOKIEJAR => '/tmp/cookies.txt',
        CURLOPT_COOKIEFILE => '/tmp/cookies.txt'
    ];
    
    if ($data && in_array($method, ['POST', 'PUT'])) {
        $options[CURLOPT_POSTFIELDS] = json_encode($data);
    }
    
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return [
        'status' => (int)$http_code,
        'body' => $response,
        'error' => $error
    ];
}

function login($email, $password) {
    $url = TEST_BASE_URL . '/api/login.php';
    $data = ['email' => $email, 'password' => $password];
    
    $result = curl_request($url, 'POST', $data);
    
    if ($result['status'] === 200) {
        $body = json_decode($result['body'], true);
        return $result['status'] === 200;
    }
    
    return false;
}

function test_endpoint($endpoint_config, $user_email, $user_rol) {
    $url = TEST_BASE_URL . $endpoint_config['url'];
    $method = $endpoint_config['method'] ?? 'GET';
    $data = $endpoint_config['data'] ?? null;
    $expected_status = $endpoint_config['expected_status'] ?? 200;
    
    // Hacer login
    if (!login($user_email, 'zentrax123')) {
        return [
            'passed' => false,
            'reason' => 'Login failed'
        ];
    }
    
    // Hacer request al endpoint
    $result = curl_request($url, $method, $data);
    
    // Validar resultado
    $passed = false;
    if (is_array($expected_status)) {
        $passed = in_array($result['status'], $expected_status);
    } else {
        $passed = $result['status'] === $expected_status;
    }
    
    return [
        'passed' => $passed,
        'status' => $result['status'],
        'expected' => $expected_status,
        'reason' => $result['error'] ?: ''
    ];
}

// =====================================
// Ejecución de Tests
// =====================================

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║         TEST DE PERMISOS POR ROL - ZEMYNA                     ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$total_tests = 0;
$passed_tests = 0;
$failed_tests = [];

foreach ($test_users as $user) {
    echo "─────────────────────────────────────────────────────────────────\n";
    echo "Usuario: {$user['email']} ({$user['rol']})\n";
    echo "─────────────────────────────────────────────────────────────────\n";
    
    $user_perms = $expected_permissions[$user['rol']] ?? [];
    
    // Agrupar endpoints por permiso esperado
    $endpoints_for_role = [];
    foreach ($user_perms as $perm) {
        if (isset($test_endpoints[$perm])) {
            $endpoints_for_role[$perm] = $test_endpoints[$perm];
        }
    }
    
    if (empty($endpoints_for_role)) {
        echo "  ℹ️  No hay endpoints para probar este rol aún.\n\n";
        continue;
    }
    
    foreach ($endpoints_for_role as $perm => $endpoint) {
        $total_tests++;
        
        echo "  Permiso: $perm\n";
        echo "    → {$endpoint['method']} {$endpoint['url']}\n";
        
        // Para este test prototipo, asumimos que si el login funciona
        // y tenemos el permiso, el endpoint debería responder
        // (En producción necesitarías validar contra la DB real)
        
        $result = test_endpoint($endpoint, $user['email'], $user['rol']);
        
        if ($result['passed']) {
            echo "    ✅ OK (Status: {$result['status']})\n";
            $passed_tests++;
        } else {
            echo "    ❌ FALLO\n";
            echo "       Status: {$result['status']} (Expected: {$result['expected']})\n";
            if ($result['reason']) {
                echo "       Error: {$result['reason']}\n";
            }
            $failed_tests[] = [
                'user' => $user['email'],
                'rol' => $user['rol'],
                'permiso' => $perm,
                'endpoint' => $endpoint['url'],
                'result' => $result
            ];
        }
    }
    
    echo "\n";
}

// =====================================
// Reporte Final
// =====================================

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                    RESUMEN DE PRUEBAS                         ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Total de pruebas:  $total_tests\n";
echo "Exitosas:         $passed_tests ✅\n";
echo "Fallidas:         " . count($failed_tests) . " ❌\n";

if (count($failed_tests) > 0) {
    echo "\nDetalle de fallos:\n";
    echo "─────────────────────────────────────────────────────────────────\n";
    
    foreach ($failed_tests as $fail) {
        echo "  • {$fail['user']} ({$fail['rol']})\n";
        echo "    Permiso: {$fail['permiso']}\n";
        echo "    Endpoint: {$fail['endpoint']}\n";
        echo "    Status: {$fail['result']['status']} (Esperado: {$fail['result']['expected']})\n";
        echo "\n";
    }
}

echo "\nℹ️  Nota: Este test es un prototipo. Para validación completa, se recomienda:\n";
echo "   - Ejecutar contra servidor real con Docker\n";
echo "   - Validar respuestas JSON completas\n";
echo "   - Probar negación de acceso (403 Forbidden)\n";
echo "   - Incluir datos de cada tabla en las pruebas\n\n";

exit($passed_tests === $total_tests ? 0 : 1);
?>
