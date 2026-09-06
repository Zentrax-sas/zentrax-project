<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../models/Foto.php';
require_once __DIR__ . '/../helpers/FotoStorage.php';

header('Content-Type: application/json; charset=utf-8');

function sendFotoJson(int $statusCode, bool $success, string $message, $data = null, array $errors = []): void {
    http_response_code($statusCode);
    echo json_encode(compact('success', 'data', 'message', 'errors', 'statusCode'));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requirePermission('incidencia.consultar', ['OPERACIONES', 'INSPECCION', 'PUNTOS_Y_DESTINOS']);

    $id = $_GET['id'] ?? null;
    if (!is_string($id) || preg_match('/^[1-9][0-9]*$/', $id) !== 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'El id_foto debe ser un entero positivo.']);
        exit;
    }

    $db = (new Database())->getConnection();
    if (!$db) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'No se pudo acceder a la evidencia.']);
        exit;
    }

    try {
        $record = (new Foto($db))->findById((int)$id);
    } catch (PDOException $exception) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'No se pudo acceder a la evidencia.']);
        exit;
    }

    $fileName = $record ? FotoStorage::extractSafeFileName((string)$record['url']) : null;
    $filePath = $fileName ? FotoStorage::resolveExistingPath(__DIR__ . '/../uploads/incidencias', $fileName) : null;
    if (!$record || !$filePath) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'La evidencia solicitada no está disponible.']);
        exit;
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
    if (!is_string($mime) || FotoStorage::extensionForMime($mime) === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'La evidencia solicitada no está disponible.']);
        exit;
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($filePath));
    header('Content-Disposition: inline; filename="' . basename($fileName) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($filePath);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendFotoJson(405, false, 'Método no permitido.');
    exit;
}

$contentLength = filter_input(INPUT_SERVER, 'CONTENT_LENGTH', FILTER_VALIDATE_INT);
if (is_int($contentLength) && $contentLength > FotoStorage::MAX_FILE_SIZE + 65536) {
    sendFotoJson(413, false, 'La foto debe pesar como máximo 5 MB.');
    exit;
}

$idIncidencia = $_POST['id_incidencia'] ?? null;
if (!is_string($idIncidencia) || preg_match('/^[1-9][0-9]*$/', $idIncidencia) !== 1) {
    sendFotoJson(400, false, 'Falta el id_incidencia de la incidencia.');
    exit;
}

if (!isset($_FILES['foto']) || !is_array($_FILES['foto'])) {
    sendFotoJson(400, false, 'No se adjuntó ninguna foto.');
    exit;
}

$file = $_FILES['foto'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    $uploadError = FotoStorage::uploadErrorDetails((int)$file['error']);
    sendFotoJson($uploadError['statusCode'], false, $uploadError['message']);
    exit;
}

if (!FotoStorage::isAllowedSize((int)$file['size'])) {
    sendFotoJson(413, false, 'La foto debe pesar como máximo 5 MB.');
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$extension = is_string($mime) ? FotoStorage::extensionForMime($mime) : null;
if ($extension === null || !is_string($file['name'] ?? null) || !FotoStorage::extensionMatchesMime($file['name'], $mime)) {
    sendFotoJson(400, false, 'Solo se permiten imágenes JPG, PNG o WEBP.');
    exit;
}

$uploadDir = __DIR__ . '/../uploads/incidencias/';
if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
    sendFotoJson(500, false, 'No se pudo preparar el almacenamiento de la imagen.');
    exit;
}

try {
    $fileName = 'incidencia_' . (int)$idIncidencia . '_' . bin2hex(random_bytes(16)) . '.' . $extension;
} catch (Throwable $exception) {
    sendFotoJson(500, false, 'No se pudo preparar el almacenamiento de la imagen.');
    exit;
}
$uploadRoot = realpath($uploadDir);
if ($uploadRoot === false || !FotoStorage::isSafeFileName($fileName)) {
    sendFotoJson(500, false, 'No se pudo preparar el almacenamiento de la imagen.');
    exit;
}
$targetPath = $uploadRoot . DIRECTORY_SEPARATOR . basename($fileName);

if (!@move_uploaded_file($file['tmp_name'], $targetPath)) {
    sendFotoJson(500, false, 'No se pudo guardar la imagen en el servidor.');
    exit;
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    @unlink($targetPath);
    sendFotoJson(500, false, 'No se pudo guardar la fotografía.');
    exit;
}

$foto = new Foto($db);
$foto->fecha = date('Y-m-d');
$foto->url = $fileName;
$foto->id_incidencia = (int)$idIncidencia;

try {
    $created = $foto->create();
} catch (PDOException $exception) {
    $created = false;
}

if ($created) {
    sendFotoJson(201, true, 'Foto adjuntada correctamente.', [
        'id_incidencia' => (int)$idIncidencia,
        'id_foto' => (int)$foto->id_foto,
        'url' => FotoStorage::downloadUrl((int)$foto->id_foto)
    ]);
    exit;
}

@unlink($targetPath);
sendFotoJson(500, false, 'La foto se subió al servidor, pero no se pudo guardar en la base de datos.');
