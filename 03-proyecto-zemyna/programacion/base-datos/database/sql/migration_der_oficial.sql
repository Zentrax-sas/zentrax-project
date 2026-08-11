-- Migracion desde schema legado (rol/usuario/contenedor/camion/incidencia)
-- hacia DER oficial Zemyna v0.9
-- Ejecutar en entorno de desarrollo con backup previo.

START TRANSACTION;
SET FOREIGN_KEY_CHECKS = 0;

-- Respaldos temporales
DROP TABLE IF EXISTS usuario_legacy;
DROP TABLE IF EXISTS contenedor_legacy;
DROP TABLE IF EXISTS camion_legacy;
DROP TABLE IF EXISTS incidencia_legacy;
DROP TABLE IF EXISTS rol_legacy;

RENAME TABLE usuario TO usuario_legacy;
RENAME TABLE contenedor TO contenedor_legacy;
RENAME TABLE camion TO camion_legacy;
RENAME TABLE incidencia TO incidencia_legacy;
RENAME TABLE rol TO rol_legacy;

-- Limpiar tablas destino por si existen de una corrida anterior
DROP TABLE IF EXISTS solicitud, recorre, usa, maquinaria, foto, reclamo,
                     incidencia, contenedor, vehiculo, cuadrilla, acopio,
                     vertedero, usuario, vecino, ruta, tipo_residuo, centro;

-- Estructura DER oficial
CREATE TABLE tipo_residuo (
    id_tipo_residuo INT          NOT NULL AUTO_INCREMENT,
    nombre          VARCHAR(50)  NOT NULL,
    descripcion     VARCHAR(150) NOT NULL,
    PRIMARY KEY (id_tipo_residuo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ruta (
    id_ruta INT          NOT NULL AUTO_INCREMENT,
    nombre  VARCHAR(50)  NOT NULL,
    zona    VARCHAR(100) NOT NULL,
    PRIMARY KEY (id_ruta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE centro (
    id_centro INT          NOT NULL AUTO_INCREMENT,
    nombre    VARCHAR(100) NOT NULL,
    direccion VARCHAR(100) NOT NULL,
    telefono  VARCHAR(20)  NOT NULL,
    PRIMARY KEY (id_centro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE vecino (
    ci       CHAR(8)     NOT NULL,
    nombre   VARCHAR(50) NOT NULL,
    apellido VARCHAR(50) NOT NULL,
    telefono VARCHAR(50) NOT NULL,
    PRIMARY KEY (ci)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE acopio (
    id_centro        INT          NOT NULL,
    horario_atencion VARCHAR(100) NOT NULL,
    PRIMARY KEY (id_centro),
    CONSTRAINT fk_acopio_centro FOREIGN KEY (id_centro) REFERENCES centro (id_centro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE vertedero (
    id_centro        INT           NOT NULL,
    capacidad_maxima DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (id_centro),
    CONSTRAINT fk_vertedero_centro FOREIGN KEY (id_centro) REFERENCES centro (id_centro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE usuario (
    id_usuario     INT                              NOT NULL AUTO_INCREMENT,
    nombre         VARCHAR(50)                      NOT NULL,
    apellido       VARCHAR(50)                      NOT NULL,
    email          VARCHAR(100)                     NOT NULL,
    contraseña     VARCHAR(255)                     NOT NULL,
    telefono       VARCHAR(20)                      NOT NULL,
    fecha_registro DATE                             NOT NULL,
    rol            ENUM('Administrador','Operario') NOT NULL,
    id_centro      INT                              NOT NULL,
    PRIMARY KEY (id_usuario),
    UNIQUE KEY uk_usuario_email (email),
    CONSTRAINT fk_usuario_centro FOREIGN KEY (id_centro) REFERENCES centro (id_centro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE contenedor (
    id_contenedor   INT                                                     NOT NULL AUTO_INCREMENT,
    codigo          VARCHAR(20)                                             NOT NULL,
    capacidad       INT                                                     NOT NULL,
    direccion       VARCHAR(150)                                            NOT NULL,
    latitud         DECIMAL(10,7)                                           NOT NULL,
    longitud        DECIMAL(10,7)                                           NOT NULL,
    estado          ENUM('Disponible','Lleno','Dañado','Fuera de Servicio') NOT NULL,
    id_tipo_residuo INT                                                     NOT NULL,
    id_ruta         INT                                                     NOT NULL,
    PRIMARY KEY (id_contenedor),
    UNIQUE KEY uk_contenedor_codigo (codigo),
    CONSTRAINT fk_contenedor_tipo_residuo FOREIGN KEY (id_tipo_residuo) REFERENCES tipo_residuo (id_tipo_residuo),
    CONSTRAINT fk_contenedor_ruta FOREIGN KEY (id_ruta) REFERENCES ruta (id_ruta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cuadrilla (
    id_cuadrilla INT                                    NOT NULL AUTO_INCREMENT,
    nombre       VARCHAR(50)                            NOT NULL,
    turno        ENUM('Matutino','Vespertino','Nocturno') NOT NULL,
    id_centro    INT                                    NOT NULL,
    PRIMARY KEY (id_cuadrilla),
    CONSTRAINT fk_cuadrilla_centro FOREIGN KEY (id_centro) REFERENCES centro (id_centro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE vehiculo (
    id_vehiculo     INT                                                 NOT NULL AUTO_INCREMENT,
    matricula       VARCHAR(10)                                         NOT NULL,
    marca           VARCHAR(50)                                         NOT NULL,
    modelo          VARCHAR(50)                                         NOT NULL,
    capacidad_carga DECIMAL(8,2)                                        NOT NULL,
    estado          ENUM('Disponible','En Servicio','En Mantenimiento') NOT NULL,
    id_tipo_residuo INT                                                 NOT NULL,
    PRIMARY KEY (id_vehiculo),
    UNIQUE KEY uk_vehiculo_matricula (matricula),
    CONSTRAINT fk_vehiculo_tipo_residuo FOREIGN KEY (id_tipo_residuo) REFERENCES tipo_residuo (id_tipo_residuo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE maquinaria (
    id_maquinaria INT                                               NOT NULL AUTO_INCREMENT,
    nombre        VARCHAR(50)                                       NOT NULL,
    tipo          VARCHAR(50)                                       NOT NULL,
    estado        ENUM('Disponible','En Uso','En Mantenimiento')    NOT NULL,
    id_centro     INT                                               NOT NULL,
    PRIMARY KEY (id_maquinaria),
    CONSTRAINT fk_maquinaria_centro FOREIGN KEY (id_centro) REFERENCES centro (id_centro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE incidencia (
    id_incidencia INT                                       NOT NULL AUTO_INCREMENT,
    descripcion   TEXT                                      NOT NULL,
    fecha_reporte DATETIME                                  NOT NULL,
    estado        ENUM('Pendiente','En Proceso','Resuelta') NOT NULL,
    prioridad     ENUM('Baja','Media','Alta')              NOT NULL,
    tipo_problema VARCHAR(50)                              NOT NULL,
    id_contenedor INT                                       NOT NULL,
    id_cuadrilla  INT                                       NOT NULL,
    id_usuario    INT                                       NOT NULL,
    PRIMARY KEY (id_incidencia),
    CONSTRAINT fk_incidencia_contenedor FOREIGN KEY (id_contenedor) REFERENCES contenedor (id_contenedor),
    CONSTRAINT fk_incidencia_cuadrilla FOREIGN KEY (id_cuadrilla) REFERENCES cuadrilla (id_cuadrilla),
    CONSTRAINT fk_incidencia_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE reclamo (
    id_reclamo    INT                                          NOT NULL AUTO_INCREMENT,
    fecha         DATETIME                                     NOT NULL,
    descripcion   TEXT                                         NOT NULL,
    estado        ENUM('Pendiente','En Proceso','Resuelto')    NOT NULL,
    ci            CHAR(8)                                      NOT NULL,
    id_incidencia INT                                          NOT NULL,
    PRIMARY KEY (id_reclamo),
    CONSTRAINT fk_reclamo_vecino FOREIGN KEY (ci) REFERENCES vecino (ci),
    CONSTRAINT fk_reclamo_incidencia FOREIGN KEY (id_incidencia) REFERENCES incidencia (id_incidencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE foto (
    id_foto       INT          NOT NULL AUTO_INCREMENT,
    fecha         DATETIME     NOT NULL,
    url           VARCHAR(255) NOT NULL,
    id_incidencia INT          NOT NULL,
    PRIMARY KEY (id_foto),
    CONSTRAINT fk_foto_incidencia FOREIGN KEY (id_incidencia) REFERENCES incidencia (id_incidencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE solicitud (
    id_solicitud    INT                                              NOT NULL AUTO_INCREMENT,
    fecha           DATETIME                                         NOT NULL,
    descripcion     TEXT                                             NOT NULL,
    direccion       VARCHAR(150)                                     NOT NULL,
    estado          ENUM('Pendiente','Programada','Finalizada','Cancelada') NOT NULL,
    ci              CHAR(8)                                          NOT NULL,
    id_tipo_residuo INT                                              NOT NULL,
    email           VARCHAR(100)                                     NOT NULL,
    telefono        VARCHAR(20)                                      NOT NULL,
    tipo_solicitud  VARCHAR(50)                                      NOT NULL,
    PRIMARY KEY (id_solicitud),
    CONSTRAINT fk_solicitud_vecino FOREIGN KEY (ci) REFERENCES vecino (ci),
    CONSTRAINT fk_solicitud_tipo_residuo FOREIGN KEY (id_tipo_residuo) REFERENCES tipo_residuo (id_tipo_residuo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE usa (
    id_cuadrilla INT NOT NULL,
    id_vehiculo  INT NOT NULL,
    PRIMARY KEY (id_cuadrilla, id_vehiculo),
    CONSTRAINT fk_usa_cuadrilla FOREIGN KEY (id_cuadrilla) REFERENCES cuadrilla (id_cuadrilla),
    CONSTRAINT fk_usa_vehiculo FOREIGN KEY (id_vehiculo) REFERENCES vehiculo (id_vehiculo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recorre (
    id_vehiculo INT NOT NULL,
    id_ruta     INT NOT NULL,
    PRIMARY KEY (id_vehiculo, id_ruta),
    CONSTRAINT fk_recorre_vehiculo FOREIGN KEY (id_vehiculo) REFERENCES vehiculo (id_vehiculo),
    CONSTRAINT fk_recorre_ruta FOREIGN KEY (id_ruta) REFERENCES ruta (id_ruta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Datos base minimos para poder migrar FK de tablas legadas
INSERT INTO tipo_residuo (id_tipo_residuo, nombre, descripcion) VALUES
(1, 'Orgánico', 'Migrado desde esquema legado'),
(2, 'Papel y cartón', 'Migrado desde esquema legado'),
(3, 'Plástico', 'Migrado desde esquema legado')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

INSERT INTO ruta (id_ruta, nombre, zona) VALUES
(1, 'Ruta Inicial', 'Zona general')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

INSERT INTO centro (id_centro, nombre, direccion, telefono) VALUES
(1, 'Centro Migrado', 'Direccion a definir', '099000000')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

INSERT INTO acopio (id_centro, horario_atencion) VALUES
(1, 'Lunes a viernes 08:00-16:00')
ON DUPLICATE KEY UPDATE horario_atencion = VALUES(horario_atencion);

INSERT INTO cuadrilla (id_cuadrilla, nombre, turno, id_centro) VALUES
(1, 'Cuadrilla Migrada', 'Matutino', 1)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- Migracion de USUARIO legado -> USUARIO DER
INSERT INTO usuario (id_usuario, nombre, apellido, email, contraseña, telefono, fecha_registro, rol, id_centro)
SELECT
    u.id_usuario,
    u.nombre,
    '',
    u.email,
    u.password,
    '099000000',
    DATE(COALESCE(u.created_at, NOW())),
    CASE WHEN u.id_rol IN (1,2) THEN 'Administrador' ELSE 'Operario' END,
    COALESCE(NULLIF(u.id_centro, 0), 1)
FROM usuario_legacy u;

-- Migracion de CONTENEDOR legado -> CONTENEDOR DER
INSERT INTO contenedor (id_contenedor, codigo, capacidad, direccion, latitud, longitud, estado, id_tipo_residuo, id_ruta)
SELECT
    c.id_contenedor,
    c.codigo,
    c.capacidad_litros,
    c.direccion,
    COALESCE(c.latitud, -34.9011000),
    COALESCE(c.longitud, -56.1645000),
    CASE
        WHEN c.estado IN ('verde', 'gris') THEN 'Disponible'
        WHEN c.estado = 'amarillo' THEN 'Lleno'
        WHEN c.estado = 'rojo' THEN 'Fuera de Servicio'
        ELSE 'Disponible'
    END,
    CASE
        WHEN LOWER(c.tipo_residuo) LIKE '%organ%' THEN 1
        WHEN LOWER(c.tipo_residuo) LIKE '%papel%' OR LOWER(c.tipo_residuo) LIKE '%carton%' THEN 2
        ELSE 3
    END,
    1
FROM contenedor_legacy c;

-- Migracion de CAMION legado -> VEHICULO DER
INSERT INTO vehiculo (id_vehiculo, matricula, marca, modelo, capacidad_carga, estado, id_tipo_residuo)
SELECT
    m.id_camion,
    m.matricula,
    'No especificada',
    'No especificado',
    m.capacidad_toneladas,
    CASE
        WHEN LOWER(m.estado) IN ('operativo', 'disponible') THEN 'Disponible'
        WHEN LOWER(m.estado) LIKE '%ruta%' OR LOWER(m.estado) LIKE '%servicio%' THEN 'En Servicio'
        ELSE 'En Mantenimiento'
    END,
    1
FROM camion_legacy m;

-- Migracion de INCIDENCIA legado -> INCIDENCIA DER
INSERT INTO incidencia (id_incidencia, descripcion, fecha_reporte, estado, prioridad, tipo_problema, id_contenedor, id_cuadrilla, id_usuario)
SELECT
    i.id_incidencia,
    COALESCE(i.descripcion, i.tipo_incidencia),
    COALESCE(i.created_at, NOW()),
    'Pendiente',
    'Media',
    COALESCE(i.tipo_incidencia, 'Sin clasificar'),
    i.id_contenedor,
    COALESCE(NULLIF(i.id_cuadrilla, 0), 1),
    (SELECT id_usuario FROM usuario ORDER BY id_usuario LIMIT 1)
FROM incidencia_legacy i;

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;
