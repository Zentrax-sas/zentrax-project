-- migration_v8_roles_genericos.sql
-- Normaliza roles, sectores y permisos al modelo pedido por la segunda entrega.
-- No elimina datos existentes ni reemplaza los roles viejos; solo agrega la estructura genérica y deja compatibilidad.

START TRANSACTION;

-- Sectores genéricos solicitados por el prompt.
INSERT INTO sector (nombre, descripcion) VALUES
    ('TI', 'Tecnología e infraestructura del sistema'),
    ('LOGISTICA', 'Gestión de vehículos, recorridos y rutas'),
    ('MANTENIMIENTO', 'Mantenimiento y reparación de recursos'),
    ('PUNTOS_Y_DESTINOS', 'Contenedores, centros, acopios y destinos'),
    ('OPERACIONES', 'Organización operativa diaria y tareas'),
    ('INSPECCION', 'Inspección, evidencias y observaciones')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- Permisos genéricos por dominio.
INSERT INTO permiso (nombre, descripcion) VALUES
    ('usuario.crear', 'Crear usuarios'),
    ('usuario.consultar', 'Consultar usuarios'),
    ('usuario.modificar', 'Modificar usuarios'),
    ('usuario.suspender', 'Suspender usuarios'),
    ('usuario.asignar_rol', 'Asignar roles'),
    ('usuario.asignar_sector', 'Asignar sectores'),
    ('contenedor.crear', 'Crear contenedores'),
    ('contenedor.consultar', 'Consultar contenedores'),
    ('contenedor.modificar', 'Modificar contenedores'),
    ('contenedor.baja', 'Dar de baja contenedores'),
    ('contenedor.cambiar_estado', 'Cambiar estado operativo del contenedor'),
    ('vehiculo.crear', 'Crear vehículos'),
    ('vehiculo.consultar', 'Consultar vehículos'),
    ('vehiculo.modificar', 'Modificar vehículos'),
    ('vehiculo.baja', 'Dar de baja vehículos'),
    ('vehiculo.cambiar_estado', 'Cambiar estado del vehículo'),
    ('vehiculo.asignar', 'Asignar vehículos a tareas o recorridos'),
    ('lugar.crear', 'Crear lugares o centros'),
    ('lugar.consultar', 'Consultar lugares o centros'),
    ('lugar.modificar', 'Modificar lugares o centros'),
    ('lugar.baja', 'Dar de baja lugares o centros'),
    ('lugar.cambiar_estado', 'Cambiar estado del lugar'),
    ('maquinaria.crear', 'Crear maquinaria'),
    ('maquinaria.consultar', 'Consultar maquinaria'),
    ('maquinaria.modificar', 'Modificar maquinaria'),
    ('maquinaria.baja', 'Dar de baja maquinaria'),
    ('maquinaria.cambiar_estado', 'Cambiar estado de la maquinaria'),
    ('incidencia.crear', 'Crear incidencias'),
    ('incidencia.consultar', 'Consultar incidencias'),
    ('incidencia.modificar', 'Modificar incidencias'),
    ('incidencia.adjuntar_evidencia', 'Adjuntar evidencia a incidencias')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- Roles recomendados por el prompt.
INSERT INTO rol (nombre, descripcion) VALUES
    ('ADMINISTRADOR_TI', 'Responsable de usuarios, accesos y permisos del sistema'),
    ('RESPONSABLE_SECTORIAL', 'Administra recursos y permisos de su sector'),
    ('ADMINISTRATIVO_OPERATIVO', 'Organiza tareas, asignaciones y recorridos diarios'),
    ('OPERARIO', 'Ejecuta tareas, inicia cierres y registra incidencias'),
    ('INSPECTOR', 'Consulta estados, verifica situaciones y registra observaciones')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- Compatibilidad: asocia usuarios antiguos a nuevos roles según el nombre previo.
INSERT INTO usuario_rol (id_usuario, id_rol, sector, fecha_desde, fecha_hasta)
SELECT ur.id_usuario,
       r_nuevo.id_rol,
       CASE
           WHEN r_antiguo.nombre = 'Superusuario' THEN 'TI'
           WHEN r_antiguo.nombre = 'Administrador' THEN 'OPERACIONES'
           ELSE 'GENERAL'
       END AS sector,
       CURDATE(),
       NULL
FROM usuario_rol ur
INNER JOIN rol r_antiguo ON r_antiguo.id_rol = ur.id_rol
INNER JOIN rol r_nuevo ON r_nuevo.nombre = CASE
    WHEN r_antiguo.nombre = 'Superusuario' THEN 'ADMINISTRADOR_TI'
    WHEN r_antiguo.nombre = 'Administrador' THEN 'ADMINISTRATIVO_OPERATIVO'
    ELSE 'OPERARIO'
END
WHERE ur.fecha_hasta IS NULL
  AND NOT EXISTS (
      SELECT 1
      FROM usuario_rol ur_existente
      WHERE ur_existente.id_usuario = ur.id_usuario
        AND ur_existente.id_rol = r_nuevo.id_rol
        AND ur_existente.sector = CASE
            WHEN r_antiguo.nombre = 'Superusuario' THEN 'TI'
            WHEN r_antiguo.nombre = 'Administrador' THEN 'OPERACIONES'
            ELSE 'GENERAL'
        END
        AND ur_existente.fecha_hasta IS NULL
  );

-- Permisos del rol ADMINISTRADOR_TI.
INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM rol r
JOIN permiso p ON p.nombre IN (
    'usuario.crear',
    'usuario.consultar',
    'usuario.modificar',
    'usuario.suspender',
    'usuario.asignar_rol',
    'usuario.asignar_sector'
)
WHERE r.nombre = 'ADMINISTRADOR_TI'
ON DUPLICATE KEY UPDATE id_permiso = VALUES(id_permiso);

-- Permisos del rol RESPONSABLE_SECTORIAL.
INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM rol r
JOIN permiso p ON p.nombre IN (
    'contenedor.consultar',
    'contenedor.crear',
    'contenedor.modificar',
    'contenedor.baja',
    'contenedor.cambiar_estado',
    'vehiculo.consultar',
    'vehiculo.crear',
    'vehiculo.modificar',
    'vehiculo.baja',
    'vehiculo.cambiar_estado',
    'vehiculo.asignar',
    'lugar.consultar',
    'lugar.crear',
    'lugar.modificar',
    'lugar.baja',
    'lugar.cambiar_estado',
    'maquinaria.consultar',
    'maquinaria.crear',
    'maquinaria.modificar',
    'maquinaria.baja',
    'maquinaria.cambiar_estado'
)
WHERE r.nombre = 'RESPONSABLE_SECTORIAL'
ON DUPLICATE KEY UPDATE id_permiso = VALUES(id_permiso);

-- Permisos del rol ADMINISTRATIVO_OPERATIVO.
INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM rol r
JOIN permiso p ON p.nombre IN (
    'contenedor.consultar',
    'vehiculo.consultar',
    'vehiculo.asignar',
    'lugar.consultar',
    'incidencia.crear',
    'incidencia.consultar',
    'incidencia.modificar'
)
WHERE r.nombre = 'ADMINISTRATIVO_OPERATIVO'
ON DUPLICATE KEY UPDATE id_permiso = VALUES(id_permiso);

-- Permisos del rol OPERARIO.
INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM rol r
JOIN permiso p ON p.nombre IN (
    'contenedor.consultar',
    'contenedor.cambiar_estado',
    'vehiculo.consultar',
    'maquinaria.consultar',
    'incidencia.crear',
    'incidencia.consultar',
    'incidencia.adjuntar_evidencia'
)
WHERE r.nombre = 'OPERARIO'
ON DUPLICATE KEY UPDATE id_permiso = VALUES(id_permiso);

-- Permisos del rol INSPECTOR.
INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM rol r
JOIN permiso p ON p.nombre IN (
    'contenedor.consultar',
    'vehiculo.consultar',
    'maquinaria.consultar',
    'incidencia.crear',
    'incidencia.consultar',
    'incidencia.adjuntar_evidencia'
)
WHERE r.nombre = 'INSPECTOR'
ON DUPLICATE KEY UPDATE id_permiso = VALUES(id_permiso);

COMMIT;
