-- Cache tecnica para geocodificacion inversa de contenedores.
-- No modifica ni elimina datos del negocio.

CREATE TABLE IF NOT EXISTS geocodificacion_cache (
    id_contenedor INT NOT NULL,
    direccion VARCHAR(255) NOT NULL,
    barrio VARCHAR(150) DEFAULT NULL,
    localidad VARCHAR(150) DEFAULT NULL,
    fecha_consulta DATETIME NOT NULL,
    PRIMARY KEY (id_contenedor),
    CONSTRAINT fk_geocodificacion_contenedor
        FOREIGN KEY (id_contenedor)
        REFERENCES contenedor(id_contenedor)
        ON DELETE RESTRICT
);
