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

    $rolActual = $_SESSION['usuario']['rol'] ?? null;
    if ($rolActual === null || !in_array($rolActual, $rolesPermitidos, true)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'No tenés permiso para esta acción.'
        ]);
        exit;
    }
}
