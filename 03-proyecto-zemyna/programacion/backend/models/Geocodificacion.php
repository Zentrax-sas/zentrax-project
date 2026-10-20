<?php

class Geocodificacion {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    private static function texto($value): ?string {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')
            || preg_match('/[<>\x00-\x1F\x7F]/u', $value)) return null;
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        return $value === '' ? null : $value;
    }

    private static function primero(array $data, array $keys): ?string {
        foreach ($keys as $key) {
            $value = self::texto($data[$key] ?? null);
            if ($value !== null) return $value;
        }
        return null;
    }

    public static function normalizarRespuesta(array $response): array {
        $address = is_array($response['address'] ?? null) ? $response['address'] : [];
        $via = null;
        foreach (['road', 'pedestrian', 'residential', 'footway', 'path', 'service'] as $key) {
            $candidate = self::texto($address[$key] ?? null);
            if ($candidate !== null && preg_match('/\p{L}/u', $candidate)) { $via = $candidate; break; }
        }
        $numero = self::texto($address['house_number'] ?? null);
        // Una lista de puertas distintas no identifica una puerta del contenedor.
        $numeros = $numero === null ? [] : array_map('trim', explode(',', $numero));
        $multiples = count(array_unique($numeros)) > 1;
        foreach ($numeros as $parte) {
            if (!preg_match('/^\d[\d\s\/\-]*(?:[a-z]{1,3})?$/iu', $parte)) $multiples = false;
        }
        if ($multiples) $numero = null;
        $direccion = $via === null ? null : $via . ($numero !== null ? ' ' . $numero : '');
        // Nunca truncar una direccion: la columna contenedor.direccion admite 150 caracteres.
        if ($direccion !== null && mb_strlen($direccion, 'UTF-8') > 150) $direccion = null;
        return [
            'direccion' => $direccion,
            'barrio' => self::primero($address, ['neighbourhood', 'suburb']),
            'localidad' => self::primero($address, ['city', 'town', 'village']),
            'display_name' => self::texto($response['display_name'] ?? null),
        ];
    }

    public static function normalizarCache(array $cache): array {
        // La cache historica guarda display_name, no address ni coordenadas.
        // Solo reutilizar formatos inequívocos; los demás se vuelven a consultar.
        $display = self::texto($cache['direccion'] ?? null);
        $barrio = self::texto($cache['barrio'] ?? null);
        $localidad = self::texto($cache['localidad'] ?? null);
        $parts = $display === null ? [] : array_map('trim', explode(',', $display));
        $via = null; $numeros = [];
        $esNumero = static fn(string $text): bool => (bool) preg_match('/^\d[\d\s\/\-]*(?:[a-z]{1,3})?$/iu', $text);
        // Un POI puede preceder al numero: "Escuela ..., 1411, Meliton Gonzalez, ...".
        if (count($parts) > 2 && !$esNumero($parts[0]) && $esNumero($parts[1])) array_shift($parts);
        while ($parts && $esNumero($parts[0])) $numeros[] = array_shift($parts);
        if ($parts && ($numeros || preg_match('/^(avenida|av\.|calle|camino|rambla|bulevar|bvar\.|ruta|pasaje)\s/iu', $parts[0]))) {
            $via = $parts[0];
        }
        // No confundir barrio/localidad con via; nunca inferir una calle desde solo un barrio.
        if ($via !== null) {
            foreach ([$barrio, $localidad, 'Uruguay'] as $place) {
                if ($place !== null && mb_strtolower($via, 'UTF-8') === mb_strtolower($place, 'UTF-8')) { $via = null; break; }
            }
        }
        return self::normalizarRespuesta([
            'display_name' => $display,
            'address' => ['road' => $via, 'house_number' => $numeros ? implode(',', $numeros) : null,
                'neighbourhood' => $barrio, 'city' => $localidad],
        ]);
    }

    /** Logica compartida por HTTP y CLI. No modifica contenedor. */
    public function resolver(array $contenedor): array {
        $cache = $this->findCache((int) $contenedor['id_contenedor']);
        if ($cache) {
            $normalizada = self::normalizarCache($cache);
            if ($normalizada['direccion'] !== null) {
                return ['source' => 'cache', 'data' => $normalizada, 'requests' => 0, 'cache_saved' => true, 'error' => null];
            }
        }
        $lat = $contenedor['latitud'] ?? null; $lon = $contenedor['longitud'] ?? null;
        $empty = self::normalizarRespuesta([]);
        if (!is_numeric($lat) || !is_numeric($lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180
            || ((float) $lat === 0.0 && (float) $lon === 0.0)) {
            return ['source' => 'fallback', 'data' => $empty, 'requests' => 0, 'cache_saved' => false, 'error' => 'Coordenadas invalidas'];
        }
        $requests = 0;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = $this->consultarNominatim((float) $lat, (float) $lon);
            $requests += $response['requested'] ? 1 : 0;
            if ($response['status'] === 200 && is_array($response['body'])) {
                $data = self::normalizarRespuesta($response['body']);
                // Se mantiene el contrato de cache existente: direccion contiene display_name.
                $saved = $data['display_name'] !== null && strlen($data['display_name']) <= 65535
                    && ($data['barrio'] === null || mb_strlen($data['barrio'], 'UTF-8') <= 150)
                    && ($data['localidad'] === null || mb_strlen($data['localidad'], 'UTF-8') <= 150)
                    && $this->saveCache((int) $contenedor['id_contenedor'], $data['display_name'], $data['barrio'], $data['localidad']);
                return ['source' => 'external', 'data' => $data, 'requests' => $requests, 'cache_saved' => $saved,
                    'error' => $saved ? null : 'No se pudo conservar el resultado completo en cache'];
            }
            if (!$response['transient'] || $attempt === 1) break;
        }
        return ['source' => 'fallback', 'data' => $empty, 'requests' => $requests, 'cache_saved' => false,
            'error' => 'No se pudo consultar Nominatim (HTTP ' . $response['status'] . ')'];
    }

    protected function consultarNominatim(float $lat, float $lon): array {
        $failure = ['status' => 0, 'body' => null, 'transient' => false, 'requested' => false];
        // GET_LOCK coordina endpoint y lote en la misma base, sin modificar el esquema.
        $lock = $this->conn->query("SELECT GET_LOCK('zemyna_nominatim_reverse', 6)")->fetchColumn();
        if ((int) $lock !== 1) return $failure;
        try {
            // Espera incluso tras el request previo: nunca mas de una peticion por segundo.
            usleep(1000000);
            $url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
                'format' => 'jsonv2', 'lat' => $lat, 'lon' => $lon, 'zoom' => 18, 'addressdetails' => 1,
            ]);
            $context = stream_context_create(['http' => [
                'method' => 'GET', 'timeout' => 5, 'ignore_errors' => true, 'follow_location' => 0,
                'header' => "User-Agent: Zemyna/1.0 (+https://github.com/Zentrax-sas/zentrax-project)\r\nAccept: application/json\r\n",
            ]]);
            error_clear_last();
            $body = @file_get_contents($url, false, $context);
            $error = error_get_last()['message'] ?? '';
            $status = 0; $retryAfter = null;
            foreach ($http_response_header ?? [] as $header) {
                if (preg_match('~^HTTP/\S+\s+(\d+)~', $header, $m)) $status = (int) $m[1];
                if (stripos($header, 'Retry-After:') === 0) $retryAfter = trim(substr($header, 12));
            }
            // Si el servidor pide una pausa mayor, detenerse: no ignorar Retry-After.
            $retryAllowed = $retryAfter === null || (ctype_digit($retryAfter) && (int) $retryAfter <= 1);
            $transient = $retryAllowed && (in_array($status, [429, 502, 503, 504], true)
                || ($status === 0 && preg_match('/timed out|timeout|reset by peer/i', $error)));
            return ['status' => $status, 'body' => $body === false ? null : json_decode($body, true),
                'transient' => (bool) $transient, 'requested' => true];
        } finally {
            $this->conn->query("SELECT RELEASE_LOCK('zemyna_nominatim_reverse')");
        }
    }

    public function findCache($idContenedor) {
        $query = "SELECT direccion, barrio, localidad, fecha_consulta
                  FROM geocodificacion_cache
                  WHERE id_contenedor = :id_contenedor
                  LIMIT 1";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id_contenedor', (int) $idContenedor, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $exception) {
            return null;
        }
    }

    public function findContenedor($idContenedor) {
        $query = "SELECT id_contenedor, codigo, direccion, latitud, longitud, estado
                  FROM contenedor
                  WHERE id_contenedor = :id_contenedor
                  AND activo = 1
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id_contenedor', (int) $idContenedor, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function saveCache($idContenedor, $direccion, $barrio, $localidad) {
        $query = "INSERT INTO geocodificacion_cache
                  (id_contenedor, direccion, barrio, localidad, fecha_consulta)
                  VALUES (:id_contenedor, :direccion, :barrio, :localidad, NOW())
                  ON DUPLICATE KEY UPDATE
                      direccion = VALUES(direccion),
                      barrio = VALUES(barrio),
                      localidad = VALUES(localidad),
                      fecha_consulta = NOW()";

        try {
            $stmt = $this->conn->prepare($query);
            return $stmt->execute([
                ':id_contenedor' => (int) $idContenedor,
                ':direccion' => $direccion,
                ':barrio' => $barrio,
                ':localidad' => $localidad
            ]);
        } catch (PDOException $exception) {
            return false;
        }
    }
}
