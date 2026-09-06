-- Migración v13: unifica los permisos de centros bajo el dominio canónico lugar.*.
-- Es idempotente y conserva las asignaciones existentes de roles.

START TRANSACTION;

INSERT INTO permiso (nombre, descripcion) VALUES
    ('lugar.crear', 'Crear lugares o centros'),
    ('lugar.consultar', 'Consultar lugares o centros'),
    ('lugar.modificar', 'Modificar lugares o centros'),
    ('lugar.baja', 'Dar de baja lugares o centros'),
    ('lugar.cambiar_estado', 'Cambiar estado del lugar')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT DISTINCT rp.id_rol, canonical.id_permiso
FROM rol_permiso rp
INNER JOIN permiso legacy ON legacy.id_permiso = rp.id_permiso
INNER JOIN permiso canonical ON canonical.nombre = CASE legacy.nombre
    WHEN 'centro.crear' THEN 'lugar.crear'
    WHEN 'centro.consultar' THEN 'lugar.consultar'
    WHEN 'centro.modificar' THEN 'lugar.modificar'
    WHEN 'centro.baja' THEN 'lugar.baja'
END
WHERE legacy.nombre IN ('centro.crear', 'centro.consultar', 'centro.modificar', 'centro.baja')
ON DUPLICATE KEY UPDATE id_permiso = VALUES(id_permiso);

DELETE rp
FROM rol_permiso rp
INNER JOIN permiso p ON p.id_permiso = rp.id_permiso
WHERE p.nombre IN ('centro.crear', 'centro.consultar', 'centro.modificar', 'centro.baja');

DELETE FROM permiso
WHERE nombre IN ('centro.crear', 'centro.consultar', 'centro.modificar', 'centro.baja');

COMMIT;
