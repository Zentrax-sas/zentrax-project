-- Migración aditiva: los trámites existentes quedan sin consentimiento.
ALTER TABLE incidencia
    ADD COLUMN telefono VARCHAR(20) NULL,
    ADD COLUMN consentimiento_whatsapp TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE solicitud
    ADD COLUMN consentimiento_whatsapp TINYINT(1) NOT NULL DEFAULT 0;
