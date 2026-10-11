<?php
// Prueba HTTP exclusivamente anónima: no configura ni abre conexiones de BD.
ini_set('session.use_cookies', '0');
session_save_path(sys_get_temp_dir());
session_id('f52' . bin2hex(random_bytes(16)));
$sessionFile = session_save_path() . '/sess_' . session_id();
register_shutdown_function(static function () use ($sessionFile): void {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (is_file($sessionFile)) unlink($sessionFile);
});
require __DIR__ . '/../../api/ruta_apoyo.php';
