-- Schema oficial Zemyna — DER v0.9 (ZTX-DOC-ISW-001 / ZTX-DOC-ISW-003)
-- MySQL 8 compatible — 17 tablas
-- RNE-21: todo CENTRO debe ser Acopio O Vertedero (herencia total y exclusiva).
--         MySQL no soporta constraint de herencia nativo; validar en capa de aplicación.
-- RNE-09: un Operario pertenece a una única cuadrilla; validar en capa de aplicación.

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS solicitud, recorre, usa, maquinaria, foto, reclamo,
                     incidencia, contenedor, vehiculo, cuadrilla, acopio,
                     vertedero, usuario, vecino, ruta, tipo_residuo, centro;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------
-- Tablas sin dependencias
-- ---------------------------------------------------------------

CREATE TABLE tipo_residuo (
    id_tipo_residuo INT          NOT NULL AUTO_INCREMENT,
    nombre          VARCHAR(50)  NOT NULL,
    descripcion     VARCHAR(150) NOT NULL,
    PRIMARY KEY (id_tipo_residuo)
    -- RNE-27: Orgánico | Papel y cartón | Plástico | Vidrio | Metal |
    --         Electrónicos | Pilas y baterías | Escombros | Residuos voluminosos
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ruta (
    id_ruta INT          NOT NULL AUTO_INCREMENT,
    nombre  VARCHAR(50)  NOT NULL,
    zona    VARCHAR(100) NOT NULL,
    PRIMARY KEY (id_ruta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE centro (
    id_centro  INT          NOT NULL AUTO_INCREMENT,
    nombre     VARCHAR(100) NOT NULL,
    direccion  VARCHAR(100) NOT NULL,
    telefono   VARCHAR(20)  NOT NULL,
    PRIMARY KEY (id_centro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE vecino (
    ci       CHAR(8)     NOT NULL,
    nombre   VARCHAR(50) NOT NULL,
    apellido VARCHAR(50) NOT NULL,
    telefono VARCHAR(50) NOT NULL,
    PRIMARY KEY (ci)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Especializaciones de CENTRO (herencia total/exclusiva — RNE-21)
-- ---------------------------------------------------------------

CREATE TABLE acopio (
    id_centro        INT          NOT NULL,
    horario_atencion VARCHAR(100) NOT NULL,
    PRIMARY KEY (id_centro),
    CONSTRAINT fk_acopio_centro FOREIGN KEY (id_centro) REFERENCES centro (id_centro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE vertedero (
    id_centro        INT            NOT NULL,
    capacidad_maxima DECIMAL(10,2)  NOT NULL,
    PRIMARY KEY (id_centro),
    CONSTRAINT fk_vertedero_centro FOREIGN KEY (id_centro) REFERENCES centro (id_centro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Tablas con FK a CENTRO / TIPO_RESIDUO / RUTA
-- ---------------------------------------------------------------

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
    id_contenedor   INT                                                    NOT NULL AUTO_INCREMENT,
    codigo          VARCHAR(20)                                            NOT NULL,
    capacidad       INT                                                    NOT NULL,
    direccion       VARCHAR(150)                                           NOT NULL,
    latitud         DECIMAL(10,7)                                          NOT NULL,
    longitud        DECIMAL(10,7)                                          NOT NULL,
    estado          ENUM('Disponible','Lleno','Dañado','Fuera de Servicio') NOT NULL,
    id_tipo_residuo INT                                                    NOT NULL,
    id_ruta         INT                                                    NOT NULL,
    PRIMARY KEY (id_contenedor),
    UNIQUE KEY uk_contenedor_codigo (codigo),
    CONSTRAINT fk_contenedor_tipo_residuo FOREIGN KEY (id_tipo_residuo) REFERENCES tipo_residuo (id_tipo_residuo),
    CONSTRAINT fk_contenedor_ruta         FOREIGN KEY (id_ruta)         REFERENCES ruta (id_ruta)
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
    id_vehiculo     INT                                              NOT NULL AUTO_INCREMENT,
    matricula       VARCHAR(10)                                      NOT NULL,
    marca           VARCHAR(50)                                      NOT NULL,
    modelo          VARCHAR(50)                                      NOT NULL,
    capacidad_carga DECIMAL(8,2)                                     NOT NULL,
    estado          ENUM('Disponible','En Servicio','En Mantenimiento') NOT NULL,
    id_tipo_residuo INT                                              NOT NULL,
    PRIMARY KEY (id_vehiculo),
    UNIQUE KEY uk_vehiculo_matricula (matricula),
    CONSTRAINT fk_vehiculo_tipo_residuo FOREIGN KEY (id_tipo_residuo) REFERENCES tipo_residuo (id_tipo_residuo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE maquinaria (
    id_maquinaria INT                                              NOT NULL AUTO_INCREMENT,
    nombre        VARCHAR(50)                                      NOT NULL,
    tipo          VARCHAR(50)                                      NOT NULL,
    estado        ENUM('Disponible','En Uso','En Mantenimiento')   NOT NULL,
    id_centro     INT                                              NOT NULL,
    PRIMARY KEY (id_maquinaria),
    CONSTRAINT fk_maquinaria_centro FOREIGN KEY (id_centro) REFERENCES centro (id_centro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Tablas con FK cruzadas
-- ---------------------------------------------------------------

CREATE TABLE incidencia (
    id_incidencia  INT                                       NOT NULL AUTO_INCREMENT,
    descripcion    TEXT                                      NOT NULL,
    fecha_reporte  DATETIME                                  NOT NULL,
    estado         ENUM('Pendiente','En Proceso','Resuelta') NOT NULL,
    prioridad      ENUM('Baja','Media','Alta')               NOT NULL,
    tipo_problema  VARCHAR(50)                               NOT NULL,
    id_contenedor  INT                                       NOT NULL,
    id_cuadrilla   INT                                       NOT NULL,
    id_usuario     INT                                       NOT NULL,
    PRIMARY KEY (id_incidencia),
    CONSTRAINT fk_incidencia_contenedor FOREIGN KEY (id_contenedor) REFERENCES contenedor (id_contenedor),
    CONSTRAINT fk_incidencia_cuadrilla  FOREIGN KEY (id_cuadrilla)  REFERENCES cuadrilla (id_cuadrilla),
    CONSTRAINT fk_incidencia_usuario    FOREIGN KEY (id_usuario)    REFERENCES usuario (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE reclamo (
    id_reclamo    INT                                          NOT NULL AUTO_INCREMENT,
    fecha         DATETIME                                     NOT NULL,
    descripcion   TEXT                                         NOT NULL,
    estado        ENUM('Pendiente','En Proceso','Resuelto')    NOT NULL,
    ci            CHAR(8)                                      NOT NULL,
    id_incidencia INT                                          NOT NULL,
    PRIMARY KEY (id_reclamo),
    CONSTRAINT fk_reclamo_vecino     FOREIGN KEY (ci)            REFERENCES vecino (ci),
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
    CONSTRAINT fk_solicitud_vecino      FOREIGN KEY (ci)             REFERENCES vecino (ci),
    CONSTRAINT fk_solicitud_tipo_residuo FOREIGN KEY (id_tipo_residuo) REFERENCES tipo_residuo (id_tipo_residuo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Tablas de relación N:M
-- ---------------------------------------------------------------

-- RNE-17/18: Cuadrilla usa Vehículo (N:M)
CREATE TABLE usa (
    id_cuadrilla INT NOT NULL,
    id_vehiculo  INT NOT NULL,
    PRIMARY KEY (id_cuadrilla, id_vehiculo),
    CONSTRAINT fk_usa_cuadrilla FOREIGN KEY (id_cuadrilla) REFERENCES cuadrilla (id_cuadrilla),
    CONSTRAINT fk_usa_vehiculo  FOREIGN KEY (id_vehiculo)  REFERENCES vehiculo (id_vehiculo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- RNE-19/20: Vehículo recorre Ruta (N:M)
-- RNE-36: solo rutas del tipo de residuo habilitado; validar en capa de aplicación.
CREATE TABLE recorre (
    id_vehiculo INT NOT NULL,
    id_ruta     INT NOT NULL,
    PRIMARY KEY (id_vehiculo, id_ruta),
    CONSTRAINT fk_recorre_vehiculo FOREIGN KEY (id_vehiculo) REFERENCES vehiculo (id_vehiculo),
    CONSTRAINT fk_recorre_ruta     FOREIGN KEY (id_ruta)     REFERENCES ruta (id_ruta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Índices auxiliares
-- ---------------------------------------------------------------
CREATE INDEX idx_contenedor_ruta         ON contenedor (id_ruta);
CREATE INDEX idx_contenedor_tipo_residuo ON contenedor (id_tipo_residuo);
CREATE INDEX idx_incidencia_contenedor   ON incidencia (id_contenedor);
CREATE INDEX idx_incidencia_cuadrilla    ON incidencia (id_cuadrilla);
CREATE INDEX idx_solicitud_ci            ON solicitud (ci);
