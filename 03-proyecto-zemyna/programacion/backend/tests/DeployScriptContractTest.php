<?php

use PHPUnit\Framework\TestCase;

final class DeployScriptContractTest extends TestCase
{
    private string $script;
    private string $config;
    private string $documentation;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2) . '/../sistemas-operativos';
        $this->script = (string) file_get_contents($root . '/scripts/deploy_zemyna.sh');
        $this->config = (string) file_get_contents($root . '/config/deploy.conf.example');
        $this->documentation = (string) file_get_contents($root . '/README.md');
    }

    public function testScriptRequiresRootPrivateConfigurationAndLock(): void
    {
        $this->assertStringStartsWith("#!/bin/bash\nset -Eeuo pipefail", $this->script);
        $this->assertStringContainsString('EUID', $this->script);
        $this->assertStringContainsString('/etc/zemyna/deploy.conf', $this->script);
        $this->assertStringContainsString("stat -c '%a'", $this->script);
        $this->assertStringContainsString('flock -n 9', $this->script);
        $this->assertStringContainsString('/var/log/zemyna/deploy.log', $this->config);
    }

    public function testConfigurationUsesValidatedVmPathsWithoutSecrets(): void
    {
        foreach (
            [
                'REPO_DIR=/var/www/html',
                'APP_DIR=/var/www/html/03-proyecto-zemyna/programacion',
                'DEPLOY_USER=zentrax',
                'EXPECTED_ORIGIN=https://github.com/Zentrax-sas/zentrax-project.git',
                'COMPOSE_PROJECT_NAME=zemyna_compose_test',
                'COMPOSE_FILE=compose.yaml',
                'ENV_FILE=.env.docker',
                'APP_HEALTH_URL=http://127.0.0.1:8080/frontend/public/landing.html',
            ] as $contract
        ) {
            $this->assertStringContainsString($contract, $this->config);
        }
        $this->assertStringNotContainsString('PASSWORD=', $this->config);
        $this->assertStringNotContainsString('TOKEN=', $this->config);
    }

    public function testGitUpdateRunsAsDeployUserAndOnlyFastForwards(): void
    {
        foreach (
            [
                'remote get-url origin',
                'status --porcelain --untracked-files=normal',
                'runuser -u "$DEPLOY_USER" -- git',
                'fetch origin "$branch"',
                'pull --ff-only origin "$branch"',
                'commit_anterior=',
                'commit_nuevo=',
            ] as $contract
        ) {
            $this->assertStringContainsString($contract, $this->script);
        }
    }

    public function testComposeIsValidatedDeployedAndHealthChecked(): void
    {
        foreach (
            [
                'docker info',
                'docker compose version',
                'config --quiet',
                'up -d --build',
                'service_is_healthy db',
                'service_is_healthy app',
                'service_is_healthy phpmyadmin',
                '"$APP_HEALTH_URL"',
            ] as $contract
        ) {
            $this->assertStringContainsString($contract, $this->script);
        }
    }

    public function testHttpCheckRequiresLocalUrlWithRealPath(): void
    {
        $this->assertStringContainsString(': "${APP_HEALTH_URL:=}"', $this->script);
        $this->assertStringContainsString(
            '^http://(127\\.0\\.0\\.1|localhost):[0-9]{1,5}/[^[:space:]]+$',
            $this->script
        );
        $this->assertStringContainsString(
            'curl --fail --silent --show-error --location --max-time 15',
            $this->script
        );
        $this->assertStringNotContainsString(
            'http://127.0.0.1:8080/ >/dev/null',
            $this->script
        );
        $this->assertStringContainsString('403', $this->documentation);
        $this->assertStringContainsString('200', $this->documentation);
        $this->assertStringContainsString('frontend/public/landing.html', $this->documentation);
    }

    public function testScriptContainsNoForbiddenDeploymentOperations(): void
    {
        foreach (
            [
                '/\bsudo\b/',
                '/\bdocker\s+compose\s+down\b/',
                '/\bdown\s+-v\b/',
                '/\bprune\b/',
                '/\bgit\s+[^\n]*(reset|checkout)\b/',
                '/\b(dnf|yum|apt-get)\b/',
                '/\b(mariadb|mysql)\b/',
            ] as $pattern
        ) {
            $this->assertDoesNotMatchRegularExpression($pattern, $this->script);
        }
    }

    public function testDocumentationInstallsAndExplainsSafeOperation(): void
    {
        foreach (
            [
                '/usr/local/bin/deploy_zemyna.sh',
                '/etc/zemyna/deploy.conf',
                'root -g root -m 600',
                'primera versión reproducible',
                'script.sh',
                'start.sh',
                'stop.sh',
                '/usr/local/bin/deploy.sh',
                'Nunca ejecutar `docker compose down -v`',
            ] as $contract
        ) {
            $this->assertStringContainsStringIgnoringCase($contract, $this->documentation);
        }
        $this->assertMatchesRegularExpression(
            '/no es un sistema de alta\s+disponibilidad/i',
            $this->documentation
        );
    }
}
