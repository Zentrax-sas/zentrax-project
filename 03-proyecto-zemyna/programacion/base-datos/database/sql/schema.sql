-- schema.sql = ejecutar para instalación limpia
-- Fuente de verdad del DER oficial Zemyna — MySQL 8 compatible.
-- Este archivo debe usarse como base para una instalación nueva.

-- Schema oficial Zemyna — DER v0.9 (ZTX-DOC-ISW-001 / ZTX-DOC-ISW-003)
-- MySQL 8 compatible — 22 tablas
DROP DATABASE IF EXISTS gestion_residuosfinal;

CREATE DATABASE gestion_residuosfinal
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE gestion_residuosfinal;

ALTER DATABASE gestion_residuosfinal
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;


-- =====================================
-- TABLA VECINO
-- =====================================

CREATE TABLE vecino (
    ci CHAR(8) NOT NULL,
    nombre VARCHAR(50) NOT NULL,
    apellido VARCHAR(50) NOT NULL,
    telefono VARCHAR(20) NOT NULL,

    PRIMARY KEY (ci)
);


-- =====================================
-- TABLA CENTRO
-- =====================================

CREATE TABLE centro (
    id_centro INT NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(100) NOT NULL,
    direccion VARCHAR(150) NOT NULL,
    telefono VARCHAR(20),

    PRIMARY KEY (id_centro)
);


-- =====================================
-- TABLA TIPO_RESIDUO
-- =====================================

CREATE TABLE tipo_residuo (
    id_tipo_residuo INT NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(50) NOT NULL,
    descripcion VARCHAR(150),

    PRIMARY KEY (id_tipo_residuo)
);


-- =====================================
-- TABLA RUTA
-- =====================================

CREATE TABLE ruta (
    id_ruta INT NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(50) NOT NULL,
    zona VARCHAR(100) NOT NULL,

    PRIMARY KEY (id_ruta)
);


-- =====================================
-- TABLA USUARIO
-- =====================================

CREATE TABLE usuario (
    id_usuario INT NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(50) NOT NULL,
    apellido VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL,
    contrasena VARCHAR(255) NOT NULL,
    telefono VARCHAR(20),
    fecha_registro DATE NOT NULL,
    id_centro INT NOT NULL,
    activo ENUM('Activo', 'Inactivo') NOT NULL DEFAULT 'Activo',

    PRIMARY KEY (id_usuario),
    UNIQUE (email),

    CONSTRAINT fk_usuario_centro
        FOREIGN KEY (id_centro)
        REFERENCES centro(id_centro)
);

-- =====================================
-- TABLA ROL
-- =====================================

CREATE TABLE rol (
    id_rol INT NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(50) NOT NULL,
    descripcion VARCHAR(150),

    PRIMARY KEY (id_rol),

    UNIQUE (nombre)
);


-- =====================================
-- TABLA USUARIO_ROL
-- =====================================

CREATE TABLE usuario_rol (
    id_usuario_rol INT NOT NULL AUTO_INCREMENT,
    id_usuario INT NOT NULL,
    id_rol INT NOT NULL,
    sector VARCHAR(100) NOT NULL,
    fecha_desde DATE NOT NULL,
    fecha_hasta DATE DEFAULT NULL,

    PRIMARY KEY (id_usuario_rol),

    CONSTRAINT fk_usuario_rol_usuario
        FOREIGN KEY (id_usuario)
        REFERENCES usuario(id_usuario),

    CONSTRAINT fk_usuario_rol_rol
        FOREIGN KEY (id_rol)
        REFERENCES rol(id_rol),

    CONSTRAINT chk_usuario_rol_fechas
        CHECK (
            fecha_hasta IS NULL
            OR fecha_hasta >= fecha_desde
        )
);


-- =====================================
-- TABLA CONTENEDOR
-- =====================================

CREATE TABLE contenedor (
    id_contenedor INT NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(20) NOT NULL,
    capacidad DECIMAL(8,2) NOT NULL,
    direccion VARCHAR(150) NOT NULL,
    latitud DECIMAL(10,7) NOT NULL,
    longitud DECIMAL(10,7) NOT NULL,

    estado ENUM(
        'Disponible',
        'Lleno',
        'Dañado',
        'Fuera de Servicio'
    ) NOT NULL,

    id_tipo_residuo INT NOT NULL,
    id_ruta INT NOT NULL,

    PRIMARY KEY (id_contenedor),

    UNIQUE (codigo),

    CONSTRAINT fk_contenedor_tipo_residuo
        FOREIGN KEY (id_tipo_residuo)
        REFERENCES tipo_residuo(id_tipo_residuo),

    CONSTRAINT fk_contenedor_ruta
        FOREIGN KEY (id_ruta)
        REFERENCES ruta(id_ruta),

    CONSTRAINT chk_contenedor_capacidad
        CHECK (capacidad > 0),

    CONSTRAINT chk_contenedor_latitud
        CHECK (latitud BETWEEN -90 AND 90),

    CONSTRAINT chk_contenedor_longitud
        CHECK (longitud BETWEEN -180 AND 180)
);

CREATE INDEX idx_contenedor_geo ON contenedor (latitud, longitud);


-- =====================================
-- TABLA VEHICULO
-- =====================================

CREATE TABLE vehiculo (
    id_vehiculo INT NOT NULL AUTO_INCREMENT,
    id_tipo_residuo INT NOT NULL,
    matricula VARCHAR(10) NOT NULL,
    marca VARCHAR(50) NOT NULL,
    modelo VARCHAR(50) NOT NULL,
    capacidad_carga DECIMAL(8,2) NOT NULL,

    estado ENUM(
        'Disponible',
        'En Servicio',
        'En Mantenimiento'
    ) NOT NULL,

    PRIMARY KEY (id_vehiculo),

    UNIQUE (matricula),

    CONSTRAINT fk_vehiculo_tipo_residuo
        FOREIGN KEY (id_tipo_residuo)
        REFERENCES tipo_residuo(id_tipo_residuo),

    CONSTRAINT chk_vehiculo_capacidad
        CHECK (capacidad_carga > 0)
);


-- =====================================
-- TABLA CUADRILLA
-- =====================================

CREATE TABLE cuadrilla (
    id_cuadrilla INT NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(50) NOT NULL,

    turno ENUM(
        'Matutino',
        'Vespertino',
        'Nocturno'
    ) NOT NULL,

    id_centro INT NOT NULL,

    PRIMARY KEY (id_cuadrilla),

    CONSTRAINT fk_cuadrilla_centro
        FOREIGN KEY (id_centro)
        REFERENCES centro(id_centro)
);


-- =====================================
-- TABLA USA
-- Cuadrilla + Vehiculo
-- =====================================

CREATE TABLE usa (
    id_usa INT NOT NULL AUTO_INCREMENT,
    id_cuadrilla INT NOT NULL,
    id_vehiculo INT NOT NULL,

    PRIMARY KEY (id_usa),

    CONSTRAINT fk_usa_cuadrilla
        FOREIGN KEY (id_cuadrilla)
        REFERENCES cuadrilla(id_cuadrilla),

    CONSTRAINT fk_usa_vehiculo
        FOREIGN KEY (id_vehiculo)
        REFERENCES vehiculo(id_vehiculo)
);


-- =====================================
-- TABLA RECORRIDO
-- =====================================

CREATE TABLE recorrido (
    id_recorrido INT NOT NULL AUTO_INCREMENT,
    fecha_inicio DATETIME NOT NULL,
    fecha_fin DATETIME DEFAULT NULL,

    estado ENUM(
        'Pendiente',
        'En Proceso',
        'Finalizado',
        'Cancelado'
    ) NOT NULL,

    id_ruta INT NOT NULL,

    PRIMARY KEY (id_recorrido),

    CONSTRAINT fk_recorrido_ruta
        FOREIGN KEY (id_ruta)
        REFERENCES ruta(id_ruta),

    CONSTRAINT chk_recorrido_fechas
        CHECK (
            fecha_fin IS NULL
            OR fecha_fin >= fecha_inicio
        )
);


-- =====================================
-- TABLA PARTICIPA
-- Relacion entre USA y RECORRIDO
-- =====================================

CREATE TABLE participa (
    id_participa INT NOT NULL AUTO_INCREMENT,
    id_usa INT NOT NULL,
    id_recorrido INT NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME DEFAULT NULL,
    motivo_fin VARCHAR(150) DEFAULT NULL,

    PRIMARY KEY (id_participa),

    CONSTRAINT fk_participa_usa
        FOREIGN KEY (id_usa)
        REFERENCES usa(id_usa),

    CONSTRAINT fk_participa_recorrido
        FOREIGN KEY (id_recorrido)
        REFERENCES recorrido(id_recorrido),

    CONSTRAINT chk_participa_horas
        CHECK (
            hora_fin IS NULL
            OR hora_fin >= hora_inicio
        )
);


-- =====================================
-- TABLA INCIDENCIA
-- =====================================

CREATE TABLE incidencia (
    id_incidencia INT NOT NULL AUTO_INCREMENT,
    descripcion TEXT NOT NULL,
    fecha_reporte DATETIME NOT NULL,

    estado ENUM(
        'Pendiente',
        'En Proceso',
        'Resuelta'
    ) NOT NULL,

    prioridad ENUM(
        'Baja',
        'Media',
        'Alta'
    ) NOT NULL,

    tipo_problema VARCHAR(50) NOT NULL,

    id_contenedor INT DEFAULT NULL,
    id_ruta INT DEFAULT NULL,
    id_cuadrilla INT NOT NULL,
    id_usuario INT NOT NULL,

    PRIMARY KEY (id_incidencia),

    CONSTRAINT fk_incidencia_contenedor
        FOREIGN KEY (id_contenedor)
        REFERENCES contenedor(id_contenedor),

    CONSTRAINT fk_incidencia_ruta
        FOREIGN KEY (id_ruta)
        REFERENCES ruta(id_ruta),

    CONSTRAINT fk_incidencia_cuadrilla
        FOREIGN KEY (id_cuadrilla)
        REFERENCES cuadrilla(id_cuadrilla),

    CONSTRAINT fk_incidencia_usuario
        FOREIGN KEY (id_usuario)
        REFERENCES usuario(id_usuario),

    CONSTRAINT chk_incidencia_ambito
        CHECK (
            (id_contenedor IS NOT NULL AND id_ruta IS NULL)
            OR
            (id_contenedor IS NULL AND id_ruta IS NOT NULL)
        )
);


-- =====================================
-- TABLA DENUNCIA
-- Reemplaza RECLAMO
-- =====================================

CREATE TABLE denuncia (
    id_denuncia INT NOT NULL AUTO_INCREMENT,
    fecha DATETIME NOT NULL,
    descripcion TEXT NOT NULL,
    ci CHAR(8) NOT NULL,
    id_incidencia INT NOT NULL,

    PRIMARY KEY (id_denuncia),

    CONSTRAINT fk_denuncia_vecino
        FOREIGN KEY (ci)
        REFERENCES vecino(ci),

    CONSTRAINT fk_denuncia_incidencia
        FOREIGN KEY (id_incidencia)
        REFERENCES incidencia(id_incidencia)
);


-- =====================================
-- TABLA FOTO
-- =====================================

CREATE TABLE foto (
    id_foto INT NOT NULL AUTO_INCREMENT,
    fecha DATE NOT NULL,
    url VARCHAR(255) NOT NULL,
    id_incidencia INT NOT NULL,

    PRIMARY KEY (id_foto),

    CONSTRAINT fk_foto_incidencia
        FOREIGN KEY (id_incidencia)
        REFERENCES incidencia(id_incidencia)
);


-- =====================================
-- TABLA ACOPIO
-- =====================================

CREATE TABLE acopio (
    id_centro INT NOT NULL,
    horario_atencion VARCHAR(100) NOT NULL,

    PRIMARY KEY (id_centro),

    CONSTRAINT fk_acopio_centro
        FOREIGN KEY (id_centro)
        REFERENCES centro(id_centro)
);


-- =====================================
-- TABLA VERTEDERO
-- =====================================

CREATE TABLE vertedero (
    id_centro INT NOT NULL,
    capacidad_maxima DECIMAL(10,2) NOT NULL,

    PRIMARY KEY (id_centro),

    CONSTRAINT fk_vertedero_centro
        FOREIGN KEY (id_centro)
        REFERENCES centro(id_centro),

    CONSTRAINT chk_vertedero_capacidad
        CHECK (capacidad_maxima > 0)
);


-- =====================================
-- TABLA MAQUINARIA
-- =====================================

CREATE TABLE maquinaria (
    id_maquinaria INT NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(50) NOT NULL,
    tipo VARCHAR(50) NOT NULL,

    estado ENUM(
        'Disponible',
        'En Uso',
        'En Mantenimiento'
    ) NOT NULL,

    id_centro INT NOT NULL,

    PRIMARY KEY (id_maquinaria),

    CONSTRAINT fk_maquinaria_centro
        FOREIGN KEY (id_centro)
        REFERENCES centro(id_centro)
);


-- =====================================
-- TABLA SOLICITUD
-- =====================================

CREATE TABLE solicitud (
    id_solicitud INT NOT NULL AUTO_INCREMENT,
    fecha DATETIME NOT NULL,
    descripcion TEXT NOT NULL,
    direccion VARCHAR(150) NOT NULL,

    estado ENUM(
        'Pendiente',
        'Programada',
        'Finalizada',
        'Cancelada'
    ) NOT NULL,

    ci CHAR(8) NOT NULL,
    id_tipo_residuo INT NOT NULL,
    email VARCHAR(100) NOT NULL,
    telefono VARCHAR(20) NOT NULL,

    tipo_solicitud ENUM(
        'Gran volumen',
        'Reciclables'
    ) NOT NULL,

    PRIMARY KEY (id_solicitud),

    CONSTRAINT fk_solicitud_vecino
        FOREIGN KEY (ci)
        REFERENCES vecino(ci),

    CONSTRAINT fk_solicitud_tipo_residuo
        FOREIGN KEY (id_tipo_residuo)
        REFERENCES tipo_residuo(id_tipo_residuo)
);


-- =====================================
-- TABLA MANTENIMIENTO
-- Ahora solo Vehiculo o Maquinaria
-- =====================================

CREATE TABLE mantenimiento (
    id_mantenimiento INT NOT NULL AUTO_INCREMENT,
    fecha_inicio DATETIME NOT NULL,
    fecha_fin DATETIME DEFAULT NULL,

    estado ENUM(
        'Pendiente',
        'En Proceso',
        'Finalizado',
        'Cancelado'
    ) NOT NULL,

    tipo ENUM(
        'Preventivo',
        'Correctivo'
    ) NOT NULL,

    descripcion TEXT NOT NULL,

    id_vehiculo INT DEFAULT NULL,
    id_maquinaria INT DEFAULT NULL,

    PRIMARY KEY (id_mantenimiento),

    CONSTRAINT fk_mantenimiento_vehiculo
        FOREIGN KEY (id_vehiculo)
        REFERENCES vehiculo(id_vehiculo),

    CONSTRAINT fk_mantenimiento_maquinaria
        FOREIGN KEY (id_maquinaria)
        REFERENCES maquinaria(id_maquinaria),

    CONSTRAINT chk_mantenimiento_recurso
        CHECK (
            (id_vehiculo IS NOT NULL AND id_maquinaria IS NULL)
            OR
            (id_vehiculo IS NULL AND id_maquinaria IS NOT NULL)
        ),

    CONSTRAINT chk_mantenimiento_fechas
        CHECK (
            fecha_fin IS NULL
            OR fecha_fin >= fecha_inicio
        )
);


-- =====================================
-- TABLA SESION
-- Estructura indicada por el profesor
-- =====================================

CREATE TABLE IF NOT EXISTS sesion (
    id VARCHAR(128) NOT NULL,
    data TEXT NOT NULL,
    last_acces INT UNSIGNED NOT NULL,

    PRIMARY KEY (id)
);


-- =====================================
-- INDICES AUXILIARES
-- =====================================

CREATE INDEX idx_usuario_centro
    ON usuario(id_centro);

CREATE INDEX idx_contenedor_ruta
    ON contenedor(id_ruta);

CREATE INDEX idx_contenedor_tipo
    ON contenedor(id_tipo_residuo);

CREATE INDEX idx_vehiculo_tipo
    ON vehiculo(id_tipo_residuo);

CREATE INDEX idx_incidencia_contenedor
    ON incidencia(id_contenedor);

CREATE INDEX idx_incidencia_ruta
    ON incidencia(id_ruta);

CREATE INDEX idx_incidencia_cuadrilla
    ON incidencia(id_cuadrilla);

CREATE INDEX idx_denuncia_incidencia
    ON denuncia(id_incidencia);

CREATE INDEX idx_recorrido_ruta
    ON recorrido(id_ruta);

CREATE INDEX idx_participa_recorrido
    ON participa(id_recorrido);

ALTER TABLE vecino CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE centro CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE tipo_residuo CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ruta CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE usuario CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rol CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE usuario_rol CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE contenedor CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE vehiculo CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE cuadrilla CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE usa CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE recorrido CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE participa CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE incidencia CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE denuncia CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE foto CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE acopio CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE vertedero CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE maquinaria CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE solicitud CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE mantenimiento CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE sesion CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    