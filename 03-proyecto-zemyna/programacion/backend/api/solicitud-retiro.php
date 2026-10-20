<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/captcha.php';
require_once __DIR__ . '/../helpers/FotoStorage.php';
require_once __DIR__ . '/../controllers/SolicitudRetiroController.php';

function responderSolicitudRetiro(int $statusCode, bool $success, string $message, $data = null, array $errors = []): void {
    http_response_code($statusCode);
    echo json_encode(compact('success', 'data', 'message', 'errors', 'statusCode'));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    responderSolicitudRetiro(405, false, 'Método HTTP no permitido.');
    exit;
}

$contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
if (is_int($contentLength) && $contentLength > FotoStorage::MAX_FILE_SIZE + 65536) {
    responderSolicitudRetiro(413, false, 'La foto debe pesar como máximo 5 MB.');
    exit;
}

if (!validarCaptcha($_POST['captcha_respuesta'] ?? null)) {
    responderSolicitudRetiro(400, false, 'Captcha incorrecto, intentá de nuevo.', null, [
        'captcha_respuesta' => 'Captcha incorrecto o expirado.',
    ]);
    exit;
}

$fotoUrl = null;
$targetPath = null;
if (isset($_FILES['foto']) && (int)($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['foto'];
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $uploadError = FotoStorage::uploadErrorDetails((int)$file['error']);
        responderSolicitudRetiro($uploadError['statusCode'], false, $uploadError['message']);
        exit;
    }
    if (!FotoStorage::isAllowedSize((int)$file['size'])) {
        responderSolicitudRetiro(413, false, 'La foto debe pesar como máximo 5 MB.');
        exit;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
    if ($finfo) finfo_close($finfo);
    $extension = is_string($mime) ? FotoStorage::extensionForMime($mime) : null;
    if ($extension === null || !FotoStorage::extensionMatchesMime((string)($file['name'] ?? ''), $mime)) {
        responderSolicitudRetiro(400, false, 'Solo se permiten imágenes JPG, PNG o WEBP.');
        exit;
    }

    $uploadDir = __DIR__ . '/../uploads/solicitudes/';
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        responderSolicitudRetiro(500, false, 'No se pudo preparar el almacenamiento de la imagen.');
        exit;
    }
    try {
        $fotoUrl = 'retiro_' . bin2hex(random_bytes(16)) . '.' . $extension;
    } catch (Throwable $exception) {
        responderSolicitudRetiro(500, false, 'No se pudo preparar el almacenamiento de la imagen.');
        exit;
    }
    $uploadRoot = realpath($uploadDir);
    if ($uploadRoot === false || !FotoStorage::isSafeFileName($fotoUrl)) {
        responderSolicitudRetiro(500, false, 'No se pudo preparar el almacenamiento de la imagen.');
        exit;
    }
    $targetPath = $uploadRoot . DIRECTORY_SEPARATOR . $fotoUrl;
    if (!is_uploaded_file($file['tmp_name']) || !@move_uploaded_file($file['tmp_name'], $targetPath)) {
        responderSolicitudRetiro(500, false, 'No se pudo guardar la imagen en el servidor.');
        exit;
    }
}

$database = new Database();
$db = $database->getConnection();
$controller = new SolicitudRetiroController($db);
$response = $controller->crear($_POST, $fotoUrl);
require_once __DIR__ . '/../helpers/ClaveEdicion.php';
$response = agregarClaveEdicion($response, $db);
if (!$response['success'] && $targetPath !== null) @unlink($targetPath);
http_response_code($response['statusCode']);
echo json_encode($response);
