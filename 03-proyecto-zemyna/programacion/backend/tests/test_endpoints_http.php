<?php
/**
 * Test simple de endpoints HTTP con permisos
 */

$base_url = 'http://localhost/zentrax-project/03-proyecto-zemyna/programacion/backend';
$cookie_file = sys_get_temp_dir() . '/test_cookies_' . uniqid() . '.txt';

// Usuarios y endpoints a probar
$users = [
    ['email' => 'sistemas@zemyna.com', 'pass' => 'zentrax123', 'rol' => 'ADMINISTRADOR_TI'],
    ['email' => 'facu@zemyna.com', 'pass' => 'zentrax123', 'rol' => 'ADMINISTRATIVO_OPERATIVO'],
    ['email' => 'diego@zemyna.com', 'pass' => 'zentrax123', 'rol' => 'OPERARIO'],
    ['email' => 'andrea@zemyna.com', 'pass' => 'zentrax123', 'rol' => 'INSPECTOR'],
];

$permitted_tests = [
    'ADMINISTRADOR_TI' => [['/api/usuarios.php', 'GET']],
    'ADMINISTRATIVO_OPERATIVO' => [['/api/cuadrillas.php', 'GET'], ['/api/rutas.php', 'GET']],
    'OPERARIO' => [['/api/contenedores.php', 'GET']],
    'INSPECTOR' => [['/api/incidencias.php', 'GET']],
];

$denied_tests = [
    'OPERARIO' => [['/api/usuarios.php', 'POST'], ['/api/rutas.php', 'POST']],
    'INSPECTOR' => [['/api/cuadrillas.php', 'POST'], ['/api/rutas.php', 'POST']],
];

echo "╔═══════════════════════════════════════════════════════════════════╗\n";
echo "║     TEST DE ENDPOINTS HTTP - Validación de Permisos              ║\n";
echo "╚═══════════════════════════════════════════════════════════════════╝\n\n";

$total = 0;
$passed = 0;
$failed = [];

foreach ($users as $user) {
    $email = $user['email'];
    $pass = $user['pass'];
    $rol = $user['rol'];
    
    echo "───────────────────────────────────────────────────────────────────\n";
    echo "Usuario: $email ($rol)\n";
    echo "───────────────────────────────────────────────────────────────────\n";
    
    // Login
    $ch = curl_init($base_url . '/api/login.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => $pass]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_COOKIEJAR => $cookie_file,
        CURLOPT_COOKIEFILE => $cookie_file,
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $data = json_decode($resp, true);
    if ($status !== 200 || !($data['success'] ?? false)) {
        echo "  ❌ Login falló\n\n";
        continue;
    }
    echo "  ✅ Login exitoso\n\n";
    
    // Test endpoints permitidos
    echo "  Endpoints permitidos:\n";
    foreach ($permitted_tests[$rol] ?? [] as [$endpoint, $method]) {
        $total++;
        
        $ch = curl_init($base_url . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_COOKIEJAR => $cookie_file,
            CURLOPT_COOKIEFILE => $cookie_file,
        ]);
        $resp = curl_exec($ch);
        $result_status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($result_status === 200) {
            echo "    ✅ $method $endpoint\n";
            $passed++;
        } else {
            echo "    ❌ $method $endpoint (esperado 200, obtenido $result_status)\n";
            $failed[] = "[$email] $method $endpoint";
        }
    }
    
    // Test endpoints denegados
    if (isset($denied_tests[$rol])) {
        echo "\n  Endpoints denegados:\n";
        foreach ($denied_tests[$rol] as [$endpoint, $method]) {
            $total++;
            
            $ch = curl_init($base_url . $endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_COOKIEJAR => $cookie_file,
                CURLOPT_COOKIEFILE => $cookie_file,
            ]);
            $resp = curl_exec($ch);
            $result_status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($result_status === 403) {
                echo "    ✅ $method $endpoint (correctamente denegado)\n";
                $passed++;
            } else {
                echo "    ❌ $method $endpoint (esperado 403, obtenido $result_status)\n";
                $failed[] = "[$email] $method $endpoint";
            }
        }
    }
    
    echo "\n";
}

// Resumen
echo "╔═══════════════════════════════════════════════════════════════════╗\n";
echo "║                        RESUMEN                                   ║\n";
echo "╚═══════════════════════════════════════════════════════════════════╝\n\n";

$rate = ($total > 0) ? round(($passed / $total) * 100, 1) : 0;
echo "Total:    $total\n";
echo "Éxito:    $passed ✅\n";
echo "Fallo:    " . count($failed) . " ❌\n";
echo "Tasa:     $rate%\n\n";

if (count($failed) > 0) {
    echo "FALLOS:\n";
    foreach ($failed as $f) {
        echo "  • $f\n";
    }
}

@unlink($cookie_file);

if ($rate === 100.0 && $total > 0) {
    echo "\n✅ Todos los endpoints respetan los permisos correctamente\n";
} else if ($total > 0) {
    echo "\n⚠️ Hay inconsistencias en la validación de permisos\n";
}

exit($passed === $total && $total > 0 ? 0 : 1);
?>
