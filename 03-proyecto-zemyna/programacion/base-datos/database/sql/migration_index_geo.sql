-- Agrega el indice necesario para las consultas de contenedores por viewport.
ALTER TABLE contenedor
    ADD INDEX idx_contenedor_geo (latitud, longitud);
