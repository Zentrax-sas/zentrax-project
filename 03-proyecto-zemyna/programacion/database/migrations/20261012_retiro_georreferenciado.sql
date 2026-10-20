-- Históricos sin cambios; nuevas solicitudes móviles requieren un punto confirmado.
ALTER TABLE solicitud
    ADD COLUMN calle VARCHAR(150) NULL,
    ADD COLUMN numero_referencia VARCHAR(150) NULL,
    ADD COLUMN latitud DECIMAL(10,7) NULL,
    ADD COLUMN longitud DECIMAL(10,7) NULL;
