<?php

class FotoStorage
{
    public const MAX_FILE_SIZE = 5 * 1024 * 1024;
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public static function extensionForMime(string $mime): ?string
    {
        return self::MIME_EXTENSIONS[$mime] ?? null;
    }

    public static function isAllowedSize(int $size): bool
    {
        return $size > 0 && $size <= self::MAX_FILE_SIZE;
    }

    public static function extractSafeFileName(string $storedValue): ?string
    {
        $value = trim($storedValue);
        if ($value === '' || str_contains($value, "\0")) return null;

        $path = parse_url($value, PHP_URL_PATH);
        if (!is_string($path) || $path === '') $path = $value;
        $path = str_replace('\\', '/', rawurldecode($path));
        if (in_array('..', explode('/', $path), true)) return null;
        $name = basename($path);

        return self::isSafeFileName($name) ? $name : null;
    }

    public static function isSafeFileName(string $fileName): bool
    {
        return $fileName !== ''
            && $fileName === basename($fileName)
            && !str_contains($fileName, '..')
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.(?:jpg|jpeg|png|webp)$/i', $fileName) === 1;
    }

    public static function resolveExistingPath(string $uploadDirectory, string $fileName): ?string
    {
        if (!self::isSafeFileName($fileName)) return null;

        $base = realpath($uploadDirectory);
        if ($base === false || !is_dir($base)) return null;
        $candidate = realpath($base . DIRECTORY_SEPARATOR . basename($fileName));
        if ($candidate === false || !is_file($candidate)) return null;

        return realpath(dirname($candidate)) === $base ? $candidate : null;
    }

    public static function downloadUrl(int $idFoto): string
    {
        return 'foto.php?id=' . $idFoto;
    }
}
