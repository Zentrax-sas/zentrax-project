<?php

// Fuente domiciliaria independiente: Sistema Único de Direcciones, IDE Uruguay.
class DireccionRetiro
{
    protected function consultar(string $servicio, array $params): array
    {
        $url = 'https://direcciones.ide.uy/api/v1/geocode/' . $servicio . '?' . http_build_query($params);
        $cache = sys_get_temp_dir() . '/zemyna-direccion-' . hash('sha256', $url) . '.json';
        if (is_file($cache) && filemtime($cache) > time() - 86400) {
            $data = json_decode(file_get_contents($cache), true);
            if (is_array($data)) return $data;
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'Zemyna/1.0 (domicilios de retiro)', CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        $data = is_string($body) ? json_decode($body, true) : null;
        if ($status !== 200 || !is_array($data) || !array_is_list($data)) throw new RuntimeException('Servicio de direcciones no disponible.');
        @file_put_contents($cache, json_encode($data), LOCK_EX);
        return $data;
    }
    public function sugerir(string $q): array
    {
        $rows = $this->consultar('candidates', ['q' => $q, 'limit' => 10, 'soloLocalidad' => 'false']);
        $calles = [];
        foreach ($rows as $row) {
            if (($row['type'] ?? '') === 'CALLE' && is_string($row['address'] ?? null) && trim($row['address']) !== '') $calles[] = trim($row['address']);
        }
        return array_slice(array_values(array_unique($calles)), 0, 10);
    }
    public function resolver(string $calle, string $detalle): ?array
    {
        $partes = array_map('trim', explode(',', $calle)); $nombre = array_shift($partes);
        $q = $nombre . ' ' . $detalle . ($partes ? ', ' . implode(', ', $partes) : ', Uruguay');
        $candidatos = $this->consultar('candidates', ['q' => $calle, 'limit' => 10, 'soloLocalidad' => 'false']);
        $elegida = null;
        foreach ($candidatos as $candidato) {
            if (($candidato['type'] ?? '') === 'CALLE' && mb_strtoupper(trim((string)($candidato['address'] ?? ''))) === mb_strtoupper($calle)) { $elegida = $candidato; break; }
        }
        if (!$elegida || empty($elegida['idCalle'])) return null;
        $params = ['type' => 'calle', 'idcalle' => $elegida['idCalle']];
        $esPuerta = preg_match('/^(\d+)\s*([a-zA-Z]?)$/', $detalle, $puerta) === 1;
        if ($esPuerta) { $params['portal'] = $puerta[1]; if ($puerta[2] !== '') $params['letra'] = $puerta[2]; }
        $rows = [];
        if (!$esPuerta) {
            $esquina = preg_replace('/^(esquina|esq\.?)\s+/iu', '', $detalle);
            $rows = $this->consultar('crucesPorIdCalle', ['idcalle' => $elegida['idCalle'], 'q' => $esquina]);
        }
        $rows = array_merge($rows, $this->consultar('find', $params));
        foreach ($rows as $row) {
            $lat = $row['lat'] ?? null; $lon = $row['lng'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lon) || !is_finite((float)$lat) || !is_finite((float)$lon) ||
                abs((float)$lat) > 85 || abs((float)$lon) > 180 || ((float)$lat === 0.0 && (float)$lon === 0.0) || ($row['type'] ?? '') === 'LOCALIDAD') continue;
            return ['calle' => $nombre, 'numero_referencia' => $detalle,
                'direccion' => $q, 'latitud' => (float)$lat, 'longitud' => (float)$lon,
                'aproximada' => (!$esPuerta && ($row['type'] ?? '') !== 'ESQUINA') || !empty($row['stateMsg']) || ($row['type'] ?? '') === 'CALLE'];
        }
        return null;
    }
}
