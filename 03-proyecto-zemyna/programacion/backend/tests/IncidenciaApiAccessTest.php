<?php

use PHPUnit\Framework\TestCase;

/** Ejecuta el endpoint real en otro proceso, conservando sus guards de sesión/permisos.
 * El controlador doble evita leer o modificar la base local en estas pruebas de acceso.
 */
final class IncidenciaApiAccessTest extends TestCase
{
    /** @dataProvider requests */
    public function testAccesoYRuteo(string $method, array $query, array $session, int $status, ?string $operation): void
    {
        $script = tempnam(sys_get_temp_dir(), 'inc_access_');
        $endpoint = realpath(__DIR__ . '/../api/incidencias.php');
        $code = <<<'PHP'
<?php
session_save_path(sys_get_temp_dir());
session_start();
putenv('DB_HOST=127.0.0.1;port=1');
class IncidenciaController {
    public function __construct($db) {}
    public function getLocation($id) { return ['success' => true, 'statusCode' => 200, 'operation' => 'location', 'data' => []]; }
    public function getReport($filters) { return ['success' => true, 'statusCode' => 200, 'operation' => 'report', 'data' => []]; }
    public function getMap($filters) { return ['success' => true, 'statusCode' => 200, 'operation' => 'map', 'data' => []]; }
    public function getAll($filters) { return ['success' => true, 'statusCode' => 200, 'operation' => 'list', 'data' => [$filters]]; }
    public function getPublicByTracking($tracking) { return ['success' => true, 'statusCode' => 200, 'operation' => 'public', 'data' => ['tracking_number' => $tracking]]; }
    public function getCuadrillas() { return ['success' => true, 'statusCode' => 200, 'operation' => 'cuadrillas', 'data' => []]; }
}
register_shutdown_function(function () {
    $body = ob_get_clean();
    echo json_encode(['status' => http_response_code(), 'body' => json_decode($body, true)]);
    session_destroy();
});
ob_start();
PHP;
        $code .= PHP_EOL . '$_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';';
        $code .= PHP_EOL . '$_GET = ' . var_export($query, true) . ';';
        $code .= PHP_EOL . '$_SESSION = ' . var_export($session, true) . ';';
        $code .= PHP_EOL . 'require ' . var_export($endpoint, true) . ';';
        file_put_contents($script, $code);
        try {
            $process = proc_open([PHP_BINARY, $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $errors);
            $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($status, $result['status']);
            $this->assertSame($operation, $result['body']['operation'] ?? null);
            if ($status === 200 && $operation === 'list') {
                $this->assertSame($query['tracking_number'] ?? null, $result['body']['data'][0]['tracking_number']);
            }
        } finally {
            unlink($script);
        }
    }

    public static function requests(): array
    {
        $reader = ['usuario' => ['roles' => ['INSPECTOR'], 'autorizaciones' => [['permiso' => 'incidencia.consultar', 'sector' => 'INSPECCION']]]];
        $writer = ['usuario' => ['roles' => [], 'autorizaciones' => [['permiso' => 'incidencia.modificar', 'sector' => 'OPERACIONES']]]];
        $none = ['usuario' => ['roles' => ['OPERARIO'], 'autorizaciones' => []]];
        $wrongSector = ['usuario' => ['roles' => [], 'autorizaciones' => [['permiso' => 'incidencia.consultar', 'sector' => 'LOGISTICA']]]];
        return [
            'ubicación sin sesión' => ['GET', ['view' => 'location', 'id' => 2, 'tracking_number' => 'INC-2026-ABCDF'], [], 401, null],
            'ubicación sin permiso' => ['GET', ['view' => 'location', 'id' => 2], $none, 403, null],
            'ubicación autorizada' => ['GET', ['view' => 'location', 'id' => 2], $reader, 200, 'location'],
            'informe sin sesión incluso con tracking' => ['GET', ['view' => 'report', 'tracking_number' => 'INC-2026-ABCDE'], [], 401, null],
            'informe sin permiso' => ['GET', ['view' => 'report'], $none, 403, null],
            'informe sector incorrecto' => ['GET', ['view' => 'report'], $wrongSector, 403, null],
            'informe autorizado' => ['GET', ['view' => 'report'], $reader, 200, 'report'],
            'mapa administrativo sin sesión' => ['GET', ['view' => 'map', 'admin' => 1], [], 401, null],
            'mapa administrativo sin permiso' => ['GET', ['view' => 'map', 'admin' => 1], $none, 403, null],
            'mapa administrativo sector incorrecto' => ['GET', ['view' => 'map', 'admin' => 1], $wrongSector, 403, null],
            'mapa administrativo autorizado' => ['GET', ['view' => 'map', 'admin' => 1], $reader, 200, 'map'],
            'mapa público sin sesión' => ['GET', ['view' => 'map', 'north' => -34.8, 'south' => -35, 'west' => -56.3, 'east' => -56, 'zoom' => 14], [], 200, 'map'],
            'listado autorizado' => ['GET', ['admin' => '1'], $reader, 200, 'list'],
            'sin sesión' => ['GET', [], [], 401, null],
            'sin permiso' => ['GET', [], $none, 403, null],
            'sector incorrecto' => ['GET', [], $wrongSector, 403, null],
            'detalle sin sesión' => ['GET', ['id' => 1], [], 401, null],
            'detalle sin permiso' => ['GET', ['id' => 1], $none, 403, null],
            'detalle autorizado' => ['GET', ['id' => 1], $reader, 200, 'list'],
            'tracking administrativo autorizado' => ['GET', ['admin' => '1', 'tracking_number' => 'INC-2026-ABCDE'], $reader, 200, 'list'],
            'tracking administrativo privado' => ['GET', ['admin' => '1', 'tracking_number' => 'INC-2026-ABCDE'], [], 401, null],
            'seguimiento público' => ['GET', ['tracking_number' => 'INC-2026-ABCDE'], [], 200, 'public'],
            'opciones privadas' => ['GET', ['opciones' => 'cuadrillas'], $none, 403, null],
            'opciones autorizadas' => ['GET', ['opciones' => 'cuadrillas'], $reader, 200, 'cuadrillas'],
            'actualización sin sesión' => ['PUT', [], [], 401, null],
            'actualización sin permiso' => ['PUT', [], $none, 403, null],
            'permiso de modificación habilita validación del cuerpo' => ['PUT', [], $writer, 400, null],
            'registro público alcanza validación sin sesión' => ['POST', [], [], 400, null],
            'consulta no habilita actualización' => ['PUT', [], $reader, 403, null],
        ];
    }
}
