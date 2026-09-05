-- init.sql = datos demo, ejecutar después de schema.sql
-- Datos iniciales para entorno de desarrollo/demo de Zemyna.
-- Ejecutar únicamente después de schema.sql.

USE gestion_residuosfinal;


-- =====================================
-- TIPOS DE RESIDUO
-- =====================================

INSERT INTO tipo_residuo (nombre, descripcion) VALUES
('Orgánico', 'Residuos de origen biológico: restos de comida, hojas, etc.'),
('Papel y cartón', 'Papeles, diarios, cartones limpios y secos.'),
('Plástico', 'Envases plásticos, botellas PET, tapas y bolsas.'),
('Vidrio', 'Botellas y envases de vidrio.'),
('Metal', 'Latas y otros residuos metálicos.'),
('Electrónicos', 'Equipos y componentes electrónicos.'),
('Pilas y baterías', 'Pilas y baterías usadas.'),
('Escombros', 'Restos de obras y construcción.'),
('Residuos voluminosos', 'Muebles y objetos de gran tamaño.');


-- =====================================
-- RUTAS
-- =====================================

INSERT INTO ruta (nombre, zona) VALUES
('Ruta Norte', 'Zona norte de la ciudad'),
('Ruta Centro', 'Zona céntrica y microcentro'),
('Ruta Sur', 'Zona sur de la ciudad');


-- =====================================
-- CENTROS
-- =====================================

INSERT INTO centro (nombre, direccion, telefono) VALUES
('Centro de Acopio Norte', 'Av. Gral. Rivera 1500', '099-100100'),
('Vertedero Municipal Sur', 'Camino Maldonado km 12', '099-200200'),
('Centro de Acopio Este', 'Av. Italia 3200', '099-300300');


-- =====================================
-- ACOPIOS
-- =====================================

INSERT INTO acopio (id_centro, horario_atencion) VALUES
(1, 'Lunes a viernes 08:00-17:00'),
(3, 'Lunes a sábado 07:00-15:00');


-- =====================================
-- VERTEDEROS
-- =====================================

INSERT INTO vertedero (id_centro, capacidad_maxima) VALUES
(2, 50000.00);


-- =====================================
-- VECINOS
-- =====================================

INSERT INTO vecino (ci, nombre, apellido, telefono) VALUES
('12345678', 'Carlos', 'García', '092-111111'),
('87654321', 'Laura', 'Rodríguez', '092-222222'),
('11223344', 'Martín', 'López', '092-333333');


-- =====================================
-- SECTORES Y PERMISOS
-- =====================================

INSERT INTO sector (nombre, descripcion) VALUES
('TI', 'Tecnología e infraestructura del sistema'),
('LOGISTICA', 'Gestión de vehículos, rutas y recorridos'),
('MANTENIMIENTO', 'Mantenimiento y reparación de recursos'),
('PUNTOS_Y_DESTINOS', 'Contenedores, centros, acopios y destinos'),
('OPERACIONES', 'Organización operativa diaria y tareas'),
('INSPECCION', 'Inspección, evidencias y observaciones')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

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

-- =====================================
-- ROLES
-- =====================================

INSERT INTO rol (nombre, descripcion) VALUES
('ADMINISTRADOR_TI', 'Responsable de usuarios, accesos y permisos del sistema'),
('RESPONSABLE_SECTORIAL', 'Administra recursos y permisos de su sector'),
('ADMINISTRATIVO_OPERATIVO', 'Organiza tareas, asignaciones y recorridos diarios'),
('OPERARIO', 'Ejecuta tareas operativas y registra incidencias'),
('INSPECTOR', 'Consulta estados, verifica situaciones y registra observaciones')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM rol r
JOIN permiso p ON p.nombre IN (
    'usuario.crear', 'usuario.consultar', 'usuario.modificar', 'usuario.suspender',
    'usuario.asignar_rol', 'usuario.asignar_sector', 'contenedor.crear',
    'contenedor.consultar', 'contenedor.modificar', 'contenedor.baja', 'contenedor.cambiar_estado',
    'vehiculo.crear', 'vehiculo.consultar', 'vehiculo.modificar', 'vehiculo.baja', 'vehiculo.cambiar_estado',
    'vehiculo.asignar', 'lugar.crear', 'lugar.consultar', 'lugar.modificar', 'lugar.baja', 'lugar.cambiar_estado',
    'maquinaria.crear', 'maquinaria.consultar', 'maquinaria.modificar', 'maquinaria.baja', 'maquinaria.cambiar_estado',
    'incidencia.crear', 'incidencia.consultar', 'incidencia.modificar', 'incidencia.adjuntar_evidencia'
)
WHERE r.nombre = 'ADMINISTRADOR_TI'
ON DUPLICATE KEY UPDATE id_permiso = VALUES(id_permiso);

INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM rol r
JOIN permiso p ON p.nombre IN (
    'contenedor.consultar', 'contenedor.crear', 'contenedor.modificar', 'contenedor.baja', 'contenedor.cambiar_estado',
    'vehiculo.consultar', 'vehiculo.crear', 'vehiculo.modificar', 'vehiculo.baja', 'vehiculo.cambiar_estado',
    'vehiculo.asignar', 'lugar.consultar', 'lugar.crear', 'lugar.modificar', 'lugar.baja', 'lugar.cambiar_estado',
    'maquinaria.consultar', 'maquinaria.crear', 'maquinaria.modificar', 'maquinaria.baja', 'maquinaria.cambiar_estado',
    'incidencia.crear', 'incidencia.consultar', 'incidencia.modificar', 'incidencia.adjuntar_evidencia'
)
WHERE r.nombre = 'RESPONSABLE_SECTORIAL'
ON DUPLICATE KEY UPDATE id_permiso = VALUES(id_permiso);

INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM rol r
JOIN permiso p ON p.nombre IN (
    'contenedor.consultar', 'vehiculo.consultar', 'vehiculo.asignar', 'lugar.consultar',
    'incidencia.crear', 'incidencia.consultar', 'incidencia.modificar'
)
WHERE r.nombre = 'ADMINISTRATIVO_OPERATIVO'
ON DUPLICATE KEY UPDATE id_permiso = VALUES(id_permiso);

INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM rol r
JOIN permiso p ON p.nombre IN (
    'contenedor.consultar', 'contenedor.cambiar_estado', 'vehiculo.consultar', 'maquinaria.consultar',
    'incidencia.crear', 'incidencia.consultar', 'incidencia.adjuntar_evidencia'
)
WHERE r.nombre = 'OPERARIO'
ON DUPLICATE KEY UPDATE id_permiso = VALUES(id_permiso);

INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM rol r
JOIN permiso p ON p.nombre IN (
    'contenedor.consultar', 'vehiculo.consultar', 'maquinaria.consultar',
    'incidencia.crear', 'incidencia.consultar', 'incidencia.adjuntar_evidencia'
)
WHERE r.nombre = 'INSPECTOR'
ON DUPLICATE KEY UPDATE id_permiso = VALUES(id_permiso);


-- =====================================
-- USUARIOS
-- =====================================

INSERT INTO usuario
(nombre, apellido, email, contrasena, telefono, fecha_registro, id_centro, activo)
VALUES
(
    'Sistema',
    'Zemyna',
    'sistemas@zemyna.com',
    '$2y$10$tW6nYGKQ0jUzBRA3mBQu9OjNvsxGHSHjVu4X7a0qExal1iEncxOnm',
    '091-000000',
    '2025-01-01',
    1,
    'Activo'
),
(
    'Facundo',
    'Pérez',
    'facu@zemyna.com',
    '$2y$10$tW6nYGKQ0jUzBRA3mBQu9OjNvsxGHSHjVu4X7a0qExal1iEncxOnm',
    '091-001001',
    '2025-01-10',
    1,
    'Activo'
),
(
    'Diego',
    'Suárez',
    'diego@zemyna.com',
    '$2y$10$tW6nYGKQ0jUzBRA3mBQu9OjNvsxGHSHjVu4X7a0qExal1iEncxOnm',
    '091-002002',
    '2025-02-15',
    1,
    'Activo'
),
(
    'Andrea',
    'Méndez',
    'andrea@zemyna.com',
    '$2y$10$tW6nYGKQ0jUzBRA3mBQu9OjNvsxGHSHjVu4X7a0qExal1iEncxOnm',
    '091-003003',
    '2025-03-20',
    2,
    'Activo'
);


-- =====================================
-- ASIGNACIÓN DE ROLES
-- =====================================

INSERT INTO usuario_rol
(id_usuario, id_rol, sector, fecha_desde, fecha_hasta)
SELECT u.id_usuario, r.id_rol, 'TI', '2025-01-01', NULL
FROM usuario u
JOIN rol r ON r.nombre = 'ADMINISTRADOR_TI'
WHERE u.email = 'sistemas@zemyna.com';

INSERT INTO usuario_rol
(id_usuario, id_rol, sector, fecha_desde, fecha_hasta)
SELECT u.id_usuario, r.id_rol, 'OPERACIONES', '2025-01-10', NULL
FROM usuario u
JOIN rol r ON r.nombre = 'ADMINISTRATIVO_OPERATIVO'
WHERE u.email = 'facu@zemyna.com';

INSERT INTO usuario_rol
(id_usuario, id_rol, sector, fecha_desde, fecha_hasta)
SELECT u.id_usuario, r.id_rol, 'OPERACIONES', '2025-02-15', NULL
FROM usuario u
JOIN rol r ON r.nombre = 'OPERARIO'
WHERE u.email = 'diego@zemyna.com';

INSERT INTO usuario_rol
(id_usuario, id_rol, sector, fecha_desde, fecha_hasta)
SELECT u.id_usuario, r.id_rol, 'INSPECCION', '2025-03-20', NULL
FROM usuario u
JOIN rol r ON r.nombre = 'INSPECTOR'
WHERE u.email = 'andrea@zemyna.com';


-- =====================================
-- CONTENEDORES
-- =====================================

INSERT INTO contenedor
(codigo, capacidad, direccion, latitud, longitud, estado,
 id_tipo_residuo, id_ruta)
VALUES
(
    'CTN-001',
    2400,
    'Av. Brasil y Lázaro Gadea',
    -34.9142000,
    -56.1495000,
    'Disponible',
    1,
    1
),
(
    'CTN-002',
    3200,
    'Brito del Pino y Charrúa',
    -34.9210000,
    -56.1585000,
    'Lleno',
    2,
    1
),
(
    'CTN-003',
    2400,
    'Av. 18 de Julio y Tacuarí',
    -34.9065000,
    -56.1852000,
    'Disponible',
    3,
    2
);


-- =====================================
-- CUADRILLAS
-- =====================================

INSERT INTO cuadrilla
(nombre, turno, id_centro)
VALUES
('Cuadrilla Alpha', 'Matutino', 1),
('Cuadrilla Beta', 'Vespertino', 2);


-- =====================================
-- VEHÍCULOS
-- =====================================

INSERT INTO vehiculo
(matricula, marca, modelo, capacidad_carga, estado, id_tipo_residuo)
VALUES
('ABC1234', 'Mercedes-Benz', 'Atego 1725', 8.50, 'Disponible', 1),
('XYZ5678', 'Volvo', 'FE 280', 6.00, 'En Servicio', 2),
('MNO9012', 'Scania', 'P 360', 10.00, 'Disponible', 3);


-- =====================================
-- MAQUINARIA
-- =====================================

INSERT INTO maquinaria
(nombre, tipo, estado, id_centro)
VALUES
('Prensadora P-01', 'Prensadora', 'Disponible', 1),
('Trituradora T-01', 'Trituradora', 'En Mantenimiento', 2);


-- =====================================
-- USA
-- CUADRILLA + VEHÍCULO
-- =====================================

INSERT INTO usa
(id_cuadrilla, id_vehiculo)
VALUES
(1, 1),
(1, 2),
(2, 3);


-- =====================================
-- RECORRIDOS
-- =====================================

INSERT INTO recorrido
(fecha_inicio, fecha_fin, estado, id_ruta)
VALUES
(
    '2025-06-01 08:00:00',
    '2025-06-01 13:00:00',
    'Finalizado',
    1
),
(
    '2025-06-02 08:00:00',
    NULL,
    'En Proceso',
    2
);


-- =====================================
-- PARTICIPACIÓN EN RECORRIDOS
-- =====================================

INSERT INTO participa
(id_usa, id_recorrido, hora_inicio, hora_fin, motivo_fin)
VALUES
(
    1,
    1,
    '08:00:00',
    '13:00:00',
    'Fin del recorrido'
),
(
    3,
    2,
    '08:00:00',
    NULL,
    NULL
);


-- =====================================
-- INCIDENCIAS
-- =====================================

INSERT INTO incidencia
(tracking_number, descripcion, fecha_reporte, estado, prioridad, tipo_problema,
 id_contenedor, id_ruta, id_cuadrilla, id_usuario)
VALUES
(
    'INC-001',
    'Contenedor dañado, tapa rota.',
    '2025-06-01 09:00:00',
    'Pendiente',
    'Alta',
    'Contenedor Roto/Dañado',
    1,
    NULL,
    1,
    3
),
(
    'INC-002',
    'Contenedor desbordado, necesita vaciado.',
    '2025-06-02 11:30:00',
    'En Proceso',
    'Media',
    'Contenedor Desbordado',
    2,
    NULL,
    2,
    4
),
(
    'INC-003',
    'Residuos obstruyen parte de la ruta.',
    '2025-06-03 10:15:00',
    'Pendiente',
    'Media',
    'Obstrucción en ruta',
    NULL,
    1,
    1,
    3
);


-- =====================================
-- DENUNCIAS
-- =====================================

INSERT INTO denuncia
(fecha, descripcion, ci, id_incidencia)
VALUES
(
    '2025-06-01 10:00:00',
    'El contenedor de mi cuadra está roto desde hace días.',
    '12345678',
    1
),
(
    '2025-06-02 12:00:00',
    'Hay residuos en la vereda por desbordamiento.',
    '87654321',
    2
),
(
    '2025-06-03 10:30:00',
    'Hay residuos que dificultan el paso por la ruta.',
    '11223344',
    3
);


-- =====================================
-- FOTOS
-- =====================================

INSERT INTO foto
(fecha, url, id_incidencia)
VALUES
('2025-06-01', '/uploads/incidencias/inc1_foto1.jpg', 1),
('2025-06-02', '/uploads/incidencias/inc2_foto1.jpg', 2);


-- =====================================
-- SOLICITUDES
-- =====================================

INSERT INTO solicitud
(tracking_number, fecha, descripcion, direccion, estado,
 id_tipo_residuo, email, telefono, tipo_solicitud)
VALUES
(
    'REF-2025-00001',
    '2025-06-05 08:00:00',
    'Retiro de un mueble de gran tamaño.',
    'Dr. Luis Bonavita 1294',
    'Pendiente',
    9,
    'martin@gmail.com',
    '092-333333',
    'Gran volumen'
),
(
    'REF-2025-00002',
    '2025-06-06 09:30:00',
    'Gran cantidad de cartones para retirar.',
    'Paraguay 1450',
    'Programada',
    2,
    'carlos@gmail.com',
    '092-111111',
    'Reciclables'
);


-- =====================================
-- MANTENIMIENTOS
-- Solo vehículo o maquinaria
-- =====================================

INSERT INTO mantenimiento
(fecha_inicio, fecha_fin, estado, tipo, descripcion,
 id_vehiculo, id_maquinaria)
VALUES
(
    '2025-06-10 08:00:00',
    NULL,
    'En Proceso',
    'Preventivo',
    'Revisión general del vehículo.',
    1,
    NULL
),
(
    '2025-06-12 10:00:00',
    NULL,
    'Pendiente',
    'Preventivo',
    'Mantenimiento general de la maquinaria.',
    NULL,
    1
);
