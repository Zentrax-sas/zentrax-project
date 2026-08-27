-- migration_v10_permisos_operativos.sql
-- Agrega permisos para cuadrillas, mantenimientos y recorridos operativos.

START TRANSACTION;

INSERT INTO permiso (nombre, descripcion) VALUES
    ('cuadrilla.consultar', 'Consultar cuadrillas'),
    ('cuadrilla.crear', 'Crear cuadrillas'),
    ('cuadrilla.modificar', 'Modificar cuadrillas'),
    ('cuadrilla.baja', 'Dar de baja cuadrillas'),
    ('mantenimiento.consultar', 'Consultar mantenimientos'),
    ('mantenimiento.crear', 'Crear mantenimientos'),
    ('mantenimiento.modificar', 'Modificar mantenimientos'),
    ('mantenimiento.baja', 'Dar de baja mantenimientos'),
    ('recorrido.consultar', 'Consultar recorridos'),
    ('recorrido.crear', 'Crear recorridos'),
    ('recorrido.modificar', 'Modificar recorridos'),
    ('recorrido.baja', 'Dar de baja recorridos')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM rol r
JOIN permiso p ON p.nombre IN (
    'cuadrilla.consultar', 'cuadrilla.crear', 'cuadrilla.modificar', 'cuadrilla.baja',
    'mantenimiento.consultar', 'mantenimiento.crear', 'mantenimiento.modificar', 'mantenimiento.baja',
    'recorrido.consultar', 'recorrido.crear', 'recorrido.modificar', 'recorrido.baja'
)
WHERE r.nombre = 'RESPONSABLE_SECTORIAL'
ON DUPLICATE KEY UPDATE id_permiso = VALUES(id_permiso);

INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM rol r
JOIN permiso p ON p.nombre IN (
    'cuadrilla.consultar', 'cuadrilla.crear', 'cuadrilla.modificar',
    'mantenimiento.consultar', 'mantenimiento.crear', 'mantenimiento.modificar',
    'recorrido.consultar', 'recorrido.crear', 'recorrido.modificar'
)
WHERE r.nombre = 'ADMINISTRATIVO_OPERATIVO'
ON DUPLICATE KEY UPDATE id_permiso = VALUES(id_permiso);

INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM rol r
JOIN permiso p ON p.nombre IN ('cuadrilla.consultar', 'mantenimiento.consultar', 'recorrido.consultar')
WHERE r.nombre IN ('OPERARIO', 'INSPECTOR')
ON DUPLICATE KEY UPDATE id_permiso = VALUES(id_permiso);

COMMIT;