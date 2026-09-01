-- ====================================
-- ZEMYNA - INICIALIZACIÓN DE BASE DE DATOS
-- ====================================
--
-- Este archivo maestro ejecuta en orden:
-- 1. schema.sql       → Estructura y tablas
-- 2. Migraciones      → Actualizaciones incrementales
-- 3. init.sql         → Datos demo iniciales
--
-- INSTRUCCIONES DE USO:
-- Ejecutar en MySQL/MariaDB:
--   mysql -u root -p < 00_INICIALIZAR.sql
--
-- O en MariaDB con contraseña vacía:
--   mariadb -u root < 00_INICIALIZAR.sql
--
-- O copiando el contenido en phpMyAdmin → SQL.
--
-- IMPORTANTE:
-- - No redirige datos existentes si la BD ya existe
-- - Schema.sql incluye DROP DATABASE para limpiar
-- - Migraciones son idempotentes (ON DUPLICATE KEY UPDATE)
-- - Revisar .env para configuración de conexión
--
-- ====================================

-- 1. ESTRUCTURA BASE: Schema oficial Zemyna
SOURCE ./schema.sql;

-- 2. MIGRACIONES INCREMENTALES SEGURAS PARA INSTALACIÓN NUEVA
-- Se omiten migraciones históricas incompatibles con el schema actual
-- y se ejecutan solo las que corresponden al estado final del proyecto.
SOURCE ./migration_v8_roles_genericos.sql;
SOURCE ./migration_v9_permisos_rutas.sql;
SOURCE ./migration_v10_permisos_operativos.sql;
SOURCE ./migration_index_geo.sql;
SOURCE ./migration_tracking_incidencia.sql;

-- 3. DATOS INICIALES: Demo y datos de prueba
SOURCE ./init.sql;

-- ====================================
-- FIN DE INICIALIZACIÓN
-- ====================================
-- Verificar estructura:
-- SELECT COUNT(*) as tablas FROM information_schema.tables WHERE table_schema='gestion_residuosfinal';
-- SELECT COUNT(*) as usuarios FROM usuario;
-- SELECT COUNT(*) as roles FROM rol;
-- SELECT COUNT(*) as permisos FROM permiso;
-- SELECT COUNT(*) as usuarios_rol FROM usuario_rol;
-- ====================================
