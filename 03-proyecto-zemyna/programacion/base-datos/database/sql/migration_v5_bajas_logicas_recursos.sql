-- Bajas logicas para vehiculos, centros y maquinaria.
-- El valor por defecto 1 conserva todos los registros existentes.

ALTER TABLE centro
    ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER telefono;

ALTER TABLE vehiculo
    ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER estado;

ALTER TABLE maquinaria
    ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER estado;

CREATE INDEX idx_centro_activo ON centro (activo);
CREATE INDEX idx_vehiculo_activo ON vehiculo (activo);
CREATE INDEX idx_maquinaria_activo ON maquinaria (activo);
