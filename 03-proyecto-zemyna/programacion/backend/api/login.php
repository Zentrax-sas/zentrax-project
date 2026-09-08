<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../controllers/LoginController.php';
require_once __DIR__ . '/../helpers/SessionLogger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido.'
    ]);
    exit;
}

$sessionLogger = new SessionLogger();

$body = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    $sessionLogger->log('LOGIN_FAILURE', 'failure');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'JSON inválido.'
    ]);
    exit;
}

$email = trim($body['email'] ?? '');
$password = trim($body['password'] ?? '');

if ($email === '' || $password === '') {
    $sessionLogger->log('LOGIN_FAILURE', 'failure');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Email y contraseña son obligatorios.'
    ]);
    exit;
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    $sessionLogger->log('LOGIN_FAILURE', 'error');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'No se pudo conectar a la base de datos.'
    ]);
    exit;
}

$usuarioModel = new Usuario($db);
$loginController = new LoginController($usuarioModel);
$result = $loginController->authenticate($email, $password);

http_response_code($result['statusCode']);

if ($result['success']) {
    session_regenerate_id(true);
    $_SESSION['usuario'] = $result['sessionUser'];
    $sessionLogger->log(
        'LOGIN_SUCCESS',
        'success',
        (int) $result['sessionUser']['id_usuario'],
        $result['sessionUser']['roles'] ?? []
    );
} else {
    $sessionLogger->log('LOGIN_FAILURE', 'failure');
}

unset($result['statusCode'], $result['sessionUser']);
echo json_encode($result);
