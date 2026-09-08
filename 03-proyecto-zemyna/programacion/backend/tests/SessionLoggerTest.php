<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/SessionLogger.php';

final class SessionLoggerTest extends TestCase
{
    private string $tempDirectory;
    private Closure $clock;

    protected function setUp(): void
    {
        $this->tempDirectory = sys_get_temp_dir() . '/zemyna_session_logger_' . bin2hex(random_bytes(8));
        $this->clock = static fn (): DateTimeImmutable => new DateTimeImmutable(
            '2026-09-08T15:30:00+00:00'
        );
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempDirectory)) {
            return;
        }

        foreach (glob($this->tempDirectory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (glob($this->tempDirectory . '/.*') ?: [] as $file) {
            if (basename($file) !== '.' && basename($file) !== '..' && is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tempDirectory);
    }

    public function testLoginCorrectoGeneraEntrada(): void
    {
        $logger = $this->logger();

        $this->assertTrue($logger->log('LOGIN_SUCCESS', 'success', 7, ['Administrador']));

        $entry = $this->entries()[0];
        $this->assertSame('LOGIN_SUCCESS', $entry['event']);
        $this->assertSame('success', $entry['result']);
        $this->assertSame(7, $entry['user_id']);
        $this->assertSame(['ADMINISTRADOR_TI'], $entry['roles']);
    }

    public function testLoginFallidoGeneraEntradaAnonima(): void
    {
        $this->logger()->log('LOGIN_FAILURE', 'failure');

        $entry = $this->entries()[0];
        $this->assertSame('LOGIN_FAILURE', $entry['event']);
        $this->assertSame('failure', $entry['result']);
        $this->assertNull($entry['user_id']);
        $this->assertSame([], $entry['roles']);
    }

    public function testLogoutGeneraEntrada(): void
    {
        $this->logger()->log('LOGOUT', 'success', 3, ['operario', 'OPERARIO']);

        $entry = $this->entries()[0];
        $this->assertSame('LOGOUT', $entry['event']);
        $this->assertSame(3, $entry['user_id']);
        $this->assertSame(['OPERARIO'], $entry['roles']);
    }

    public function testTimestampUsaAmericaMontevideoYFormatoJsonLines(): void
    {
        $this->logger()->log('LOGIN_FAILURE', 'failure');

        $line = trim((string) file_get_contents($this->tempDirectory . '/sesiones.log'));
        $this->assertJson($line);
        $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('2026-09-08T12:30:00-03:00', $entry['timestamp']);
        $this->assertSame(
            ['timestamp', 'event', 'result', 'user_id', 'roles'],
            array_keys($entry)
        );
    }

    public function testAppendConservaEventosAnteriores(): void
    {
        $logger = $this->logger();
        $logger->log('LOGIN_SUCCESS', 'success', 1, ['ADMINISTRADOR_TI']);
        $logger->log('LOGOUT', 'success', 1, ['ADMINISTRADOR_TI']);

        $entries = $this->entries();
        $this->assertCount(2, $entries);
        $this->assertSame('LOGIN_SUCCESS', $entries[0]['event']);
        $this->assertSame('LOGOUT', $entries[1]['event']);
    }

    public function testEntradaNoContieneDatosSensibles(): void
    {
        $this->logger()->log('LOGIN_FAILURE', 'failure');

        $contents = (string) file_get_contents($this->tempDirectory . '/sesiones.log');
        foreach (['password', 'contrasena', 'hash', 'PHPSESSID', 'session_id', 'cookie', 'token'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $contents);
        }
    }

    public function testCreaDirectorioConPermisosRazonables(): void
    {
        $this->assertDirectoryDoesNotExist($this->tempDirectory);

        $this->assertTrue($this->logger()->log('LOGIN_FAILURE', 'failure'));

        $this->assertDirectoryExists($this->tempDirectory);
        $this->assertFileExists($this->tempDirectory . '/sesiones.log');
    }

    public function testRotaAntesDeSuperarElLimiteConfigurado(): void
    {
        $logger = $this->logger(180, 5);
        $logger->log('LOGIN_SUCCESS', 'success', 1, ['ADMINISTRADOR_TI']);
        $logger->log('LOGOUT', 'success', 1, ['ADMINISTRADOR_TI']);

        $archives = glob($this->tempDirectory . '/sesiones-*.log') ?: [];
        $this->assertCount(1, $archives);
        $this->assertSame('LOGIN_SUCCESS', $this->entries($archives[0])[0]['event']);
        $this->assertSame('LOGOUT', $this->entries()[0]['event']);
    }

    public function testRotacionConservaComoMaximoLosHistoricosConfigurados(): void
    {
        $logger = $this->logger(1, 2);
        foreach (range(1, 5) as $userId) {
            $logger->log('LOGIN_SUCCESS', 'success', $userId, ['OPERARIO']);
        }

        $this->assertCount(2, glob($this->tempDirectory . '/sesiones-*.log') ?: []);
        $this->assertCount(1, $this->entries());
    }

    public function testFalloDeEscrituraNoAlteraRespuestaDeAutenticacion(): void
    {
        mkdir($this->tempDirectory, 0750, true);
        $invalidDirectory = $this->tempDirectory . '/not-a-directory';
        file_put_contents($invalidDirectory, 'occupied');
        $response = ['success' => true, 'message' => 'Inicio de sesión correcto.'];
        $previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->tempDirectory . '/php-error.log');

        try {
            $logged = (new SessionLogger($invalidDirectory, 100, 1, $this->clock))
                ->log('LOGIN_SUCCESS', 'success', 1, ['ADMINISTRADOR_TI']);
        } finally {
            ini_set('error_log', (string) $previousErrorLog);
        }

        $this->assertFalse($logged);
        $this->assertSame(['success' => true, 'message' => 'Inicio de sesión correcto.'], $response);
        $this->assertStringContainsString(
            'no se pudo registrar un evento de autenticacion',
            (string) file_get_contents($this->tempDirectory . '/php-error.log')
        );
    }

    public function testUsaAppendYBloqueoExclusivo(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../helpers/SessionLogger.php');

        $this->assertStringContainsString("fopen(\$this->directory . '/.sesiones.lock', 'c')", $source);
        $this->assertStringContainsString('flock($lock, LOCK_EX)', $source);
        $this->assertStringContainsString('FILE_APPEND', $source);
    }

    public function testEndpointsIntegranUnSoloLoggerSinExponerPayload(): void
    {
        $login = (string) file_get_contents(__DIR__ . '/../api/login.php');
        $logout = (string) file_get_contents(__DIR__ . '/../api/logout.php');

        $this->assertStringContainsString("'LOGIN_SUCCESS'", $login);
        $this->assertStringContainsString("'LOGIN_FAILURE'", $login);
        $this->assertSame(1, substr_count($login, '$sessionLogger = new SessionLogger();'));
        $this->assertStringContainsString("'LOGOUT'", $logout);
        $this->assertStringNotContainsString('$_SESSION)', $login);
        $this->assertStringNotContainsString('$_SESSION)', $logout);
    }

    public function testPruebasNuncaUsanDirectorioRealDeSesiones(): void
    {
        $realDirectory = realpath(__DIR__ . '/../sesion');
        $this->assertNotFalse($realDirectory);
        $this->assertNotSame($realDirectory, realpath($this->tempDirectory) ?: $this->tempDirectory);
        $this->assertStringStartsWith(str_replace('\\', '/', sys_get_temp_dir()), str_replace('\\', '/', $this->tempDirectory));
    }

    private function logger(int $maxBytes = 5242880, int $maxArchives = 5): SessionLogger
    {
        return new SessionLogger($this->tempDirectory, $maxBytes, $maxArchives, $this->clock);
    }

    private function entries(?string $path = null): array
    {
        $contents = (string) file_get_contents($path ?? $this->tempDirectory . '/sesiones.log');
        return array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode(PHP_EOL, trim($contents))))
        );
    }
}
