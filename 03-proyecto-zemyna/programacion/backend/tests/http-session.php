<?php
// Integración HTTP sin BD: la foto inválida detiene el POST después del CAPTCHA.
// php tests/http-session.php http://localhost/.../backend/api
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/http.php';
$base = rtrim($argv[1] ?? '', '/');
if (!filter_var($base, FILTER_VALIDATE_URL)) { throw new RuntimeException('Indicar URL de API de prueba.'); }
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function request(string $path, string $method = 'GET', array $headers = [], string $body = ''): array {
    global $base;
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body,
        'ignore_errors' => true, 'timeout' => 10,
    ]]);
    $response = file_get_contents($base . $path, false, $context);
    if ($response === false) { throw new RuntimeException('No se pudo contactar API.'); }
    $raw = $http_response_header;
    preg_match('/\s(\d{3})\s/', $raw[0], $status);
    $result = [];
    foreach (array_slice($raw, 1) as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) { $result[strtolower($parts[0])] = trim($parts[1]); }
    }
    return [(int) $status[1], $result, json_decode($response, true)];
}
foreach (['https://localhost', 'http://localhost:4200'] as $origin) {
    [$status, $headers] = request('/solicitud-retiro.php', 'OPTIONS', [
        'Origin: ' . $origin, 'Access-Control-Request-Method: POST',
        'Access-Control-Request-Headers: Content-Type',
    ]);
    check($status === 204, 'Preflight 204');
    check(($headers['access-control-allow-origin'] ?? '') === $origin, 'Origen explícito');
    check(($headers['access-control-allow-credentials'] ?? '') === 'true', 'Credenciales');
    check(str_contains($headers['vary'] ?? '', 'Origin'), 'Vary');
    check(!isset($headers['set-cookie']), 'Preflight no crea sesión');
}
foreach (['OPTIONS', 'GET', 'POST'] as $method) {
    [$status, $headers] = request('/captcha.php', $method, ['Origin: https://localhost.evil.test']);
    check($status === 403, 'Origen desconocido rechazado');
    check(!isset($headers['access-control-allow-origin']) && !isset($headers['set-cookie']), 'Sin permiso ni cookie');
}
// Tanto web sin Origin como Android: verificar sesión en PHP, NO política del WebView.
foreach (['', 'https://localhost', 'http://localhost:4200'] as $origin) {
    $originHeaders = $origin === '' ? [] : ['Origin: ' . $origin];
    [$status, $headers, $data] = request('/captcha.php', 'GET', $originHeaders);
    check($status === 200 && ($data['success'] ?? false), 'CAPTCHA real');
    $cookie = $headers['set-cookie'] ?? '';
    check(str_contains(strtolower($cookie), 'httponly'), 'HttpOnly');
    if (str_starts_with($base, 'http://')) {
        check(str_contains(strtolower($cookie), 'samesite=lax'), 'HTTP Lax');
        check(!str_contains(strtolower($cookie), '; secure'), 'HTTP sin Secure inválido');
    }
    preg_match('/(\d+)\s*\+\s*(\d+)/u', $data['data']['pregunta'], $numbers);
    $answer = (int) $numbers[1] + (int) $numbers[2];
    $boundary = 'ZemynaSessionTestBoundary';
    $body = "--$boundary\r\nContent-Disposition: form-data; name=\"captcha_respuesta\"\r\n\r\n$answer\r\n"
        . "--$boundary\r\nContent-Disposition: form-data; name=\"foto\"; filename=\"invalid.txt\"\r\n"
        . "Content-Type: text/plain\r\n\r\nnot-an-image\r\n--$boundary--\r\n";
    $postHeaders = array_merge($originHeaders, ['Content-Type: multipart/form-data; boundary=' . $boundary]);
    [$status, , $data] = request('/solicitud-retiro.php', 'POST', $postHeaders, $body);
    check($status === 400 && isset($data['errors']['captcha_respuesta']), 'Sin cookie falla CAPTCHA');
    $postHeaders[] = 'Cookie: ' . explode(';', $cookie, 2)[0];
    [$status, , $data] = request('/solicitud-retiro.php', 'POST', $postHeaders, $body);
    check($status === 400 && ($data['message'] ?? '') === 'Solo se permiten imágenes JPG, PNG o WEBP.', 'Misma sesión supera CAPTCHA sin escribir BD');
    [$status, , $data] = request('/solicitud-retiro.php', 'POST', $postHeaders, $body);
    check($status === 400 && isset($data['errors']['captcha_respuesta']), 'CAPTCHA de un solo uso');
}
$nativeHttps = zemynaSessionCookieOptions(['HTTPS' => 'on', 'HTTP_ORIGIN' => ZEMYNA_CAPACITOR_ORIGIN]);
check($nativeHttps['secure'] && $nativeHttps['samesite'] === 'None', 'Política HTTPS Capacitor');
check(zemynaSessionCookieOptions(['HTTPS' => 'on'])['samesite'] === 'Lax', 'Política web HTTPS');
check(!zemynaRequestIsHttps(['HTTP_X_FORWARDED_PROTO' => 'https']), 'No confiar en cabecera del cliente');
echo "OK: $checks comprobaciones; sin creación de solicitudes ni cambios en BD.\n";
