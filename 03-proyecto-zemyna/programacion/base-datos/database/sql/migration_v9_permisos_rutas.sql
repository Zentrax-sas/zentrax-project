-- migration_v9_permisos_rutas.sql
-- Agrega permisos de rutas sin modificar ni eliminar datos existentes.

START TRANSACTION;

INSERT INTO permiso (nombre, descripcion) VALUES
    ('ruta.consultar', 'Consultar rutas de recolección'),
    ('ruta.crear', 'Crear rutas de recolección'),
    ('ruta.modificar', 'Modificar rutas de recolección'),
    ('ruta.baja', 'Dar de baja rutas de recolección')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM rol r
JOIN permiso p ON p.nombre IN (
    'ruta.consultar',
    'ruta.crear',
    'ruta.modificar',
    'ruta.baja'
)
WHERE r.nombre = 'RESPONSABLE_SECTORIAL'
ON DUPLICATE KEY UPDATE id_permiso = VALUES(id_permiso);

INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM rol r
JOIN permiso p ON p.nombre IN (
    'ruta.consultar',
    'ruta.crear',
    'ruta.modificar'
)
WHERE r.nombre = 'ADMINISTRATIVO_OPERATIVO'
ON DUPLICATE KEY UPDATE id_permiso = VALUES(id_permiso);

INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM rol r
JOIN permiso p ON p.nombre = 'ruta.consultar'
WHERE r.nombre IN ('OPERARIO', 'INSPECTOR')
ON DUPLICATE KEY UPDATE id_permiso = VALUES(id_permiso);

COMMIT;