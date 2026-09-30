<?php
/** Traduce únicamente DDL específico MariaDB para fixtures SQLite; conserva CHECK/FK. */
function createAttentionFixture(PDO $db): void
{
    $sql = file_get_contents(__DIR__ . '/../../../base-datos/database/sql/migration_v18_atencion_incidencia.sql');
    $start = strpos($sql, 'CREATE TABLE');
    $sql = substr($sql, $start, strpos($sql, ') ENGINE=', $start) - $start) . ');';
    $sql = str_replace('INT NOT NULL AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/ENUM\([^)]*\)/', 'TEXT', $sql);
    $sql = str_replace('INT GENERATED ALWAYS AS (IF(fecha_cierre IS NULL, id_incidencia, NULL)) PERSISTENT',
        'INT GENERATED ALWAYS AS (CASE WHEN fecha_cierre IS NULL THEN id_incidencia ELSE NULL END) STORED', $sql);
    $sql = str_replace('UNIQUE KEY uq_ai_abierta (incidencia_abierta)', 'UNIQUE (incidencia_abierta)', $sql);
    $sql = preg_replace('/    KEY idx_ai_historial[^\n]*\n/', '', $sql);
    $sql = str_replace('CHAR_LENGTH', 'LENGTH', $sql);
    $db->exec($sql);
}
