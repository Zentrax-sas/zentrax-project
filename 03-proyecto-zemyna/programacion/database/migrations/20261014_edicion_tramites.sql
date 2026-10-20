-- Actualización aditiva. fecha_reporte/fecha son las fechas de creación existentes.
ALTER TABLE incidencia ADD COLUMN updated_at DATETIME(6) NULL, ADD COLUMN revision INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE solicitud ADD COLUMN updated_at DATETIME(6) NULL, ADD COLUMN revision INT UNSIGNED NOT NULL DEFAULT 0;
CREATE TABLE tramite_edicion (
 tracking_number VARCHAR(32) PRIMARY KEY,
 token_hash CHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
