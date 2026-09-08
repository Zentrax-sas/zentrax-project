<?php

require_once __DIR__ . '/auth.php';

final class SessionLogger
{
    private string $directory;
    private int $maxBytes;
    private int $maxArchives;
    private Closure $clock;

    public function __construct(
        ?string $directory = null,
        int $maxBytes = 5242880,
        int $maxArchives = 5,
        ?Closure $clock = null
    ) {
        $this->directory = $directory ?? dirname(__DIR__) . '/sesion';
        $this->maxBytes = max(1, $maxBytes);
        $this->maxArchives = max(0, $maxArchives);
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function log(
        string $event,
        string $result,
        ?int $userId = null,
        array $roles = []
    ): bool {
        try {
            if (!$this->ensureDirectory()) {
                throw new RuntimeException('log_directory_unavailable');
            }

            $line = $this->encodeLine($event, $result, $userId, $roles);
            $lock = @fopen($this->directory . '/.sesiones.lock', 'c');
            if ($lock === false || !@flock($lock, LOCK_EX)) {
                if (is_resource($lock)) {
                    @fclose($lock);
                }
                throw new RuntimeException('log_lock_unavailable');
            }

            try {
                $path = $this->directory . '/sesiones.log';
                clearstatcache(true, $path);
                $size = is_file($path) ? (int) @filesize($path) : 0;
                if ($size > 0 && $size + strlen($line) > $this->maxBytes) {
                    $this->rotate($path);
                }

                if (@file_put_contents($path, $line, FILE_APPEND) === false) {
                    throw new RuntimeException('log_write_failed');
                }
                @chmod($path, 0640);
            } finally {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }

            return true;
        } catch (Throwable $exception) {
            error_log('Zemyna: no se pudo registrar un evento de autenticacion.');
            return false;
        }
    }

    private function ensureDirectory(): bool
    {
        if (is_dir($this->directory)) {
            return is_writable($this->directory);
        }

        return @mkdir($this->directory, 0750, true) || is_dir($this->directory);
    }

    private function encodeLine(string $event, string $result, ?int $userId, array $roles): string
    {
        $now = ($this->clock)();
        $timestamp = $now->setTimezone(new DateTimeZone('America/Montevideo'));
        $normalizedRoles = [];
        foreach ($roles as $role) {
            $normalized = normalizeRoleName(is_array($role) ? ($role['nombre'] ?? '') : (string) $role);
            if ($normalized !== '') {
                $normalizedRoles[] = $normalized;
            }
        }

        $entry = [
            'timestamp' => $timestamp->format(DateTimeInterface::ATOM),
            'event' => strtoupper(trim($event)),
            'result' => strtolower(trim($result)),
            'user_id' => $userId !== null && $userId > 0 ? $userId : null,
            'roles' => array_values(array_unique($normalizedRoles)),
        ];

        $encoded = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('log_encoding_failed');
        }

        return $encoded . PHP_EOL;
    }

    private function rotate(string $path): void
    {
        $now = ($this->clock)()->setTimezone(new DateTimeZone('America/Montevideo'));
        $archive = sprintf(
            '%s/sesiones-%s-%s.log',
            $this->directory,
            $now->format('Ymd-His-u'),
            bin2hex(random_bytes(3))
        );

        if (!@rename($path, $archive)) {
            throw new RuntimeException('log_rotation_failed');
        }
        @chmod($archive, 0640);

        $archives = glob($this->directory . '/sesiones-*.log') ?: [];
        usort($archives, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($archives, $this->maxArchives) as $oldArchive) {
            @unlink($oldArchive);
        }
    }
}
