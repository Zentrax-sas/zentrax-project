<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'JSON inválido.']);
    exit;
}

$email = trim($body['email'] ?? '');
$password = trim($body['password'] ?? '');

$usuariosDemo = [
    [
        'email' => 'facu@zemyna.com',
        'password' => '123456',
        'nombre' => 'Facundo',
        'rol' => 1
    ],
    [
        'email' => 'diego@zemyna.com',
        'password' => '123456',
        'nombre' => 'Diego',
        'rol' => 3
    ],
      [
        'email' => 'andrea@zemyna.com',
        'password' => '123456',
        'nombre' => 'Andrea',
        'rol' => 2
    ]
];

$usuario = null;
foreach ($usuariosDemo as $item) {
    if ($item['email'] === $email && $item['password'] === $password) {
        $usuario = $item;
        break;
    }
}

if ($usuario) {
    $_SESSION['usuario'] = [
        'email' => $usuario['email'],
        'nombre' => $usuario['nombre'],
        'rol' => $usuario['rol']
    ];

    echo json_encode([
        'success' => true,
        'message' => 'Inicio de sesión correcto.',
        'data' => [
            'nombre' => $usuario['nombre'],
            'email' => $usuario['email']
        ]
    ]);
    exit;
}

http_response_code(401);
echo json_encode([
    'success' => false,
    'message' => 'Credenciales inválidas.',
    'errors' => ['Email o contraseña incorrectos.']
]);
