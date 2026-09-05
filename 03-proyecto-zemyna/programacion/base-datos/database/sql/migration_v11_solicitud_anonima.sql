-- ZEMYNA v11: solicitudes públicas sin vínculo obligatorio con vecino.
-- Objetivo: persistir un tracking único y eliminar solicitud.ci y su FK.
-- Precondición: verificar la tabla y la FK fk_solicitud_vecino.
-- Respaldo: mysqldump --single-transaction gestion_residuosfinal solicitud > solicitud_backup.sql

ALTER TABLE solicitud
    ADD COLUMN tracking_number VARCHAR(20) NULL AFTER id_solicitud;

UPDATE solicitud
SET tracking_number = CONCAT('REF-2026-', LPAD(UPPER(HEX(id_solicitud)), 5, '0'))
WHERE tracking_number IS NULL;

ALTER TABLE solicitud
    MODIFY COLUMN tracking_number VARCHAR(20) NOT NULL,
    ADD UNIQUE KEY uk_solicitud_tracking_number (tracking_number),
    DROP FOREIGN KEY fk_solicitud_vecino,
    DROP COLUMN ci;

-- Verificación:
-- SHOW CREATE TABLE solicitud;
-- SELECT COUNT(*) AS solicitudes, COUNT(DISTINCT tracking_number) AS trackings_unicos FROM solicitud;
--
-- Rollback: restaurar la tabla completa desde solicitud_backup.sql. La columna
-- eliminada contenía asociaciones históricas y no debe reconstruirse inventando CI.
