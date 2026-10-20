-- V20: sólo esquema. Sin backfill ni concesiones para incidencias existentes.
CREATE TABLE IF NOT EXISTS incidencia_upload_token (
    token_hash BINARY(32) NOT NULL PRIMARY KEY,
    id_incidencia INT NOT NULL,
    id_usuario_emisor INT DEFAULT NULL,
    fecha_creacion DATETIME NOT NULL,
    fecha_expiracion DATETIME NOT NULL,
    fecha_consumo DATETIME DEFAULT NULL,
    id_foto INT DEFAULT NULL,
    KEY idx_iut_incidencia (id_incidencia),
    KEY idx_iut_expiracion (fecha_expiracion),
    UNIQUE KEY uk_iut_foto (id_foto),
    CONSTRAINT fk_iut_incidencia FOREIGN KEY (id_incidencia) REFERENCES incidencia(id_incidencia) ON DELETE CASCADE,
    CONSTRAINT fk_iut_emisor FOREIGN KEY (id_usuario_emisor) REFERENCES usuario(id_usuario) ON DELETE RESTRICT,
    -- Borrar una foto no vuelve utilizable su concesión consumida.
    CONSTRAINT fk_iut_foto FOREIGN KEY (id_foto) REFERENCES foto(id_foto) ON DELETE SET NULL,
    CONSTRAINT chk_iut_expiracion CHECK (fecha_expiracion > fecha_creacion),
    -- MariaDB 10.11 rejects CHECK on a FK column with ON DELETE SET NULL.
    -- Temporal validity stays declarative; triggers enforce the photo condition.
    CONSTRAINT chk_iut_consumo CHECK ((fecha_consumo IS NULL)
        OR (fecha_consumo IS NOT NULL AND fecha_consumo >= fecha_creacion AND fecha_consumo < fecha_expiracion))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_iut_foto_insert
BEFORE INSERT ON incidencia_upload_token FOR EACH ROW
BEGIN
    IF NEW.fecha_consumo IS NULL AND NEW.id_foto IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una foto requiere una concesion consumida.';
    END IF;
END$$
CREATE TRIGGER IF NOT EXISTS trg_iut_foto_update
BEFORE UPDATE ON incidencia_upload_token FOR EACH ROW
BEGIN
    IF NEW.fecha_consumo IS NULL AND NEW.id_foto IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una foto requiere una concesion consumida.';
    END IF;
END$$
DELIMITER ;
