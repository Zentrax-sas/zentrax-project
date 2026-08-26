-- Baja logica de contenedores.
-- La columna se agrega con valor 1 para conservar todos los registros existentes.

ALTER TABLE contenedor
    ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER estado;

CREATE INDEX idx_contenedor_activo ON contenedor (activo);
