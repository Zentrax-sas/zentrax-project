-- V22: compatibilidad movil/local + V21. Aplicar despues de V21 con escritores detenidos.
-- Conserva fechas, autores y filas originales. NULL en origen significa historico no acreditado.
ALTER TABLE incidencia
 ADD COLUMN IF NOT EXISTS telefono VARCHAR(20) NULL,
 ADD COLUMN IF NOT EXISTS consentimiento_whatsapp TINYINT(1) NOT NULL DEFAULT 0,
 ADD COLUMN IF NOT EXISTS fecha_cancelacion DATETIME(6) NULL,
 ADD COLUMN IF NOT EXISTS updated_at DATETIME(6) NULL,
 ADD COLUMN IF NOT EXISTS revision INT UNSIGNED NOT NULL DEFAULT 0,
 ADD COLUMN IF NOT EXISTS origen_cancelacion ENUM('CIUDADANA','OPERATIVA') NULL,
 ADD COLUMN IF NOT EXISTS id_usuario_cancela INT NULL,
 ADD COLUMN IF NOT EXISTS motivo_cancelacion VARCHAR(500) NULL;
ALTER TABLE incidencia MODIFY estado ENUM('Pendiente','En Proceso','Resuelta','Cancelada') NOT NULL;
ALTER TABLE solicitud
 MODIFY email VARCHAR(100) NULL,
 MODIFY telefono VARCHAR(20) NULL,
 MODIFY fecha_cancelacion DATETIME(6) NULL,
 ADD COLUMN IF NOT EXISTS tipo_residuo_solicitado VARCHAR(60) NULL,
 ADD COLUMN IF NOT EXISTS foto_url VARCHAR(255) NULL,
 ADD COLUMN IF NOT EXISTS consentimiento_whatsapp TINYINT(1) NOT NULL DEFAULT 0,
 ADD COLUMN IF NOT EXISTS calle VARCHAR(150) NULL,
 ADD COLUMN IF NOT EXISTS numero_referencia VARCHAR(150) NULL,
 ADD COLUMN IF NOT EXISTS latitud DECIMAL(10,7) NULL,
 ADD COLUMN IF NOT EXISTS longitud DECIMAL(10,7) NULL,
 ADD COLUMN IF NOT EXISTS updated_at DATETIME(6) NULL,
 ADD COLUMN IF NOT EXISTS revision INT UNSIGNED NOT NULL DEFAULT 0,
 ADD COLUMN IF NOT EXISTS origen_cancelacion ENUM('CIUDADANA','OPERATIVA') NULL;
CREATE TABLE IF NOT EXISTS tramite_edicion (
 tracking_number VARCHAR(32) PRIMARY KEY,
 token_hash CHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE solicitud DROP CONSTRAINT IF EXISTS chk_solicitud_cancelacion;
ALTER TABLE solicitud DROP CONSTRAINT IF EXISTS chk_solicitud_cancelacion_origen;
ALTER TABLE solicitud ADD CONSTRAINT chk_solicitud_cancelacion_origen CHECK (
 (origen_cancelacion IS NULL AND id_usuario_cancela IS NULL AND motivo_cancelacion IS NULL
   AND (fecha_cancelacion IS NULL OR (estado='Cancelada' AND fecha_cancelacion>=fecha)))
 OR (origen_cancelacion IS NOT NULL AND estado='Cancelada' AND fecha_cancelacion IS NOT NULL AND fecha_cancelacion>=fecha AND (
   (origen_cancelacion='CIUDADANA' AND id_usuario_cancela IS NULL AND motivo_cancelacion IS NULL)
   OR (origen_cancelacion='OPERATIVA' AND id_usuario_cancela IS NOT NULL AND motivo_cancelacion IS NOT NULL AND CHAR_LENGTH(TRIM(motivo_cancelacion)) BETWEEN 1 AND 500)))
);
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_solicitud_cancelacion_insert
BEFORE INSERT ON solicitud FOR EACH ROW
BEGIN
 IF NEW.estado='Cancelada' AND NEW.origen_cancelacion IS NULL THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Una cancelacion nueva requiere origen acreditado.';
 END IF;
END$$
CREATE TRIGGER IF NOT EXISTS trg_solicitud_cancelacion_update
BEFORE UPDATE ON solicitud FOR EACH ROW
BEGIN
 IF NEW.estado='Cancelada' AND NEW.origen_cancelacion IS NULL AND NOT (
   OLD.estado='Cancelada' AND OLD.origen_cancelacion IS NULL
   AND NEW.fecha_cancelacion <=> OLD.fecha_cancelacion
   AND NEW.id_usuario_cancela <=> OLD.id_usuario_cancela
   AND NEW.motivo_cancelacion <=> OLD.motivo_cancelacion) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='No se puede fabricar una cancelacion historica.';
 END IF;
END$$
DELIMITER ;
ALTER TABLE incidencia DROP CONSTRAINT IF EXISTS chk_incidencia_cancelacion_origen;
ALTER TABLE incidencia ADD CONSTRAINT chk_incidencia_cancelacion_origen CHECK (
 (origen_cancelacion IS NULL AND id_usuario_cancela IS NULL AND motivo_cancelacion IS NULL
   AND (fecha_cancelacion IS NULL OR (estado='Cancelada' AND fecha_cancelacion>=fecha_reporte)))
 OR (origen_cancelacion IS NOT NULL AND estado='Cancelada' AND fecha_cancelacion IS NOT NULL AND fecha_cancelacion>=fecha_reporte AND (
   (origen_cancelacion='CIUDADANA' AND id_usuario_cancela IS NULL AND motivo_cancelacion IS NULL)
   OR (origen_cancelacion='OPERATIVA' AND id_usuario_cancela IS NOT NULL AND motivo_cancelacion IS NOT NULL AND CHAR_LENGTH(TRIM(motivo_cancelacion)) BETWEEN 1 AND 500)))
);
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_incidencia_cancelacion_insert
BEFORE INSERT ON incidencia FOR EACH ROW
BEGIN
 IF NEW.estado='Cancelada' AND NEW.origen_cancelacion IS NULL THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Una cancelacion nueva requiere origen acreditado.';
 END IF;
END$$
CREATE TRIGGER IF NOT EXISTS trg_incidencia_cancelacion_update
BEFORE UPDATE ON incidencia FOR EACH ROW
BEGIN
 IF NEW.estado='Cancelada' AND NEW.origen_cancelacion IS NULL AND NOT (
   OLD.estado='Cancelada' AND OLD.origen_cancelacion IS NULL
   AND NEW.fecha_cancelacion <=> OLD.fecha_cancelacion
   AND NEW.id_usuario_cancela <=> OLD.id_usuario_cancela
   AND NEW.motivo_cancelacion <=> OLD.motivo_cancelacion) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='No se puede fabricar una cancelacion historica.';
 END IF;
END$$
DELIMITER ;
ALTER TABLE incidencia ADD CONSTRAINT fk_incidencia_cancela FOREIGN KEY (id_usuario_cancela) REFERENCES usuario(id_usuario) ON DELETE RESTRICT;

-- Extension local ya existente en la copia real; preserva direcciones completas.
ALTER TABLE geocodificacion_cache MODIFY COLUMN direccion TEXT NOT NULL;
