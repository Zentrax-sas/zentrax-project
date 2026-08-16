-- migration_v2_roles_normalizados.sql
-- Migracion desde el schema intermedio hacia el schema oficial actual.
-- Ejecutar solo en entornos de desarrollo con datos del schema intermedio,
-- no en una instalacion nueva. Para una instalacion nueva usar schema.sql.
-- Esta migracion normaliza usuario.rol y agrega mantenimiento.

START TRANSACTION;
SET FOREIGN_KEY_CHECKS = 0;

-- Roles normalizados, equivalentes a los definidos en schema.sql.
CREATE TABLE IF NOT EXISTS rol (
    id_rol INT NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(50) NOT NULL,
    descripcion VARCHAR(150),
    PRIMARY KEY (id_rol),
    UNIQUE (nombre)
);

CREATE TABLE IF NOT EXISTS usuario_rol (
    id_usuario INT NOT NULL,
    id_rol INT NOT NULL,
    sector VARCHAR(100) NOT NULL,
    fecha_desde DATE NOT NULL,
    fecha_hasta DATE DEFAULT NULL,
    PRIMARY KEY (id_usuario, id_rol, fecha_desde),
    CONSTRAINT fk_usuario_rol_usuario
        FOREIGN KEY (id_usuario)
        REFERENCES usuario(id_usuario),
    CONSTRAINT fk_usuario_rol_rol
        FOREIGN KEY (id_rol)
        REFERENCES rol(id_rol),
    CONSTRAINT chk_usuario_rol_fechas
        CHECK (fecha_hasta IS NULL OR fecha_hasta >= fecha_desde)
);

INSERT INTO rol (nombre, descripcion) VALUES
    ('Administrador', 'Acceso administrativo al sistema'),
    ('Operario', 'Gestion operativa de residuos')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- Conserva el rol vigente del esquema intermedio antes de quitar usuario.rol.
INSERT INTO usuario_rol (id_usuario, id_rol, sector, fecha_desde)
SELECT
    u.id_usuario,
    r.id_rol,
    'General',
    COALESCE(u.fecha_registro, CURDATE())
FROM usuario u
INNER JOIN rol r ON r.nombre = u.rol
LEFT JOIN usuario_rol ur
    ON ur.id_usuario = u.id_usuario
   AND ur.id_rol = r.id_rol
   AND ur.fecha_desde = COALESCE(u.fecha_registro, CURDATE())
WHERE u.rol IS NOT NULL
  AND ur.id_usuario IS NULL;

ALTER TABLE usuario DROP COLUMN rol;

-- El schema actual no maneja activo en usuario; la baja no es parte del modelo.
SET @drop_activo = (
        SELECT IF(COUNT(*) > 0,
                            'ALTER TABLE usuario DROP COLUMN activo',
                            'SELECT 1')
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
            AND table_name = 'usuario'
            AND column_name = 'activo'
);
PREPARE drop_activo_statement FROM @drop_activo;
EXECUTE drop_activo_statement;
DEALLOCATE PREPARE drop_activo_statement;

CREATE TABLE IF NOT EXISTS mantenimiento (
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
    id_contenedor INT DEFAULT NULL,
    id_maquinaria INT DEFAULT NULL,
    PRIMARY KEY (id_mantenimiento),
    CONSTRAINT fk_mantenimiento_vehiculo
        FOREIGN KEY (id_vehiculo)
        REFERENCES vehiculo(id_vehiculo),
    CONSTRAINT fk_mantenimiento_contenedor
        FOREIGN KEY (id_contenedor)
        REFERENCES contenedor(id_contenedor),
    CONSTRAINT fk_mantenimiento_maquinaria
        FOREIGN KEY (id_maquinaria)
        REFERENCES maquinaria(id_maquinaria),
    CONSTRAINT chk_mantenimiento_recurso
        CHECK (
            (id_vehiculo IS NOT NULL) +
            (id_contenedor IS NOT NULL) +
            (id_maquinaria IS NOT NULL) = 1
        ),
    CONSTRAINT chk_mantenimiento_fechas
        CHECK (fecha_fin IS NULL OR fecha_fin >= fecha_inicio)
);

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;
