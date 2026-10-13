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

/** Conexión de la solicitud; permite inyección explícita de PDO en pruebas aisladas. */
final class AuthRequestContext {
    public static ?PDO $connection = null;
    public static ?Closure $resolver = null;
}

function authIdentitySnapshot(): array {
    $actor = $_SESSION['usuario']['id_usuario'] ?? null;
    if ((!is_string($actor) && !is_int($actor)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$actor)
        || (int)$actor > 2147483647) throw new DomainException('No autenticado.', 401);
    try {
        if (AuthRequestContext::$resolver !== null) {
            $user = (AuthRequestContext::$resolver)((int)$actor);
        } else {
            require_once __DIR__ . '/../config/database.php';
            require_once __DIR__ . '/../models/Usuario.php';
            $db = AuthRequestContext::$connection ?? (new Database())->getConnection();
            if (!$db) throw new RuntimeException('Conexión no disponible.');
            // Reutilizar conexión, nunca el resultado de una autorización anterior.
            AuthRequestContext::$connection = $db;
            $user = (new Usuario($db))->getIdentidadAutorizada((int)$actor);
        }
    } catch (Throwable $error) {
        throw new DomainException('No se pudo verificar la autorización. Volvé a intentar.', 503);
    }
    if (!$user) throw new DomainException('Sesión inválida o cuenta inactiva.', 401);
    // Se refresca la presentación, pero nunca se usa este snapshot como autoridad posterior.
    $_SESSION['usuario'] = array_replace($_SESSION['usuario'], $user);
    return $user;
}

function authReject(DomainException $error): void {
    http_response_code($error->getCode());
    echo json_encode(['success' => false, 'data' => null, 'message' => $error->getMessage(),
        'errors' => [], 'statusCode' => $error->getCode()], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Solo logout: cerrar una sesión no depende del estado de la cuenta ni de la BD. */
function requireSessionForLogout(): void {
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

function requireAuth(): void {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) session_start();
    try { authIdentitySnapshot(); }
    catch (DomainException $error) { authReject($error); }
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

    try { $usuario = authIdentitySnapshot(); }
    catch (DomainException $error) { authReject($error); }
    return permissionFromSnapshot($usuario, $permiso, $sectoresPermitidos);
}

/** Evaluación de permisos explícitos y sectores de un snapshot verificado. */
function permissionFromSnapshot(array $usuario, string $permiso, ?array $sectoresPermitidos = null): bool {
    $autorizaciones = $usuario['autorizaciones'] ?? [];
    $permisoBuscado = normalizePermissionName($permiso);
    $sectoresNormalizados = $sectoresPermitidos !== null ? array_map('strtoupper', array_map('trim', $sectoresPermitidos)) : null;

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
