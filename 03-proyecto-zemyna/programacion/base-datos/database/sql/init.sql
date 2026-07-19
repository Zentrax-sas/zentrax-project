-- Datos iniciales de ejemplo para Zemyna
-- No insertar datos sensibles en producción

INSERT INTO rol (nombre) VALUES
('superadmin'),
('dispatcher'),
('chofer');

INSERT INTO usuario (nombre, email, password, id_rol, id_centro) VALUES
('Facundo', 'facu@zemyna.com', '$2y$10$demo.hash.aqui', 1, NULL),
('Diego', 'diego@zemyna.com', '$2y$10$demo.hash.aqui', 3, NULL);

INSERT INTO contenedor (codigo, direccion, capacidad_litros, tipo_residuo, estado_llenado, municipio, latitud, longitud, estado) VALUES
('CH-101', 'Av. Brasil y Lázaro Gadea', 3200, 'Reciclable', 'Medio', 'CH', -34.9142, -56.1495, 'verde'),
('CH-102', 'Brito del Pino y Chana', 2400, 'Orgánico', 'Saturado', 'CH', -34.9210, -56.1585, 'rojo');

INSERT INTO camion (matricula, capacidad_toneladas, estado, id_chofer_asignado) VALUES
('ABC-123', 8.50, 'Operativo', 2),
('XYZ-789', 6.00, 'En Ruta', NULL);
