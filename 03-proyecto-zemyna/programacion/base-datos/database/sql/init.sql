-- init.sql = datos demo, ejecutar después de schema.sql
-- Este archivo solo agrega registros iniciales para entorno de desarrollo o demo.

-- Datos de ejemplo para Zemyna — DER oficial v0.9
-- Respetar orden de inserción por FK

INSERT INTO tipo_residuo (nombre, descripcion) VALUES
('Orgánico',            'Residuos de origen biológico: restos de comida, hojas, etc.'),
('Papel y cartón',      'Papeles, diarios, cartones limpios y secos.'),
('Plástico',            'Envases plásticos, botellas PET, tapas, bolsas.');

-- Rutas
INSERT INTO ruta (nombre, zona) VALUES
('Ruta Norte',  'Zona norte de la ciudad'),
('Ruta Centro', 'Zona céntrica y microcentro');

-- Centros 
INSERT INTO centro (nombre, direccion, telefono) VALUES
('Centro de Acopio Norte',     'Av. Gral. Rivera 1500',  '099-100100'),
('Vertedero Municipal Sur',    'Camino Maldonado km 12', '099-200200'),
('Centro de Acopio Este',      'Av. Italia 3200',        '099-300300');

INSERT INTO acopio (id_centro, horario_atencion) VALUES
(1, 'Lunes a viernes 08:00–17:00'),
(3, 'Lunes a sábado 07:00–15:00');

INSERT INTO vertedero (id_centro, capacidad_maxima) VALUES
(2, 50000.00);

-- Vecinos
INSERT INTO vecino (ci, nombre, apellido, telefono) VALUES
('12345678', 'Carlos',  'García',    '092-111111'),
('87654321', 'Laura',   'Rodríguez', '092-222222'),
('11223344', 'Martín',  'López',     '092-333333');

-- Usuarios (Administradores y Operarios)
INSERT INTO usuario (nombre, apellido, email, contraseña, telefono, fecha_registro, id_centro, activo) VALUES
('Facundo', 'Pérez',    'facu@zemyna.com',   '$2y$10$A32/CEXLhCrbRkgub3SeWeGMtn3.TOB3K/Xivs/DEVdbk0D6Iqxoe', '091-001001', '2025-01-10', 1, 1),
('Diego',   'Suárez',   'diego@zemyna.com',  '$2y$10$A32/CEXLhCrbRkgub3SeWeGMtn3.TOB3K/Xivs/DEVdbk0D6Iqxoe', '091-002002', '2025-02-15', 1, 1),
('Andrea',  'Méndez',   'andrea@zemyna.com', '$2y$10$A32/CEXLhCrbRkgub3SeWeGMtn3.TOB3K/Xivs/DEVdbk0D6Iqxoe', '091-003003', '2025-03-20', 2, 1);

-- Contenedores
INSERT INTO contenedor (codigo, capacidad, direccion, latitud, longitud, estado, id_tipo_residuo, id_ruta) VALUES
('CTN-001', 2400, 'Av. Brasil y Lázaro Gadea',     -34.9142000, -56.1495000, 'Disponible', 1, 1),
('CTN-002', 3200, 'Brito del Pino y Charrúa',       -34.9210000, -56.1585000, 'Lleno',      2, 1),
('CTN-003', 2400, 'Av. 18 de Julio y Tacuarí',      -34.9065000, -56.1852000, 'Disponible', 3, 2);

-- Cuadrillas
INSERT INTO cuadrilla (nombre, turno, id_centro) VALUES
('Cuadrilla Alpha', 'Matutino',    1),
('Cuadrilla Beta',  'Vespertino',  2);

-- Vehículos
INSERT INTO vehiculo (matricula, marca, modelo, capacidad_carga, estado, id_tipo_residuo) VALUES
('ABC1234', 'Mercedes-Benz', 'Atego 1725', 8.50, 'Disponible',   1),
('XYZ5678', 'Volvo',         'FE 280',     6.00, 'En Servicio',  2),
('MNO9012', 'Scania',        'P 360',     10.00, 'Disponible',   3);

-- Maquinaria
INSERT INTO maquinaria (nombre, tipo, estado, id_centro) VALUES
('Prensadora P-01', 'Prensadora', 'Disponible',    1),
('Trituradora T-01','Trituradora','En Mantenimiento', 2);

-- Incidencias
INSERT INTO incidencia (descripcion, fecha_reporte, estado, prioridad, tipo_problema, id_contenedor, id_cuadrilla, id_usuario) VALUES
('Contenedor dañado, tapa rota.',       '2025-06-01 09:00:00', 'Pendiente',   'Alta',  'Daño físico',    1, 1, 2),
('Contenedor desbordado, necesita vaciado.', '2025-06-02 11:30:00', 'En Proceso', 'Media', 'Desbordamiento', 2, 2, 3);

-- Reclamos
INSERT INTO reclamo (fecha, descripcion, estado, ci, id_incidencia) VALUES
('2025-06-01 10:00:00', 'El contenedor de mi cuadra está roto desde hace días.', 'Pendiente',   '12345678', 1),
('2025-06-02 12:00:00', 'Hay residuos en la vereda por desbordamiento.',          'En Proceso',  '87654321', 2);

-- Fotos de incidencias
INSERT INTO foto (fecha, url, id_incidencia) VALUES
('2025-06-01 09:05:00', '/uploads/incidencias/inc1_foto1.jpg', 1),
('2025-06-02 11:35:00', '/uploads/incidencias/inc2_foto1.jpg', 2);

-- Solicitudes de retiro
INSERT INTO solicitud (fecha, descripcion, direccion, estado, ci, id_tipo_residuo, email, telefono, tipo_solicitud) VALUES
('2025-06-05 08:00:00', 'Retiro de electrodoméstico viejo.',  'Dr. Luis Bonavita 1294', 'Pendiente',   '11223344', 1, 'martin@gmail.com', '092-333333', 'Retiro domiciliario'),
('2025-06-06 09:30:00', 'Gran cantidad de cartones para retirar.', 'Paraguay 1450',   'Programada',  '12345678', 2, 'carlos@gmail.com', '092-111111', 'Retiro domiciliario');

-- Relaciones N:M
INSERT INTO usa (id_cuadrilla, id_vehiculo) VALUES (1, 1), (1, 2), (2, 3);
INSERT INTO recorre (id_vehiculo, id_ruta)  VALUES (1, 1), (2, 1), (3, 2);
