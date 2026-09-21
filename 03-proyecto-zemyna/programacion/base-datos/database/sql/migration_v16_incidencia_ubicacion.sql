-- MariaDB 10.4. Verificar respaldo completo antes de ejecutar: DDL con commit implícito.
-- No reconstruye ubicaciones históricas ni infiere puntos a partir de rutas.
ALTER TABLE incidencia
    ADD COLUMN IF NOT EXISTS latitud DECIMAL(10,7) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS longitud DECIMAL(10,7) NULL DEFAULT NULL;
ALTER TABLE incidencia
    DROP CONSTRAINT IF EXISTS chk_incidencia_ambito,
    DROP CONSTRAINT IF EXISTS chk_incidencia_coordenadas,
    ADD CONSTRAINT chk_incidencia_coordenadas CHECK (
        (latitud IS NULL AND longitud IS NULL) OR
        (latitud IS NOT NULL AND longitud IS NOT NULL AND latitud BETWEEN -90 AND 90 AND longitud BETWEEN -180 AND 180)
    ),
    ADD CONSTRAINT chk_incidencia_ambito CHECK (
        (id_contenedor IS NOT NULL AND id_ruta IS NULL) OR
        (id_contenedor IS NULL AND id_ruta IS NOT NULL) OR
        (id_contenedor IS NULL AND id_ruta IS NULL AND latitud IS NOT NULL AND longitud IS NOT NULL)
    );
