<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../../backend/config/database.php';
$db=(new Database())->getConnection();
if (!$db) throw new RuntimeException('No hay conexión disponible.');
foreach (['incidencia','solicitud'] as $tabla) {
    $columnas=$db->query("SHOW COLUMNS FROM `$tabla`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('updated_at',$columnas,true)) $db->exec("ALTER TABLE `$tabla` ADD COLUMN updated_at DATETIME(6) NULL");
    if (!in_array('revision',$columnas,true)) $db->exec("ALTER TABLE `$tabla` ADD COLUMN revision INT UNSIGNED NOT NULL DEFAULT 0");
}
$db->exec('CREATE TABLE IF NOT EXISTS tramite_edicion (tracking_number VARCHAR(32) PRIMARY KEY, token_hash CHAR(64) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
echo "Migración de edición aplicada sin modificar registros existentes.\n";
