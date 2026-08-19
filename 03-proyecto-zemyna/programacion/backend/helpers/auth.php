<?php

function requireAuth(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['usuario'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'No autenticado.'
        ]);
        exit;
    }
}

function requireRole(array $rolesPermitidos): void {
    requireAuth();

    $rolesUsuario = $_SESSION['usuario']['roles'] ?? [];

    if (!is_array($rolesUsuario)) {
        $rolesUsuario = [];
    }

    $tienePermiso = !empty(array_intersect($rolesUsuario, $rolesPermitidos));

    if (!$tienePermiso) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'No tenés permiso para esta acción.'
        ]);
        exit;
    }
}