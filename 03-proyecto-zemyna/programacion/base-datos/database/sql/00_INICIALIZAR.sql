-- ================================================================
-- ZEMYNA - INSTALACION NUEVA MINIMA
-- ================================================================
-- ADVERTENCIA: schema.sql elimina y recrea todas las tablas de la base
-- seleccionada. Este maestro no crea, elimina ni selecciona una base por nombre.
-- El operador debe crear el destino y pasarlo expresamente con --database.
--
-- Ejecutar desde este directorio y detener ante el primer error:
--   mysql --abort-source-on-error -u USUARIO -p \
--     --database=gestion_residuosfinal < 00_INICIALIZAR.sql
--
-- Este flujo nuevo contiene el estado final de las migraciones v2-v14. Las
-- migraciones se conservan solamente para actualizar instalaciones antiguas y
-- NO deben ejecutarse despues de schema.sql.
-- ================================================================

SELECT CONCAT('Instalacion minima en base seleccionada: ', DATABASE()) AS destino;

SOURCE ./schema.sql;
SOURCE ./init.sql;

SELECT 'instalacion_minima_ok' AS resultado,
       (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE()) AS tablas,
       (SELECT COUNT(*) FROM usuario) AS usuarios,
       (SELECT COUNT(*) FROM rol) AS roles,
       (SELECT COUNT(*) FROM contenedor) AS contenedores;

-- CARGA MASIVA OPCIONAL (comando separado, una sola vez):
--   mysql --abort-source-on-error -u USUARIO -p \
--     --database=gestion_residuosfinal < seed_contenedores_idm.sql
--
-- El seed conserva los tres contenedores demo y agrega 11.211 contenedores de
-- datos abiertos, para un total esperado de 11.214. Una segunda ejecucion del
-- seed falla de forma visible antes de escribir por sus controles de colision.
