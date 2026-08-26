-- Autorizacion por rol, sector y permiso.
-- Migracion incremental: no elimina ni modifica datos existentes.

CREATE TABLE IF NOT EXISTS sector (
    id_sector INT NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(50) NOT NULL,
    descripcion VARCHAR(150),
    PRIMARY KEY (id_sector),
    UNIQUE (nombre)
);

CREATE TABLE IF NOT EXISTS permiso (
    id_permiso INT NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(100) NOT NULL,
    descripcion VARCHAR(200),
    PRIMARY KEY (id_permiso),
    UNIQUE (nombre)
);

CREATE TABLE IF NOT EXISTS rol_permiso (
    id_rol INT NOT NULL,
    id_permiso INT NOT NULL,
    PRIMARY KEY (id_rol, id_permiso),
    CONSTRAINT fk_rol_permiso_rol
        FOREIGN KEY (id_rol) REFERENCES rol(id_rol),
    CONSTRAINT fk_rol_permiso_permiso
        FOREIGN KEY (id_permiso) REFERENCES permiso(id_permiso)
);

INSERT INTO sector (nombre, descripcion) VALUES
('TI', 'Tecnologia y accesos'),
('LOGISTICA', 'Vehiculos, rutas y recorridos'),
('MANTENIMIENTO', 'Mantenimiento de recursos'),
('PUNTOS_Y_DESTINOS', 'Contenedores, centros y destinos'),
('OPERACIONES', 'Organizacion de tareas operativas'),
('INSPECCION', 'Inspeccion y evidencias')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

INSERT INTO permiso (nombre, descripcion) VALUES
('usuario.crear', 'Crear usuarios'),
('usuario.consultar', 'Consultar usuarios'),
('usuario.modificar', 'Modificar usuarios'),
('usuario.suspender', 'Suspender usuarios'),
('usuario.asignar_rol', 'Asignar roles'),
('usuario.asignar_sector', 'Asignar sectores'),
('contenedor.consultar', 'Consultar contenedores'),
('contenedor.crear', 'Crear contenedores'),
('contenedor.modificar', 'Modificar contenedores'),
('contenedor.baja', 'Dar de baja contenedores'),
('vehiculo.consultar', 'Consultar vehiculos'),
('vehiculo.crear', 'Crear vehiculos'),
('vehiculo.modificar', 'Modificar vehiculos'),
('vehiculo.baja', 'Dar de baja vehiculos'),
('centro.consultar', 'Consultar centros'),
('centro.crear', 'Crear centros'),
('centro.modificar', 'Modificar centros'),
('centro.baja', 'Dar de baja centros'),
('maquinaria.consultar', 'Consultar maquinaria'),
('maquinaria.crear', 'Crear maquinaria'),
('maquinaria.modificar', 'Modificar maquinaria'),
('maquinaria.baja', 'Dar de baja maquinaria')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

INSERT INTO rol (nombre, descripcion) VALUES
('ADMINISTRADOR_TI', 'Administra usuarios y accesos'),
('RESPONSABLE_SECTORIAL', 'Administra recursos de su sector'),
('ADMINISTRATIVO_OPERATIVO', 'Organiza la operacion diaria'),
('OPERARIO', 'Ejecuta tareas operativas'),
('INSPECTOR', 'Consulta y registra inspecciones')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- Compatibilidad conservadora: conserva Superusuario y agrega acceso TI
-- para que el administrador técnico existente pueda operar durante la transición.
INSERT INTO usuario_rol (id_usuario, id_rol, sector, fecha_desde, fecha_hasta)
SELECT ur.id_usuario, nuevo.id_rol, 'TI', CURDATE(), NULL
FROM usuario_rol ur
INNER JOIN rol antiguo ON antiguo.id_rol = ur.id_rol
INNER JOIN rol nuevo ON nuevo.nombre = 'ADMINISTRADOR_TI'
WHERE antiguo.nombre = 'Superusuario'
  AND (ur.fecha_hasta IS NULL OR ur.fecha_hasta >= CURDATE())
  AND NOT EXISTS (
      SELECT 1
      FROM usuario_rol existente
      WHERE existente.id_usuario = ur.id_usuario
        AND existente.id_rol = nuevo.id_rol
        AND existente.sector = 'TI'
        AND existente.fecha_hasta IS NULL
  );

INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM rol r
CROSS JOIN permiso p
WHERE r.nombre = 'ADMINISTRADOR_TI'
  AND p.nombre IN ('usuario.crear', 'usuario.consultar', 'usuario.modificar', 'usuario.suspender', 'usuario.asignar_rol', 'usuario.asignar_sector')
ON DUPLICATE KEY UPDATE id_permiso = VALUES(id_permiso);

INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM rol r
CROSS JOIN permiso p
WHERE r.nombre = 'RESPONSABLE_SECTORIAL'
  AND p.nombre LIKE '%.consultar'
ON DUPLICATE KEY UPDATE id_permiso = VALUES(id_permiso);

INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM rol r
CROSS JOIN permiso p
WHERE r.nombre IN ('Administrador', 'Superusuario')
ON DUPLICATE KEY UPDATE id_permiso = VALUES(id_permiso);
