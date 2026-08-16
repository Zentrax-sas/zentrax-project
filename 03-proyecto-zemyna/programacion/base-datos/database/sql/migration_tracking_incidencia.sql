-- Agrega seguimiento publico a incidencias existentes.
-- Ejecutar solo en una base ya creada con el schema anterior.

START TRANSACTION;

ALTER TABLE incidencia
    ADD COLUMN tracking_number VARCHAR(20) NULL AFTER id_incidencia;

UPDATE incidencia
SET tracking_number = CONCAT('INC-MIG-', id_incidencia)
WHERE tracking_number IS NULL OR tracking_number = '';

ALTER TABLE incidencia
    MODIFY COLUMN tracking_number VARCHAR(20) NOT NULL,
    ADD UNIQUE KEY uk_incidencia_tracking_number (tracking_number);

COMMIT;