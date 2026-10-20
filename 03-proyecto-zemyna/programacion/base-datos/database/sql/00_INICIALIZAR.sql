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
-- La referencia original v2-v14 describe el alcance historico de este maestro.
-- Actualmente SOURCE lee los archivos vigentes: schema.sql incorpora estructuras
-- hasta V21 e init.sql incluye el catalogo V19. No es un actualizador incremental.
-- Las migraciones historicas se revisan para instalaciones antiguas; no encadenarlas
-- automaticamente tras el esquema final ni ejecutar este maestro sobre datos existentes.
-- Para Docker, el procedimiento oficial es programacion/compose.yaml y README-Docker.md:
-- monta directamente schema.sql e init.sql en un volumen de datos nuevo.
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
