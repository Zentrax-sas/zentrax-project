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

header('Cache-Control: no-store');
$contentLength = filter_input(INPUT_SERVER, 'CONTENT_LENGTH', FILTER_VALIDATE_INT);
if (is_int($contentLength) && $contentLength > FotoStorage::MAX_FILE_SIZE + 65536) {
    sendFotoJson(413, false, 'La foto debe pesar como máximo 5 MB.');
    exit;
}

$db = (new Database())->getConnection();
if (!$db) { sendFotoJson(500, false, 'No se pudo guardar la fotografía.'); exit; }
require_once __DIR__ . '/../controllers/FotoUploadController.php';
$sessionId = $_SESSION['usuario']['id_usuario'] ?? null;
$sessionId = filter_var($sessionId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$response = (new FotoUploadController($db, __DIR__ . '/../uploads/incidencias'))->upload(
    $_POST, isset($_FILES['foto']) && is_array($_FILES['foto']) ? $_FILES['foto'] : null, $sessionId
);
http_response_code($response['statusCode']);
echo json_encode($response);
