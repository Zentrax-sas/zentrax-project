<?php
require_once __DIR__ . '/../models/IncidenciaUploadToken.php';
require_once __DIR__ . '/../models/Foto.php';
require_once __DIR__ . '/../helpers/FotoStorage.php';

/** Coordina BD y limpieza compensatoria del archivo. */
class FotoUploadController {
    private Closure $move;
    public function __construct(private PDO $db, private string $directory, ?Closure $move = null) {
        $this->move = $move ?? static fn($source, $target) => move_uploaded_file($source, $target);
    }

    private function failure(int $code, string $message): array {
        return ['success' => false, 'statusCode' => $code, 'data' => null, 'message' => $message, 'errors' => []];
    }

    public function upload(array $body, ?array $file, ?int $sessionUser): array {
        $id = $body['id_incidencia'] ?? null;
        $secret = $body['upload_token'] ?? null;
        if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]*$/D', (string)$id)
            || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) === false
            || !is_string($secret) || !preg_match('/^[a-f0-9]{64}$/D', $secret)) {
            return $this->failure(400, 'Se requieren id_incidencia y upload_token válidos.');
        }
        if (!$file) return $this->failure(400, 'No se adjuntó ninguna foto.');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = FotoStorage::uploadErrorDetails((int)($file['error'] ?? UPLOAD_ERR_NO_FILE));
            return $this->failure($error['statusCode'], $error['message']);
        }
        if (!FotoStorage::isAllowedSize((int)($file['size'] ?? 0))) return $this->failure(413, 'La foto debe pesar como máximo 5 MB.');
        $source = $file['tmp_name'] ?? null;
        if (!is_string($source) || !is_file($source)) return $this->failure(400, 'La foto no está disponible.');
        clearstatcache(true, $source);
        $actualSize = filesize($source);
        if ($actualSize === false || !FotoStorage::isAllowedSize($actualSize)) return $this->failure(413, 'La foto debe pesar como máximo 5 MB.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($source);
        $extension = is_string($mime) ? FotoStorage::extensionForMime($mime) : null;
        if ($extension === null || !is_string($file['name'] ?? null) || !FotoStorage::extensionMatchesMime($file['name'], $mime)) {
            return $this->failure(400, 'Solo se permiten imágenes JPG, PNG o WEBP.');
        }
        $targetPath = null;
        try {
            $this->db->beginTransaction();
            $tokens = new IncidenciaUploadToken($this->db);
            $grant = $tokens->authorize($secret, (int)$id, $sessionUser);
            if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) throw new RuntimeException('Almacenamiento no disponible.');
            $root = realpath($this->directory);
            $name = 'incidencia_' . (int)$grant['id_incidencia'] . '_' . bin2hex(random_bytes(16)) . '.' . $extension;
            if ($root === false || !FotoStorage::isSafeFileName($name)) throw new RuntimeException('Almacenamiento no disponible.');
            $targetPath = $root . DIRECTORY_SEPARATOR . basename($name);
            if (!($this->move)($source, $targetPath)) throw new RuntimeException('No se pudo mover la fotografía.');
            $photo = new Foto($this->db);
            $photo->fecha = IncidenciaUploadToken::now()->format('Y-m-d');
            $photo->url = $name;
            $photo->id_incidencia = (int)$grant['id_incidencia'];
            if (!$photo->create()) throw new PDOException('No se pudo persistir la fotografía.');
            $tokens->consume($grant, (int)$photo->id_foto);
            $this->db->commit();
            return ['success' => true, 'statusCode' => 201, 'message' => 'Foto adjuntada correctamente.', 'errors' => [],
                'data' => ['id_incidencia' => (int)$photo->id_incidencia, 'id_foto' => (int)$photo->id_foto, 'url' => FotoStorage::downloadUrl($photo->id_foto)]];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($targetPath !== null && is_file($targetPath)) @unlink($targetPath);
            if ($error instanceof DomainException) return $this->failure($error->getCode(), $error->getMessage());
            return $this->failure(500, 'No se pudo guardar la fotografía. La incidencia conserva su número de seguimiento.');
        }
    }
}
