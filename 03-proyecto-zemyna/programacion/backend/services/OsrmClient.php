<?php
declare(strict_types=1);

/** Solo códigos y mensajes públicos; nunca incluye el endpoint o errores cURL. */
final class RutaVialException extends RuntimeException
{
    public function __construct(public readonly string $reason, int $status, string $message)
    { parent::__construct($message, $status); }
}

final class OsrmClient
{
    private Closure $transport;
    public function __construct(private string $baseUrl, private int $timeout = 5,
        private float $maxSnap = 50.0, ?Closure $transport = null)
    {
        $url = parse_url($baseUrl);
        if (!$url || !in_array($url['scheme'] ?? '', ['http', 'https'], true)
            || empty($url['host']) || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment'])
            || preg_match('/[\s\x00-\x1f]/', $baseUrl)
            || $timeout < 1 || $timeout > 8 || !is_finite($maxSnap) || $maxSnap < 1 || $maxSnap > 100) {
            throw new RutaVialException('configuracion', 503, 'Servicio vial no configurado correctamente.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport ?? Closure::fromCallable([$this, 'http']);
    }

    public static function fromEnvironment(): self
    {
        $base = getenv('ZEMYNA_OSRM_URL');
        $timeout = getenv('ZEMYNA_OSRM_TIMEOUT');
        $snap = getenv('ZEMYNA_OSRM_MAX_SNAP_METERS');
        $timeout = ($timeout === false || $timeout === '') ? '5' : $timeout;
        $snap = ($snap === false || $snap === '') ? '50' : $snap;
        if (!$base || !preg_match('/^[1-8]$/D', $timeout) || !preg_match('/^\d+(?:\.\d+)?$/D', $snap)) {
            throw new RutaVialException('configuracion', 503, 'Servicio vial no configurado correctamente.');
        }
        return new self($base, (int) $timeout, (float) $snap);
    }

    /** API y GeoJSON usan longitud,latitud; el contrato de aplicación usa campos nombrados. */
    public static function point(array $point): array
    {
        if (array_keys($point) !== ['latitud', 'longitud']) {
            throw new InvalidArgumentException('Coordenadas inválidas.');
        }
        foreach ($point as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                throw new InvalidArgumentException('Coordenadas inválidas.');
            }
        }
        if (abs($point['latitud']) > 90 || abs($point['longitud']) > 180) {
            throw new InvalidArgumentException('Coordenadas inválidas.');
        }
        return ['latitud' => (float) $point['latitud'], 'longitud' => (float) $point['longitud']];
    }

    public function route(array $origin, array $destination): array
    {
        $origin = self::point($origin); $destination = self::point($destination);
        $format = static fn(array $p): string => sprintf('%.7F,%.7F', $p['longitud'], $p['latitud']);
        $url = $this->baseUrl . '/route/v1/driving/' . $format($origin) . ';' . $format($destination)
            . '?geometries=geojson&overview=full&steps=false&alternatives=false&generate_hints=false'
            . '&radiuses=' . $this->maxSnap . ';' . $this->maxSnap;
        try { $response = ($this->transport)($url, $this->timeout); }
        catch (Throwable $error) { throw new RutaVialException('indisponible', 503, 'Servicio vial temporalmente no disponible.'); }
        if (($response['error'] ?? null) === 'timeout') throw new RutaVialException('timeout', 504, 'El servicio vial agotó el tiempo de consulta.');
        if (!empty($response['error'])) throw new RutaVialException('indisponible', 503, 'Servicio vial temporalmente no disponible.');
        if (($response['status'] ?? 0) === 429) throw new RutaVialException('limite_motor', 503, 'El servicio vial alcanzó su límite de consultas.');
        if (!in_array($response['status'] ?? 0, [200, 400], true)) $this->invalid();
        $raw = $response['body'] ?? null;
        if (!is_string($raw) || strlen($raw) > 1048576) $this->invalid();
        try { $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (JsonException $error) { $this->invalid(); }
        if (!is_array($body)) $this->invalid();
        if (in_array($body['code'] ?? '', ['NoRoute', 'NoSegment'], true)) {
            throw new RutaVialException('sin_ruta', 422, 'No hay una ruta vial disponible para estos puntos.');
        }
        if ($response['status'] !== 200 || ($body['code'] ?? '') !== 'Ok') $this->invalid();
        $waypoints = $body['waypoints'] ?? null;
        $routes = $body['routes'] ?? null;
        if (!is_array($waypoints) || !array_is_list($waypoints) || count($waypoints) !== 2
            || !is_array($routes) || !array_is_list($routes) || count($routes) !== 1) $this->invalid();
        $snapped = [];
        foreach ($waypoints as $index => $waypoint) {
            if (!is_array($waypoint)) $this->invalid();
            $distance = $waypoint['distance'] ?? null;
            $location = $waypoint['location'] ?? null;
            if (!$this->nonnegative($distance) || !$this->coordinate($location)) $this->invalid();
            $input = $index === 0 ? $origin : $destination;
            if ($distance > $this->maxSnap || $this->meters($input, $location) > $this->maxSnap) {
                throw new RutaVialException('ajuste_excesivo', 422, 'Un punto requiere un ajuste excesivo a la vía. Revisá la ubicación.');
            }
            $snapped[] = ['latitud' => (float) $location[1], 'longitud' => (float) $location[0], 'ajuste_metros' => (float) $distance];
        }
        $route = $routes[0];
        if (!is_array($route)) $this->invalid();
        $geometry = $route['geometry'] ?? null;
        if (!$this->nonnegative($route['distance'] ?? null) || !$this->nonnegative($route['duration'] ?? null)
            || !is_array($geometry) || ($geometry['type'] ?? '') !== 'LineString'
            || !is_array($geometry['coordinates'] ?? null) || !array_is_list($geometry['coordinates'])
            || count($geometry['coordinates']) < 2 || count($geometry['coordinates']) > 10000) $this->invalid();
        foreach ($geometry['coordinates'] as $coordinate) if (!$this->coordinate($coordinate)) $this->invalid();
        foreach ([0, 1] as $index) {
            $end = $index === 0 ? $geometry['coordinates'][0] : $geometry['coordinates'][count($geometry['coordinates']) - 1];
            if ($this->meters($snapped[$index], $end) > 5) $this->invalid();
        }
        return ['geometria' => ['type' => 'LineString', 'coordinates' => $geometry['coordinates']],
            'distancia_metros' => (float) $route['distance'], 'duracion_segundos' => (float) $route['duration'],
            'puntos_ajustados' => $snapped];
    }

    private function invalid(): never { throw new RutaVialException('respuesta_motor', 502, 'El servicio vial devolvió una respuesta no válida.'); }
    private function nonnegative(mixed $n): bool { return (is_int($n) || is_float($n)) && is_finite((float) $n) && $n >= 0; }
    private function coordinate(mixed $p): bool
    {
        return is_array($p) && array_is_list($p) && count($p) === 2
            && (is_int($p[0]) || is_float($p[0])) && (is_int($p[1]) || is_float($p[1]))
            && is_finite((float) $p[0]) && is_finite((float) $p[1]) && abs($p[0]) <= 180 && abs($p[1]) <= 90;
    }
    private function meters(array $input, array $location): float
    {
        // Solo control del ajuste; esta distancia jamás es un costo o una ruta de fallback.
        $lat = deg2rad($location[1] - $input['latitud']); $lon = deg2rad($location[0] - $input['longitud']);
        $a = sin($lat / 2) ** 2 + cos(deg2rad($input['latitud'])) * cos(deg2rad($location[1])) * sin($lon / 2) ** 2;
        return 6371000 * 2 * asin(sqrt(min(1.0, max(0.0, $a))));
    }
    private function http(string $url, int $timeout): array
    {
        if (!function_exists('curl_init')) return ['error' => 'unavailable'];
        $curl = curl_init($url); $body = ''; $oversize = false;
        curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => min(2, $timeout),
            CURLOPT_TIMEOUT => $timeout, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '', CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'Zemyna-F5.2A/1.0',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$oversize): int {
                if (strlen($body) + strlen($chunk) > 1048576) { $oversize = true; return 0; }
                $body .= $chunk; return strlen($chunk);
            }]);
        curl_exec($curl); $errno = curl_errno($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        return ['status' => $status, 'body' => $body, 'error' => $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : (($errno || $oversize) ? 'unavailable' : null)];
    }
}
