-- V19: no clasifica vehiculos ni inventa utilizaciones. DDL hace commit implicito.
ALTER TABLE vehiculo ADD COLUMN IF NOT EXISTS funcion_operativa VARCHAR(7) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL;
SET @v19_ddl = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='vehiculo' AND CONSTRAINT_NAME='chk_vehiculo_funcion')=0,
 'ALTER TABLE vehiculo ADD CONSTRAINT chk_vehiculo_funcion CHECK (funcion_operativa IS NULL OR (BINARY funcion_operativa = BINARY ''REGULAR'' AND OCTET_LENGTH(funcion_operativa)=7) OR (BINARY funcion_operativa = BINARY ''APOYO'' AND OCTET_LENGTH(funcion_operativa)=5))', 'DO 0');
PREPARE v19_stmt FROM @v19_ddl;
EXECUTE v19_stmt;
DEALLOCATE PREPARE v19_stmt;

CREATE TABLE IF NOT EXISTS asignacion_vehiculo_operativa (
    id_asignacion_vehiculo INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_cuadrilla INT NOT NULL,
    id_vehiculo INT NOT NULL,
    fecha_inicio DATETIME NOT NULL,
    fecha_fin DATETIME DEFAULT NULL,
    id_usuario_asigna INT NOT NULL,
    id_usuario_finaliza INT DEFAULT NULL,
    motivo_cierre VARCHAR(150) DEFAULT NULL,
    cuadrilla_abierta INT GENERATED ALWAYS AS (IF(fecha_fin IS NULL,id_cuadrilla,NULL)) PERSISTENT,
    vehiculo_abierto INT GENERATED ALWAYS AS (IF(fecha_fin IS NULL,id_vehiculo,NULL)) PERSISTENT,
    UNIQUE KEY uq_avo_cuadrilla_abierta (cuadrilla_abierta),
    UNIQUE KEY uq_avo_vehiculo_abierto (vehiculo_abierto),
    KEY idx_avo_cuadrilla_historial (id_cuadrilla,fecha_inicio,id_asignacion_vehiculo),
    KEY idx_avo_vehiculo_historial (id_vehiculo,fecha_inicio,id_asignacion_vehiculo),
    CONSTRAINT fk_avo_cuadrilla FOREIGN KEY (id_cuadrilla) REFERENCES cuadrilla(id_cuadrilla) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_avo_vehiculo FOREIGN KEY (id_vehiculo) REFERENCES vehiculo(id_vehiculo) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_avo_asigna FOREIGN KEY (id_usuario_asigna) REFERENCES usuario(id_usuario) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_avo_finaliza FOREIGN KEY (id_usuario_finaliza) REFERENCES usuario(id_usuario) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_avo_fechas CHECK (fecha_fin IS NULL OR fecha_fin>=fecha_inicio),
    CONSTRAINT chk_avo_cierre CHECK ((fecha_fin IS NULL AND id_usuario_finaliza IS NULL AND motivo_cierre IS NULL) OR
        (fecha_fin IS NOT NULL AND id_usuario_finaliza IS NOT NULL AND motivo_cierre IS NOT NULL AND CHAR_LENGTH(TRIM(motivo_cierre)) BETWEEN 1 AND 150))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;
INSERT INTO permiso (nombre,descripcion)
SELECT 'asignacion_vehiculo.consultar','Consultar utilizacion operativa de vehiculos'
WHERE NOT EXISTS (SELECT 1 FROM permiso WHERE nombre='asignacion_vehiculo.consultar');
INSERT INTO permiso (nombre,descripcion)
SELECT 'asignacion_vehiculo.modificar','Abrir, cambiar y cerrar utilizacion de vehiculos'
WHERE NOT EXISTS (SELECT 1 FROM permiso WHERE nombre='asignacion_vehiculo.modificar');
INSERT IGNORE INTO rol_permiso (id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso FROM rol r CROSS JOIN permiso p
WHERE r.nombre IN ('RESPONSABLE_SECTORIAL','ADMINISTRATIVO_OPERATIVO')
AND p.nombre IN ('asignacion_vehiculo.consultar','asignacion_vehiculo.modificar');
COMMIT;
