<?php

use PHPUnit\Framework\TestCase;

final class ProteccionDatosLegacyApiTest extends TestCase
{
    private function execute(string $endpoint, string $method, ?string $role, array $query): array
    {
        $path = __DIR__ . '/../api/' . $endpoint . '.php';
        $script = '$_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . ';'
            . '$_SESSION=' . var_export($role === null ? [] : ['usuario' => ['id_usuario' => 1, 'roles' => [$role]]], true) . ';'
            . '$_GET=' . var_export($query, true) . ';'
            . 'class Database { function __construct() { throw new RuntimeException("BD ejecutada"); } }'
            . 'class VecinoController { function __construct() { throw new RuntimeException("Controlador ejecutado"); } }'
            . 'class DenunciaController { function __construct() { throw new RuntimeException("Controlador ejecutado"); } }'
            . 'spl_autoload_register(function($name) { throw new RuntimeException("Dependencia ejecutada"); });'
            . 'ob_start(); register_shutdown_function(function() {'
            . '$body=ob_get_clean(); echo json_encode(["status"=>http_response_code(),"body"=>$body,"files"=>get_included_files(),"session"=>session_status()]); });'
            . 'require ' . var_export($path, true) . ';';
        $process = proc_open([PHP_BINARY, '-r', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([realpath($path)], $result['files']);
        $this->assertSame(PHP_SESSION_NONE, $result['session']);
        return $result;
    }

    public function testRetiradaUniformeSinEjecutarDependencias(): void
    {
        $expected = ['success' => false, 'data' => null,
            'message' => 'Esta operación ya no está disponible.', 'errors' => [], 'statusCode' => 410];
        foreach (['vecinos', 'denuncias'] as $endpoint) {
            foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
                foreach ([null, 'OPERARIO', 'INSPECTOR', 'ADMINISTRADOR_TI'] as $role) {
                    foreach ([[], ['ci' => '12345678', 'id' => '987654']] as $query) {
                        $result = $this->execute($endpoint, $method, $role, $query);
                        $this->assertSame(410, $result['status']);
                        $this->assertSame($expected, json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR));
                    }
                }
            }
        }
    }

    public function testMetodosNoAdmitidosYPreflight(): void
    {
        foreach (['vecinos', 'denuncias'] as $endpoint) {
            foreach (['PATCH', 'HEAD', 'TRACE'] as $method) {
                $result = $this->execute($endpoint, $method, null, []);
                $this->assertSame(405, $result['status']);
                $this->assertSame(['success' => false, 'data' => null,
                    'message' => 'Método no permitido.', 'errors' => [], 'statusCode' => 405],
                    json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR));
            }
            $result = $this->execute($endpoint, 'OPTIONS', null, []);
            $this->assertSame(204, $result['status']);
            $this->assertSame('', $result['body']);
            // CLI no emite encabezados: verificar igualdad con los CORS del bootstrap.
            $source = file_get_contents(__DIR__ . '/../api/' . $endpoint . '.php');
            $bootstrap = file_get_contents(__DIR__ . '/../config/bootstrap.php');
            $this->assertStringContainsString('zemynaApplyCors();', $bootstrap);
            $http = file_get_contents(__DIR__ . '/../config/http.php');
            $this->assertStringNotContainsString('Access-Control-Allow-Origin: *', $http);
            $this->assertStringContainsString('Access-Control-Allow-Credentials: true', $http);
            foreach (['Content-Type: application/json; charset=UTF-8', 'Access-Control-Allow-Origin: *',
                'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS',
                'Access-Control-Allow-Headers: Content-Type, Authorization'] as $header) {
                $this->assertStringContainsString("header('" . $header . "');", $source);

            }
            $this->assertStringContainsString("header('Cache-Control: no-store');", $source);
        }
    }
}
