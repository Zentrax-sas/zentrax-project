<?php

use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../config/database.php';

/**
 * Integración optativa sobre XAMPP real. Requiere ZEMYNA_UTF8_HTTP_BASE y
 * ZEMYNA_UTF8_ADMIN_EMAIL/PASSWORD, ZEMYNA_UTF8_READER_EMAIL/PASSWORD.
 * Crea y elimina exclusivamente su propia incidencia temporal, sin dependencias.
 * Las credenciales se reciben por entorno y nunca se imprimen.
 */
final class Utf8HttpIntegrationTest extends TestCase
{
    private string $base;
    private array $cookies = [];

    protected function setUp(): void
    {
        $base = getenv('ZEMYNA_UTF8_HTTP_BASE');
        if (!$base) $this->markTestSkipped('Integración HTTP optativa: configurar ZEMYNA_UTF8_HTTP_BASE.');
        $this->base = rtrim($base, '/');
    }

    private function request(string $path, int $expected, string $method = 'GET', ?array $body = null, string $session = ''): array
    {
        $handle = curl_init($this->base . '/' . $path);
        $headers = [];
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=UTF-8'],
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers, $session) {
                $headers[] = trim($line);
                if ($session !== '' && preg_match('/^Set-Cookie:\s*([^;]+)/i', $line, $match)) $this->cookies[$session] = $match[1];
                return strlen($line);
            }]);
        if ($session !== '' && isset($this->cookies[$session])) curl_setopt($handle, CURLOPT_COOKIE, $this->cookies[$session]);
        if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $raw = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $contentType = curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        curl_close($handle);
        $this->assertSame($expected, $status, "$method $path: estado HTTP");
        $this->assertIsString($raw);
        $this->assertSame(1, preg_match('//u', $raw), "$path: bytes UTF-8 válidos");
        $this->assertMatchesRegularExpression('/charset=utf-8/i', $contentType, "$path: charset explícito");
        if (str_contains($path, 'backend/api/')) {
            $this->assertStringContainsString('application/json', $contentType);
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }
        $this->assertStringContainsString('text/html', $contentType);
        return ['html' => $raw];
    }

    public function testUtf8RealEnConexionHttpPersistenciaTrackingYMapas(): void
    {
        foreach (['ADMIN', 'READER'] as $role) {
            foreach (['EMAIL', 'PASSWORD'] as $field) {
                $this->assertNotFalse(getenv("ZEMYNA_UTF8_{$role}_{$field}"), "Falta variable de integración $role/$field");
            }
        }
        $db = (new Database())->getConnection();
        $this->assertInstanceOf(PDO::class, $db);
        foreach (['client', 'connection', 'results'] as $part) {
            $this->assertSame('utf8mb4', $db->query("SELECT @@character_set_$part")->fetchColumn());
        }
        $this->assertStringContainsString('MariaDB', $db->query('SELECT VERSION()')->fetchColumn());
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND CHARACTER_SET_NAME IS NOT NULL AND CHARACTER_SET_NAME <> 'utf8mb4'")->fetchColumn());
        $this->assertSame('utf8mb4_unicode_ci', $db->query('SELECT @@collation_database')->fetchColumn());
        foreach (['index.html', 'admin.html'] as $file) $this->request('frontend/public/' . $file, 200);
        $this->request('backend/api/session.php', 401);
        $this->request('backend/api/login.php', 405);
        foreach (['ADMIN' => 'admin', 'READER' => 'reader'] as $role => $session) {
            $login = $this->request('backend/api/login.php', 200, 'POST', [
                'email' => getenv("ZEMYNA_UTF8_{$role}_EMAIL"), 'password' => getenv("ZEMYNA_UTF8_{$role}_PASSWORD")], $session);
            $this->assertTrue($login['success']);
        }
        $container = $db->query('SELECT id_contenedor, latitud, longitud FROM contenedor WHERE activo=1 AND latitud BETWEEN -89 AND 89 AND longitud BETWEEN -179 AND 179 ORDER BY ABS(latitud+34.915)+ABS(longitud+56.154) LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($container);
        $viewport = ['view' => 'map', 'zoom' => 15, 'south' => $container['latitud'] - .01, 'north' => $container['latitud'] + .01,
            'west' => $container['longitud'] - .01, 'east' => $container['longitud'] + .01];
        $this->request('backend/api/contenedores.php?' . http_build_query($viewport), 200);
        $this->request('backend/api/incidencias.php?admin=1', 401);
        $this->request('backend/api/incidencias.php?' . http_build_query($viewport + ['admin' => 1]), 401);
        $text = 'Prueba UTF-8: información, recolección, contenedor dañado, año, pingüino, ¿correcto? á é í ó ú Á É Í Ó Ú ñ Ñ ü Ü ¿ ¡ [UTF8-' . bin2hex(random_bytes(6)) . ']';
        $type = 'Contenedor Roto/Dañado';
        $created = $this->request('backend/api/incidencias.php', 201, 'POST', ['descripcion' => $text, 'tipo_problema' => $type, 'id_contenedor' => (int)$container['id_contenedor']]);
        $id = $created['data']['id_incidencia'];
        $tracking = $created['data']['tracking_number'];
        try {
            $stmt = $db->prepare('SELECT descripcion, HEX(descripcion) AS bytes, CHAR_LENGTH(descripcion) AS chars, LENGTH(descripcion) AS length FROM incidencia WHERE id_incidencia=?');
            $stmt->execute([$id]); $stored = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->assertSame($text, $stored['descripcion']);
            $this->assertSame(strtoupper(bin2hex($text)), $stored['bytes']);
            $this->assertSame(mb_strlen($text, 'UTF-8'), (int)$stored['chars']);
            $this->assertSame(strlen($text), (int)$stored['length']);
            $detail = $this->request('backend/api/incidencias.php?id=' . $id, 200, 'GET', null, 'admin')['data'][0];
            $this->assertSame($text, $detail['descripcion']);
            for ($i = 0; $i < 2; $i++) {
                $this->request('backend/api/incidencias.php', 200, 'PUT', $detail, 'admin');
                $detail = $this->request('backend/api/incidencias.php?id=' . $id, 200, 'GET', null, 'admin')['data'][0];
                $this->assertSame($text, $detail['descripcion']);
                $this->assertSame($type, $detail['tipo_problema']);
            }
            $listed = $this->request('backend/api/incidencias.php?admin=1&tracking_number=' . $tracking, 200, 'GET', null, 'admin');
            $this->assertSame($text, $listed['data'][0]['descripcion']);
            foreach (['', 'admin'] as $session) {
                $filters = $viewport + ['estado' => 'Pendiente'];
                if ($session) $filters += ['admin' => 1, 'prioridad' => 'Media'];
                $map = $this->request('backend/api/incidencias.php?' . http_build_query($filters), 200, 'GET', null, $session);
                $matches = array_values(array_filter($map['data'], fn($row) => (int)$row['id_incidencia'] === (int)$id));
                $this->assertCount(1, $matches);
                $this->assertSame($type, $matches[0]['tipo_problema']);
                $this->assertSame(['id_incidencia', 'estado', 'prioridad', 'tipo_problema', 'fecha_reporte', 'latitud', 'longitud', 'contenedor_codigo'], array_keys($matches[0]));
            }
            $this->request('backend/api/incidencias.php', 403, 'PUT', ['id_incidencia' => $id, 'estado' => 'Resuelta'], 'reader');
            $this->request('backend/api/incidencias.php', 200, 'PUT', ['id_incidencia' => $id, 'estado' => 'Resuelta'], 'admin');
            $public = $this->request('backend/api/incidencias.php?tracking_number=' . $tracking, 200);
            $this->assertSame($type, $public['data']['tipo_problema']);
            $this->assertSame('Resuelta', $public['data']['estado']);
            $this->assertSame(['tracking_number', 'estado', 'fecha_reporte', 'tipo_problema'], array_keys($public['data']));
            $stmt->execute([$id]); $after = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->assertSame($stored, $after);
        } finally {
            // No elimina datos preexistentes ni registros que hayan adquirido dependencias.
            $refs = $db->query("SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='incidencia' AND REFERENCED_COLUMN_NAME='id_incidencia'")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($refs as $ref) {
                $check = $db->prepare('SELECT COUNT(*) FROM `' . str_replace('`', '``', $ref['TABLE_NAME']) . '` WHERE `' . str_replace('`', '``', $ref['COLUMN_NAME']) . '`=?');
                $check->execute([$id]);
                $this->assertSame(0, (int)$check->fetchColumn(), 'El registro temporal tiene dependencias: se conserva.');
            }
            $delete = $db->prepare('DELETE FROM incidencia WHERE id_incidencia=? AND tracking_number=? AND descripcion=?');
            $delete->execute([$id, $tracking, $text]);
            $this->assertSame(1, $delete->rowCount(), 'Solo se elimina la incidencia temporal intacta.');
            if ($report = getenv('ZEMYNA_UTF8_REPORT')) file_put_contents($report, json_encode(['tracking' => $tracking, 'deleted' => true], JSON_THROW_ON_ERROR));
        }
        $this->request('backend/api/incidencias.php?tracking_number=' . $tracking, 404);
    }
}
