<?php

use PHPUnit\Framework\TestCase;

final class DockerComposeContractTest extends TestCase
{
    private string $projectRoot;
    private string $compose;
    private string $dockerfile;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 2);
        $this->compose = (string) file_get_contents($this->projectRoot . '/compose.yaml');
        $this->dockerfile = (string) file_get_contents($this->projectRoot . '/Dockerfile');
    }

    public function testEntornoDockerEstaSeparadoDelEntornoXampp(): void
    {
        $appExample = (string) file_get_contents($this->projectRoot . '/.env.example');
        $dockerExample = (string) file_get_contents($this->projectRoot . '/.env.docker.example');

        $this->assertSame(
            "DB_HOST=localhost\nDB_NAME=gestion_residuosfinal\nDB_USER=root\nDB_PASS=\n",
            str_replace("\r\n", "\n", $appExample)
        );
        $this->assertStringContainsString('DB_USER=zemyna_app', $dockerExample);
        $this->assertStringContainsString("DB_PASSWORD=\n", str_replace("\r\n", "\n", $dockerExample));
        $this->assertStringContainsString("DB_ROOT_PASSWORD=\n", str_replace("\r\n", "\n", $dockerExample));
        $this->assertStringNotContainsString('DB_HOST=localhost', $dockerExample);
    }

    public function testDocumentaEnvFileDockerEnTodosLosComandos(): void
    {
        $this->assertStringContainsString('cp .env.docker.example .env.docker', $this->compose);
        $this->assertStringContainsString(
            'docker compose --env-file .env.docker -f compose.yaml config',
            $this->compose
        );
        $this->assertStringContainsString(
            'docker compose --env-file .env.docker -f compose.yaml up -d --build',
            $this->compose
        );
        $this->assertStringNotContainsString('cp .env.example .env', $this->compose);
    }

    public function testComposeExigeSecretosSinIncluirValoresReales(): void
    {
        $this->assertStringContainsString('${DB_PASSWORD:?', $this->compose);
        $this->assertStringContainsString('${DB_ROOT_PASSWORD:?', $this->compose);
        $this->assertStringContainsString('DB_PASS: "${DB_PASSWORD:', $this->compose);
        $this->assertStringNotContainsString('root_password', $this->compose);
        $this->assertStringNotContainsString('usuario_password', $this->compose);
    }

    public function testVolumenesPersistentesCubrenDatosFotosYLogs(): void
    {
        foreach (['db_data:', 'uploads_data:', 'session_logs:'] as $volume) {
            $this->assertStringContainsString($volume, $this->compose);
        }
        $this->assertStringContainsString(
            'uploads_data:/var/www/html/backend/uploads/incidencias',
            $this->compose
        );
        $this->assertStringContainsString(
            'session_logs:/var/www/html/backend/sesion',
            $this->compose
        );
        $this->assertStringNotContainsString('3306:3306', $this->compose);
    }

    public function testImagenPreparaPdoPermisosYProteccionApache(): void
    {
        $this->assertStringContainsString('docker-php-ext-install pdo_mysql', $this->dockerfile);
        $this->assertStringContainsString('AllowOverride All', $this->dockerfile);
        $this->assertStringContainsString(
            '/var/www/html/backend/uploads/incidencias',
            $this->dockerfile
        );
        $this->assertStringContainsString('/var/www/html/backend/sesion', $this->dockerfile);
        $this->assertStringContainsString('chown -R www-data:www-data', $this->dockerfile);
        $this->assertSame(
            'Require all denied',
            trim((string) file_get_contents($this->projectRoot . '/backend/uploads/incidencias/.htaccess'))
        );
        $this->assertSame(
            'Require all denied',
            trim((string) file_get_contents($this->projectRoot . '/backend/sesion/.htaccess'))
        );
    }

    public function testDockerignoreNoCopiaEntornosNiDatosDeEjecucion(): void
    {
        $dockerignore = (string) file_get_contents($this->projectRoot . '/.dockerignore');
        $gitignore = (string) file_get_contents(dirname($this->projectRoot, 2) . '/.gitignore');

        $this->assertStringContainsString('.env.*', $dockerignore);
        $this->assertStringContainsString('backend/uploads/incidencias/*', $dockerignore);
        $this->assertStringContainsString('backend/sesion/*', $dockerignore);
        $this->assertStringContainsString(
            '/03-proyecto-zemyna/programacion/.env.docker',
            $gitignore
        );
        $this->assertStringContainsString(
            '!/03-proyecto-zemyna/programacion/.env.docker.example',
            $gitignore
        );
    }
}
