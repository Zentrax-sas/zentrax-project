-- v15: habilita solicitudes públicas desde la app móvil sin eliminar datos.
-- Ejecutar una sola vez luego de migration_v14_foto_ruta_relativa.sql.

ALTER TABLE solicitud
    MODIFY COLUMN email VARCHAR(100) NULL,
    MODIFY COLUMN telefono VARCHAR(20) NULL,
    ADD COLUMN tipo_residuo_solicitado VARCHAR(60) NULL AFTER tipo_solicitud,
    ADD COLUMN foto_url VARCHAR(255) NULL AFTER tipo_residuo_solicitado;

INSERT INTO tipo_residuo (nombre, descripcion)
SELECT 'Otros', 'Otros residuos informados por la ciudadanía.'
WHERE NOT EXISTS (
    SELECT 1 FROM tipo_residuo WHERE nombre = 'Otros'
);

-- Verificación: SHOW CREATE TABLE solicitud;
-- Las columnas nuevas son nullable para conservar solicitudes existentes.