<?php

use PHPUnit\Framework\TestCase;

final class OffsiteBackupContractTest extends TestCase
{
    private string $script;
    private string $config;
    private string $documentation;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2) . '/../sistemas-operativos';
        $this->script = (string) file_get_contents($root . '/scripts/offsite_backup_zemyna.sh');
        $this->config = (string) file_get_contents($root . '/config/offsite-backup.conf.example');
        $this->documentation = (string) file_get_contents($root . '/README.md');
    }

    public function testUsesPrivateConfigurationStrictModeAndLock(): void
    {
        $this->assertStringContainsString('set -Eeuo pipefail', $this->script);
        $this->assertStringContainsString('/etc/zemyna/offsite-backup.conf', $this->script);
        $this->assertStringContainsString("stat -c '%a'", $this->script);
        $this->assertStringContainsString('flock -n 9', $this->script);
        $this->assertStringContainsString('copia offsite finalizada con error', $this->script);
    }

    public function testValidatesLatestLocalBackupAndCreatesSha256Manifest(): void
    {
        foreach (['-nt "$latest_backup"', 'gzip -t', 'CREATE TABLE', 'INSERT INTO', 'sha256sum'] as $value) {
            $this->assertStringContainsString($value, $this->script);
        }
    }

    public function testUploadsExpectedCalendarCopiesWithoutDestructiveSynchronization(): void
    {
        foreach (['daily/${calendar_date}', "date '+%G-W%V'", "date '+%Y-%m'", 'rclone_copy_pair'] as $value) {
            $this->assertStringContainsString($value, $this->script);
        }
        $this->assertStringContainsString(' copyto ', $this->script);
        $this->assertDoesNotMatchRegularExpression('/\brclone\s+[^\n]*(sync|purge)\b/', $this->script);
    }

    public function testRemoteRetentionIsDisabledAndUsesDriveTrashWhenEnabled(): void
    {
        $this->assertStringContainsString('ENABLE_REMOTE_RETENTION=false', $this->config);
        $this->assertStringContainsString('if [[ "$ENABLE_REMOTE_RETENTION" == "true" ]]', $this->script);
        $this->assertStringContainsString('--drive-use-trash=true', $this->script);
        $this->assertStringContainsString('${REMOTE_NAME}:${REMOTE_BASE_PATH}/${category}', $this->script);
    }

    public function testExampleHasNoCredentialsAndUsesDedicatedRemotePath(): void
    {
        foreach (['RCLONE_CONFIG', 'REMOTE_NAME', 'REMOTE_BASE_PATH', 'LOG_FILE'] as $variable) {
            $this->assertMatchesRegularExpression('/^' . $variable . '=/m', $this->config);
        }
        $this->assertStringNotContainsString('token =', strtolower($this->config));
        $this->assertStringNotContainsString('client_secret', strtolower($this->config));
        $this->assertDoesNotMatchRegularExpression('/^REMOTE_BASE_PATH=\/?$/m', $this->config);
    }

    public function testDocumentationCoversCronRecoveryAndBackupStrategy(): void
    {
        foreach (['30 0 * * * /usr/local/bin/offsite_backup_zemyna.sh', 'sha256sum --check', '3-2-1', 'Incremental', 'Diferencial', 'no alta disponibilidad'] as $value) {
            $this->assertStringContainsStringIgnoringCase($value, $this->documentation);
        }
    }

    public function testDocumentationUsesOfficialRcloneInstallerForRockyLinux(): void
    {
        foreach (
            [
                'sudo -v',
                'curl -fsSLo /tmp/rclone-install.sh https://rclone.org/install.sh',
                'sudo bash /tmp/rclone-install.sh',
                'rm /tmp/rclone-install.sh',
                'rclone version',
            ] as $command
        ) {
            $this->assertStringContainsString($command, $this->documentation);
        }

        $this->assertStringNotContainsString('apt-get install rclone', $this->documentation);
    }

    public function testDocumentationWarnsThatInternalUseOverridesRestoreDestination(): void
    {
        $this->assertStringContainsString(
            'CREATE DATABASE gestion_residuosfinal;',
            $this->documentation
        );
        $this->assertStringContainsString('USE gestion_residuosfinal;', $this->documentation);
        $this->assertStringContainsString('el `USE` interno prevalece', $this->documentation);
        $this->assertStringContainsString(
            'Nunca importar el `.sql.gz` original',
            $this->documentation
        );
    }

    public function testDisposableRestoreValidatesFiltersAndDropsOnlyTestDatabase(): void
    {
        foreach (
            [
                'RESTORE_DB=gestion_residuosfinal_restore_test',
                'RESTORE_DB" == "$PRODUCTION_DB',
                "grep -Ec '^CREATE DATABASE",
                "grep -Ec '^USE ",
                'restore-safe.sql',
                "if grep -Eq '^CREATE DATABASE|^USE '",
                'table_schema = DATABASE()',
                'DROP DATABASE \\`$1\\`',
                'sh "$RESTORE_DB"',
            ] as $contract
        ) {
            $this->assertStringContainsString($contract, $this->documentation);
        }

        $this->assertStringNotContainsString(
            'mariadb gestion_residuosfinal <',
            $this->documentation
        );
    }
}
