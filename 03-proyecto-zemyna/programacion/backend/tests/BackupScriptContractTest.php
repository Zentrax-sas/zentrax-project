<?php

use PHPUnit\Framework\TestCase;

final class BackupScriptContractTest extends TestCase
{
    private string $script;
    private string $configExample;
    private string $documentation;

    protected function setUp(): void
    {
        $operationsRoot = dirname(__DIR__, 2) . '/../sistemas-operativos';
        $this->script = (string) file_get_contents($operationsRoot . '/scripts/backup_zemyna.sh');
        $this->configExample = (string) file_get_contents(
            $operationsRoot . '/config/backup.conf.example'
        );
        $this->documentation = (string) file_get_contents($operationsRoot . '/README.md');
    }

    public function testUsesStrictModeLockAndAtomicPublication(): void
    {
        $this->assertStringContainsString('set -Eeuo pipefail', $this->script);
        $this->assertStringContainsString('flock -n 9', $this->script);
        $this->assertStringContainsString('mktemp', $this->script);
        $this->assertStringContainsString('mv -- "$TEMP_FILE" "$FINAL_FILE"', $this->script);
        $this->assertStringContainsString("trap 'cleanup \"$?\"' EXIT", $this->script);
    }

    public function testRunsCompleteDumpInsideComposeDatabaseService(): void
    {
        foreach (
            [
                'docker compose',
                '--project-name "$COMPOSE_PROJECT_NAME"',
                '--env-file "$ENV_FILE_PATH"',
                'exec -T db',
                'mariadb-dump',
                '--single-transaction',
                '--routines',
                '--triggers',
                '--events',
                'MARIADB_DATABASE',
                'MARIADB_USER',
                'MARIADB_PASSWORD',
            ] as $contract
        ) {
            $this->assertStringContainsString($contract, $this->script);
        }
    }

    public function testValidatesProtectsAndRotatesOnlyValidBackups(): void
    {
        foreach (
            [
                'gzip -t -- "$1"',
                '/^CREATE TABLE /',
                '/^INSERT INTO /',
                'chmod 700',
                'chmod 600',
                "-name 'zemyna_*.sql.gz'",
                '-mtime "+$RETENTION_DAYS"',
                'is_valid_backup "$old_backup"',
            ] as $contract
        ) {
            $this->assertStringContainsString($contract, $this->script);
        }
    }

    public function testExampleExposesConfigurationWithoutSecrets(): void
    {
        foreach (
            [
                'PROJECT_DIR',
                'COMPOSE_PROJECT_NAME',
                'COMPOSE_FILE',
                'ENV_FILE',
                'BACKUP_DIR',
                'LOG_FILE',
                'RETENTION_DAYS',
            ] as $variable
        ) {
            $this->assertMatchesRegularExpression('/^' . $variable . '=/m', $this->configExample);
        }

        $this->assertStringNotContainsString('PASSWORD=', $this->configExample);
        $this->assertStringNotContainsString('DB_PASS=', $this->configExample);
    }

    public function testDocumentationUsesPrivateConfigAndFourHourlyCron(): void
    {
        $this->assertStringContainsString('/etc/zemyna/backup.conf', $this->documentation);
        $this->assertStringContainsString('install -m 600', $this->documentation);
        $this->assertStringContainsString(
            '0 */4 * * * /usr/local/bin/backup_zemyna.sh',
            $this->documentation
        );
    }
}
