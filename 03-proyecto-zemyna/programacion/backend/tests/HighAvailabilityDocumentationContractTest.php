<?php

use PHPUnit\Framework\TestCase;

final class HighAvailabilityDocumentationContractTest extends TestCase
{
    private string $document;
    private string $readme;

    protected function setUp(): void
    {
        $operationsRoot = dirname(__DIR__, 2) . '/../sistemas-operativos';
        $document = (string) file_get_contents(
            $operationsRoot . '/ALTA_DISPONIBILIDAD.md'
        );
        $this->document = (string) preg_replace('/\s+/u', ' ', $document);
        $this->readme = (string) file_get_contents($operationsRoot . '/README.md');
    }

    public function testSeparatesCurrentDisasterRecoveryProposedHaAndLimitations(): void
    {
        foreach (
            [
                '## 1. Estado actual implementado',
                '## 2. Recuperación ante desastres',
                '## 3. Alta disponibilidad propuesta para producción',
                '## 4. Pendientes y limitaciones',
            ] as $heading
        ) {
            $this->assertStringContainsString($heading, $this->document);
        }
    }

    public function testDoesNotConfuseBackupsOrRestartPolicyWithHa(): void
    {
        $this->assertMatchesRegularExpression(
            '/La\s+alta disponibilidad \*\*no está implementada actualmente\*\*/u',
            $this->document
        );
        $this->assertMatchesRegularExpression(
            '/Google Drive\s+aporta una copia externa y no alta disponibilidad/u',
            $this->document
        );
        $this->assertStringContainsString(
            'no resuelve la caída completa de la VM',
            $this->document
        );
        $this->assertStringContainsString(
            'Los backups permiten recuperación, pero no continuidad inmediata',
            $this->document
        );
    }

    public function testDocumentsThreeTwoOneAndMeasuredBoundaries(): void
    {
        foreach (
            [
                'Regla 3-2-1 aplicada',
                'RPO local máximo teórico:** 4 horas',
                'RPO offsite máximo teórico:** 24 horas',
                'RTO:** todavía no medido formalmente',
                'aproximadamente **364 KB comprimido**',
            ] as $contract
        ) {
            $this->assertStringContainsString($contract, $this->document);
        }
    }

    public function testProposedArchitectureIncludesRequiredRedundancy(): void
    {
        foreach (
            [
                'balanceador o proxy inverso',
                'al menos dos nodos de aplicación',
                'sesiones compartidas o aplicación sin estado',
                'almacenamiento compartido o replicado para uploads',
                'MariaDB primaria y réplica, o un clúster con quorum',
                'failover documentado y probado',
                'monitorización, métricas y alertas',
                'réplica del balanceador',
            ] as $component
        ) {
            $this->assertStringContainsStringIgnoringCase($component, $this->document);
        }
    }

    public function testDefinesBackupTypesFailureResponsesAndAcademicScope(): void
    {
        foreach (
            [
                '**Completo:**',
                '**Incremental:**',
                '**Diferencial:**',
                '### Fallos y respuesta',
                '### Fases realistas',
                '### Justificación académica',
                'una única VM',
            ] as $contract
        ) {
            $this->assertStringContainsStringIgnoringCase($contract, $this->document);
        }
    }

    public function testReadmeLinksDocumentWithoutClaimingHaIsImplemented(): void
    {
        $this->assertStringContainsString(
            '[`ALTA_DISPONIBILIDAD.md`](ALTA_DISPONIBILIDAD.md)',
            $this->readme
        );
        $this->assertStringContainsString(
            'pero no con alta disponibilidad',
            $this->readme
        );
    }
}
