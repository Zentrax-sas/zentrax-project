-- Schema base para Zemyna
-- MySQL 8 compatible

CREATE TABLE rol (
    id_rol INT NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(50) NOT NULL,
    PRIMARY KEY (id_rol),
    UNIQUE KEY uk_rol_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE usuario (
    id_usuario INT NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    id_rol INT NOT NULL,
    id_centro INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_usuario),
    UNIQUE KEY uk_usuario_email (email),
    CONSTRAINT fk_usuario_rol FOREIGN KEY (id_rol) REFERENCES rol (id_rol),
    CHECK (id_rol > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE contenedor (
    id_contenedor INT NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(50) NOT NULL,
    direccion VARCHAR(255) NOT NULL,
    capacidad_litros INT NOT NULL,
    tipo_residuo VARCHAR(50) NOT NULL,
    estado_llenado VARCHAR(30) NOT NULL DEFAULT 'Vacio',
    municipio VARCHAR(50) NOT NULL,
    latitud DECIMAL(10, 8) NULL,
    longitud DECIMAL(11, 8) NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'verde',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_contenedor),
    UNIQUE KEY uk_contenedor_codigo (codigo),
    CHECK (capacidad_litros > 0),
    CHECK (estado_llenado IN ('Vacio', 'Medio', 'Lleno', 'Saturado')),
    CHECK (estado IN ('verde', 'amarillo', 'rojo', 'gris'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE camion (
    id_camion INT NOT NULL AUTO_INCREMENT,
    matricula VARCHAR(20) NOT NULL,
    capacidad_toneladas DECIMAL(5, 2) NOT NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'Operativo',
    id_chofer_asignado INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_camion),
    UNIQUE KEY uk_camion_matricula (matricula),
    CHECK (capacidad_toneladas > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE incidencia (
    id_incidencia INT NOT NULL AUTO_INCREMENT,
    id_contenedor INT NOT NULL,
    tipo_incidencia VARCHAR(50) NOT NULL,
    descripcion TEXT NULL,
    id_cuadrilla INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_incidencia),
    CONSTRAINT fk_incidencia_contenedor FOREIGN KEY (id_contenedor) REFERENCES contenedor (id_contenedor),
    CHECK (tipo_incidencia <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_usuario_id_rol ON usuario (id_rol);
CREATE INDEX idx_incidencia_contenedor ON incidencia (id_contenedor);
CREATE INDEX idx_contenedor_municipio ON contenedor (municipio);
