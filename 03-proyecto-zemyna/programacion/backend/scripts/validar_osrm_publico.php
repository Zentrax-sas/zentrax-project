<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/OsrmClient.php';

// Opt-in manual: nunca se ejecuta como parte de PHPUnit ni admite coordenadas de BD.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--una-consulta-demo') {
    if (PHP_SAPI !== 'cli') http_response_code(404);
    exit(2);
}
// Dos puntos públicos aproximados del corredor 18 de Julio, Montevideo.
// No son posiciones de personas, vehículos o trabajos de Zemýna.
$origin = ['latitud' => -34.9061, 'longitud' => -56.1960];
$destination = ['latitud' => -34.9057, 'longitud' => -56.1878];
try {
    if (!function_exists('curl_init')) throw new RutaVialException('disponibilidad', 503, 'No se pudo verificar disponibilidad del demo.');
    $probe = curl_init('https://router.project-osrm.org/');
    curl_setopt_array($probe, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROXY => '', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Zemyna-F5.2B-validation/1.0']);
    curl_exec($probe); $probeError = curl_errno($probe); $probeStatus = curl_getinfo($probe, CURLINFO_RESPONSE_CODE);
    curl_close($probe);
    // La raíz puede devolver 400/404: el servicio API no publica una página de inicio.
    if ($probeError || !in_array($probeStatus, [200, 400, 404], true)) {
        throw new RutaVialException('disponibilidad', 503, 'No se pudo verificar disponibilidad HTTPS del demo; no se consultó ninguna ruta.');
    }
    $route = (new OsrmClient('https://router.project-osrm.org', 8, 50))->route($origin, $destination);
    echo json_encode(['prueba' => 'OSRM real: una consulta puntual', 'origen' => $origin,
        'destino' => $destination, 'motor' => 'OSRM', 'consultiva' => true,
        'advertencias' => ['Puntos públicos declarados, sin datos de trabajos ni GPS de vehículos.',
            'OSRM / OpenStreetMap contributors; https://www.openstreetmap.org/ (fix the map).',
            'Demo sin garantía de disponibilidad; no certifica acceso de vehículos pesados.',
            'Validación directa del cliente; autorización y advertencias del servicio se validan separadamente.'],
        'resultado' => $route], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (RutaVialException $e) {
    echo json_encode(['prueba' => 'OSRM real: consulta no validada', 'code' => $e->reason,
        'statusCode' => $e->getCode(), 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
