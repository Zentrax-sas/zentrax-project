<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../models/Foto.php';

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

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $allowedMimeTypes, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Solo se permiten imágenes JPG, PNG o WEBP.'
    ]);
    exit;
}

$extension = match ($mime) {
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    default => 'jpg'
};

$uploadDir = __DIR__ . '/../uploads/incidencias/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'No se pudo crear la carpeta de uploads.'
    ]);
    exit;
}

$fileName = 'incidencia_' . (int)$_POST['id_incidencia'] . '_' . uniqid('', true) . '.' . $extension;
$targetPath = $uploadDir . $fileName;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'No se pudo guardar la imagen en el servidor.'
    ]);
    exit;
}

$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/backend/api/foto.php';
$basePath = preg_replace('#/backend/api/foto\.php$#', '', $scriptName);
$publicUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $basePath . '/uploads/incidencias/' . $fileName;

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'No se pudo conectar a la base de datos.'
    ]);
    exit;
}

$foto = new Foto($db);
$foto->fecha = date('Y-m-d');
$foto->url = $publicUrl;
$foto->id_incidencia = (int)$_POST['id_incidencia'];

if ($foto->create()) {
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Foto adjuntada correctamente.',
        'data' => [
            'id_incidencia' => (int)$_POST['id_incidencia'],
            'url' => $publicUrl
        ]
    ]);
    exit;
}

http_response_code(500);
echo json_encode([
    'success' => false,
    'message' => 'La foto se subió al servidor, pero no se pudo guardar en la base de datos.'
]);
