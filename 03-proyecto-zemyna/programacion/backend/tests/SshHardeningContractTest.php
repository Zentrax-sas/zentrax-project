<?php

use PHPUnit\Framework\TestCase;

final class SshHardeningContractTest extends TestCase
{
    private string $configuration;
    private string $documentation;

    protected function setUp(): void
    {
        $operationsRoot = dirname(__DIR__, 2) . '/../sistemas-operativos';
        $this->configuration = str_replace(
            "\r\n",
            "\n",
            (string) file_get_contents(
                $operationsRoot . '/config/sshd/00-zemyna-hardening.conf'
            )
        );
        $this->documentation = (string) file_get_contents($operationsRoot . '/README.md');
    }

    public function testDropInContainsOnlyValidatedHardeningOptions(): void
    {
        $this->assertSame(
            "PermitRootLogin no\n"
            . "PasswordAuthentication no\n"
            . "KbdInteractiveAuthentication no\n"
            . "PubkeyAuthentication yes\n",
            $this->configuration
        );
        $this->assertStringNotContainsString('Port ', $this->configuration);
    }

    public function testDocumentationInstallsDropInSecurelyAndValidatesBeforeReload(): void
    {
        foreach (
            [
                '00-zemyna-hardening.conf',
                '01-permitrootlogin.conf',
                'install -o root -g root -m 600',
                'sshd -t',
                'systemctl reload sshd',
                'sshd -T',
            ] as $contract
        ) {
            $this->assertStringContainsString($contract, $this->documentation);
        }
    }

    public function testDocumentationRequiresSecondSessionAndSafeRollback(): void
    {
        foreach (
            [
                'PreferredAuthentications=publickey',
                'BatchMode=yes',
                'PubkeyAuthentication=no',
                'No cerrar la sesión original',
                '00-zemyna-hardening.conf.rollback',
            ] as $contract
        ) {
            $this->assertStringContainsString($contract, $this->documentation);
        }
    }

    public function testDocumentationDoesNotEmbedKeysOrPrivateAddresses(): void
    {
        $this->assertStringNotContainsString('BEGIN OPENSSH PRIVATE KEY', $this->documentation);
        $this->assertStringNotContainsString('ssh-rsa ', $this->documentation);
        $this->assertStringNotContainsString('ssh-ed25519 ', $this->documentation);
        $this->assertDoesNotMatchRegularExpression(
            '/\b(?:10\.|192\.168\.|172\.(?:1[6-9]|2[0-9]|3[01])\.)/',
            $this->documentation
        );
    }
}
