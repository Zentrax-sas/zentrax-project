<?php
require_once __DIR__ . '/../models/EdicionTramite.php';
function agregarClaveEdicion(array $response, ?PDO $db): array {
    if (!empty($response['success']) && isset($response['data']['tracking_number']) && $db) {
        try { $response['data']['edicion_token'] = (new EdicionTramite($db))->emitirClave($response['data']['tracking_number']); }
        // La confirmación del alta no depende de la emisión de una clave posterior.
        catch (Throwable $error) { $response['data']['edicion_token'] = null; }
    }
    return $response;
}
