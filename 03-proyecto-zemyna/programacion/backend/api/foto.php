<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../models/Foto.php';
require_once __DIR__ . '/../helpers/FotoStorage.php';

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
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido.'
    ]);
    exit;
}

if (!isset($_POST['id_incidencia']) || !is_numeric($_POST['id_incidencia'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Falta el id_incidencia de la incidencia.'
    ]);
    exit;
}

if (!isset($_FILES['foto']) || $_FILES['foto']['error'] === UPLOAD_ERR_NO_FILE) {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'No se adjuntó ninguna foto.',
        'data' => ['id_incidencia' => (int)$_POST['id_incidencia']]
    ]);
    exit;
}

$file = $_FILES['foto'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'La foto no pudo subirse correctamente.'
    ]);
    exit;
}

if (!FotoStorage::isAllowedSize((int)$file['size'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'La foto debe pesar como máximo 5 MB.'
    ]);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$extension = is_string($mime) ? FotoStorage::extensionForMime($mime) : null;
if ($extension === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Solo se permiten imágenes JPG, PNG o WEBP.'
    ]);
    exit;
}

$uploadDir = __DIR__ . '/../uploads/incidencias/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'No se pudo crear la carpeta de uploads.'
    ]);
    exit;
}

$fileName = 'incidencia_' . (int)$_POST['id_incidencia'] . '_' . bin2hex(random_bytes(16)) . '.' . $extension;
$uploadRoot = realpath($uploadDir);
if ($uploadRoot === false || !FotoStorage::isSafeFileName($fileName)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No se pudo preparar el almacenamiento de la imagen.']);
    exit;
}
$targetPath = $uploadRoot . DIRECTORY_SEPARATOR . basename($fileName);

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'No se pudo guardar la imagen en el servidor.'
    ]);
    exit;
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    unlink($targetPath);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'No se pudo conectar a la base de datos.'
    ]);
    exit;
}

$foto = new Foto($db);
$foto->fecha = date('Y-m-d');
$foto->url = $fileName;
$foto->id_incidencia = (int)$_POST['id_incidencia'];

try {
    $created = $foto->create();
} catch (PDOException $exception) {
    $created = false;
}

if ($created) {
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Foto adjuntada correctamente.',
        'data' => [
            'id_incidencia' => (int)$_POST['id_incidencia'],
            'id_foto' => (int)$foto->id_foto,
            'url' => FotoStorage::downloadUrl((int)$foto->id_foto)
        ]
    ]);
    exit;
}

unlink($targetPath);
http_response_code(500);
echo json_encode([
    'success' => false,
    'message' => 'La foto se subió al servidor, pero no se pudo guardar en la base de datos.'
]);
