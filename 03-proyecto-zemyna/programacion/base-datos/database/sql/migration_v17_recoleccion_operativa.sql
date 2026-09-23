-- v17: MariaDB 10.4. Respaldo completo verificado antes de aplicar (DDL hace commit implícito).
-- No crea pertenencias ni modifica fechas/estados/autores históricos.
ALTER TABLE recorrido
    ADD COLUMN IF NOT EXISTS id_usuario_inicio INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS id_usuario_fin INT DEFAULT NULL;
SET @recoleccion_ddl = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'recorrido' AND CONSTRAINT_NAME = 'fk_recorrido_usuario_inicio') = 0, 'ALTER TABLE recorrido ADD CONSTRAINT fk_recorrido_usuario_inicio FOREIGN KEY (id_usuario_inicio) REFERENCES usuario(id_usuario)', 'DO 0');
PREPARE recoleccion_stmt FROM @recoleccion_ddl;
EXECUTE recoleccion_stmt;
DEALLOCATE PREPARE recoleccion_stmt;
SET @recoleccion_ddl = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'recorrido' AND CONSTRAINT_NAME = 'fk_recorrido_usuario_fin') = 0, 'ALTER TABLE recorrido ADD CONSTRAINT fk_recorrido_usuario_fin FOREIGN KEY (id_usuario_fin) REFERENCES usuario(id_usuario)', 'DO 0');
PREPARE recoleccion_stmt FROM @recoleccion_ddl;
EXECUTE recoleccion_stmt;
DEALLOCATE PREPARE recoleccion_stmt;


CREATE TABLE IF NOT EXISTS usuario_cuadrilla (
    id_usuario_cuadrilla INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    id_cuadrilla INT NOT NULL,
    fecha_inicio DATETIME NOT NULL,
    fecha_fin DATETIME DEFAULT NULL,
    id_usuario_asigna INT NOT NULL,
    id_usuario_finaliza INT DEFAULT NULL,
    usuario_vigente INT GENERATED ALWAYS AS (IF(fecha_fin IS NULL, id_usuario, NULL)) PERSISTENT,
    UNIQUE KEY uq_usuario_cuadrilla_vigente (usuario_vigente),
    KEY idx_usuario_cuadrilla_historial (id_cuadrilla, fecha_fin, id_usuario),
    CONSTRAINT fk_uc_usuario FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario),
    CONSTRAINT fk_uc_cuadrilla FOREIGN KEY (id_cuadrilla) REFERENCES cuadrilla(id_cuadrilla),
    CONSTRAINT fk_uc_asigna FOREIGN KEY (id_usuario_asigna) REFERENCES usuario(id_usuario),
    CONSTRAINT fk_uc_finaliza FOREIGN KEY (id_usuario_finaliza) REFERENCES usuario(id_usuario),
    CONSTRAINT chk_uc_fechas CHECK (fecha_fin IS NULL OR fecha_fin >= fecha_inicio),
    CONSTRAINT chk_uc_cierre CHECK ((fecha_fin IS NULL AND id_usuario_finaliza IS NULL) OR (fecha_fin IS NOT NULL AND id_usuario_finaliza IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS atencion_contenedor (
    id_atencion_contenedor INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_recorrido INT NOT NULL,
    id_contenedor INT NOT NULL,
    id_usuario INT NOT NULL,
    fecha_atencion DATETIME NOT NULL,
    UNIQUE KEY uq_atencion_recorrido_contenedor (id_recorrido, id_contenedor),
    CONSTRAINT fk_atencion_recorrido FOREIGN KEY (id_recorrido) REFERENCES recorrido(id_recorrido),
    CONSTRAINT fk_atencion_contenedor FOREIGN KEY (id_contenedor) REFERENCES contenedor(id_contenedor),
    CONSTRAINT fk_atencion_usuario FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permiso (nombre, descripcion)
SELECT 'recorrido.operar', 'Iniciar, atender y finalizar recorridos de la cuadrilla vigente'
WHERE NOT EXISTS (SELECT 1 FROM permiso WHERE nombre = 'recorrido.operar');
INSERT IGNORE INTO rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso FROM rol r CROSS JOIN permiso p
WHERE r.nombre IN ('OPERARIO', 'ADMINISTRADOR_TI', 'RESPONSABLE_SECTORIAL', 'ADMINISTRATIVO_OPERATIVO') AND p.nombre = 'recorrido.operar';
-- El endpoint exige sector OPERACIONES y pertenencia vigente incluso para administradores.
