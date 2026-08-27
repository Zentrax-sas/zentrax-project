<?php

function normalizeRoleName(?string $role): string {
    if ($role === null) {
        return '';
    }

    $normalizado = trim($role);
    $normalizado = str_replace([' ', '-', '.'], '_', $normalizado);
    $normalizado = preg_replace('/_+/', '_', $normalizado);
    $normalizado = strtoupper((string) $normalizado);

    $mapeo = [
        'SUPERUSUARIO' => 'ADMINISTRADOR_TI',
        'ADMIN' => 'ADMINISTRADOR_TI',
        'ADMINISTRADOR' => 'ADMINISTRADOR_TI',
        'ADMINISTRATIVO' => 'ADMINISTRADOR_TI',
        'RESPONSABLE' => 'RESPONSABLE_SECTORIAL',
        'RESPONSABLE_SECTORIAL' => 'RESPONSABLE_SECTORIAL',
        'ADMINISTRATIVO_OPERATIVO' => 'ADMINISTRATIVO_OPERATIVO',
        'OPERARIO' => 'OPERARIO',
        'INSPECTOR' => 'INSPECTOR',
        'TI' => 'ADMINISTRADOR_TI',
        'PUNTOS_Y_DESTINOS' => 'PUNTOS_Y_DESTINOS',
        'OPERACIONES' => 'OPERACIONES',
        'MANTENIMIENTO' => 'MANTENIMIENTO',
        'LOGISTICA' => 'LOGISTICA',
        'INSPECCION' => 'INSPECCION',
    ];

    return $mapeo[$normalizado] ?? $normalizado;
}

function normalizePermissionName(?string $permiso): string {
    if ($permiso === null) {
        return '';
    }

    return trim($permiso);
}

function normalizeRoleList(?array $roles): array {
    if (!is_array($roles)) {
        return [];
    }

    $normalizados = [];
    foreach ($roles as $rol) {
        $rolNormalizado = normalizeRoleName((string) $rol);
        if ($rolNormalizado !== '') {
            $normalizados[] = $rolNormalizado;
        }
    }

    return array_values(array_unique($normalizados));
}

function requireAuth(): void {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
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

    $rolesUsuario = normalizeRoleList($_SESSION['usuario']['roles'] ?? []);
    $rolesPermitidosNormalizados = normalizeRoleList($rolesPermitidos);

    $tienePermiso = !empty(array_intersect($rolesUsuario, $rolesPermitidosNormalizados));

    if (!$tienePermiso) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'No tenés permiso para esta acción.'
        ]);
        exit;
    }
}

function hasEffectivePermission(string $permiso, ?array $sectoresPermitidos = null): bool {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }

    if (empty($_SESSION['usuario'])) {
        return false;
    }

    $usuario = $_SESSION['usuario'];
    $rolesUsuario = normalizeRoleList($usuario['roles'] ?? []);
    $autorizaciones = $usuario['autorizaciones'] ?? [];
    $permisoBuscado = normalizePermissionName($permiso);
    $sectoresNormalizados = $sectoresPermitidos !== null ? array_map('strtoupper', array_map('trim', $sectoresPermitidos)) : null;

    if (in_array('ADMINISTRADOR_TI', $rolesUsuario, true) && $sectoresPermitidos !== null) {
        return true;
    }

    foreach ($autorizaciones as $autorizacion) {
        if (normalizePermissionName($autorizacion['permiso'] ?? null) !== $permisoBuscado) {
            continue;
        }

        if ($sectoresNormalizados === null) {
            return true;
        }

        $sectorActual = strtoupper(trim((string) ($autorizacion['sector'] ?? '')));
        if (in_array($sectorActual, $sectoresNormalizados, true)) {
            return true;
        }
    }

    return false;
}

function requirePermission(string $permiso, ?array $sectoresPermitidos = null): void {
    requireAuth();

    if (!hasEffectivePermission($permiso, $sectoresPermitidos)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'No tenés permiso para esta acción.'
        ]);
        exit;
    }
}