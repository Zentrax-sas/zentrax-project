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

    public static function extensionMatchesMime(string $originalName, string $mime): bool
    {
        $extension = strtolower(pathinfo(basename($originalName), PATHINFO_EXTENSION));
        $allowed = match ($mime) {
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/webp' => ['webp'],
            default => [],
        };
        return in_array($extension, $allowed, true);
    }

    public static function isAllowedSize(int $size): bool
    {
        return $size > 0 && $size <= self::MAX_FILE_SIZE;
    }

    public static function uploadErrorDetails(int $error): array
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => ['statusCode' => 413, 'message' => 'La foto debe pesar como máximo 5 MB.'],
            UPLOAD_ERR_PARTIAL => ['statusCode' => 400, 'message' => 'La foto se recibió de forma incompleta. Intentá adjuntarla nuevamente.'],
            UPLOAD_ERR_NO_FILE => ['statusCode' => 400, 'message' => 'No se adjuntó ninguna foto.'],
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => ['statusCode' => 500, 'message' => 'No se pudo guardar la imagen en el servidor.'],
            default => ['statusCode' => 400, 'message' => 'La foto no pudo subirse correctamente.'],
        };
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
