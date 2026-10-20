<?php

// Origen del WebView Android con la configuración Capacitor actual.
const ZEMYNA_CAPACITOR_ORIGIN = 'https://localhost';

function zemynaRequestIsHttps(array $server): bool
{
    // No confiar en X-Forwarded-Proto recibido directamente del cliente.
    return isset($server['HTTPS']) && in_array(strtolower((string) $server['HTTPS']), ['on', '1'], true);
}

function zemynaOriginAllowed(array $server): bool
{
    $origin = $server['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return true; // Navegación directa y clientes sin Origin.
    }
    $allowed = [
        ZEMYNA_CAPACITOR_ORIGIN,
        'http://localhost:4200', 'http://127.0.0.1:4200',
        'http://localhost:8100', 'http://127.0.0.1:8100',
        'http://localhost:8101', 'http://127.0.0.1:8101',
    ];
    $host = $server['HTTP_HOST'] ?? '';
    if ($host !== '') {
        $allowed[] = (zemynaRequestIsHttps($server) ? 'https://' : 'http://') . $host;
    }
    return in_array($origin, $allowed, true);
}

function zemynaApplyCors(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('Vary: Origin', false);
    if (!zemynaOriginAllowed($_SERVER)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'data' => null, 'message' => 'Origen no permitido.', 'errors' => [], 'statusCode' => 403]);
        exit;
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit; // Nunca iniciar sesión ni acceder a BD en preflight.
    }
}

function zemynaSessionCookieOptions(array $server): array
{
    $https = zemynaRequestIsHttps($server);
    return [
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        // HTTP LAN no admite None sin Secure. No eludir esa restricción.
        'samesite' => $https && ($server['HTTP_ORIGIN'] ?? '') === ZEMYNA_CAPACITOR_ORIGIN ? 'None' : 'Lax',
    ];
}

function zemynaStartSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(zemynaSessionCookieOptions($_SERVER));
        session_start();
    }
}
