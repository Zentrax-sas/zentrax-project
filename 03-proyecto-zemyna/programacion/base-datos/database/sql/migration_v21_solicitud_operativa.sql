-- V21 / F6.1, MariaDB 10.4.32. Aplicación única con respaldo y escritores detenidos.
-- Sin UPDATE/DELETE de solicitudes, sin backfill de intentos. DDL no es transaccional.
-- No usar --force: el precheck debe detener al cliente ante instalación parcial/repetida.
CREATE TEMPORARY TABLE zemyna_v21_guard (ok TINYINT NOT NULL CHECK (ok=1));
INSERT INTO zemyna_v21_guard
SELECT DATABASE() IS NOT NULL
 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('solicitud','cuadrilla','usuario','asignacion_vehiculo_operativa','rol','permiso','rol_permiso'))=7
 AND NOT EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='atencion_solicitud')
 AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='solicitud' AND COLUMN_NAME IN ('id_cuadrilla','fecha_finalizacion','fecha_cancelacion','id_usuario_cancela','motivo_cancelacion','fecha_confirmacion_residuo','id_usuario_confirma_residuo'));
DROP TEMPORARY TABLE zemyna_v21_guard;

-- BEGIN V21 STRUCTURE
ALTER TABLE solicitud
 MODIFY estado ENUM('Pendiente','Programada','En atención','Finalizada','Cancelada') NOT NULL,
 ADD id_cuadrilla INT NULL,
 ADD fecha_finalizacion DATETIME NULL,
 ADD fecha_cancelacion DATETIME NULL,
 ADD id_usuario_cancela INT NULL,
 ADD motivo_cancelacion VARCHAR(500) NULL,
 ADD fecha_confirmacion_residuo DATETIME NULL,
 ADD id_usuario_confirma_residuo INT NULL,
 ADD KEY idx_solicitud_bandeja (estado,fecha,id_solicitud),
 ADD KEY idx_solicitud_propia (id_cuadrilla,estado,fecha,id_solicitud),
 ADD CONSTRAINT fk_solicitud_cuadrilla FOREIGN KEY (id_cuadrilla) REFERENCES cuadrilla(id_cuadrilla) ON DELETE RESTRICT,
 ADD CONSTRAINT fk_solicitud_cancela FOREIGN KEY (id_usuario_cancela) REFERENCES usuario(id_usuario) ON DELETE RESTRICT,
 ADD CONSTRAINT fk_solicitud_confirma FOREIGN KEY (id_usuario_confirma_residuo) REFERENCES usuario(id_usuario) ON DELETE RESTRICT,
 ADD CONSTRAINT chk_solicitud_finalizacion CHECK (fecha_finalizacion IS NULL OR (estado='Finalizada' AND fecha_finalizacion>=fecha)),
 ADD CONSTRAINT chk_solicitud_cancelacion CHECK (
   (fecha_cancelacion IS NULL AND id_usuario_cancela IS NULL AND motivo_cancelacion IS NULL) OR
   (estado='Cancelada' AND fecha_cancelacion IS NOT NULL AND id_usuario_cancela IS NOT NULL AND motivo_cancelacion IS NOT NULL AND CHAR_LENGTH(TRIM(motivo_cancelacion)) BETWEEN 1 AND 500 AND fecha_cancelacion>=fecha)),
 ADD CONSTRAINT chk_solicitud_confirmacion CHECK (
   (fecha_confirmacion_residuo IS NULL AND id_usuario_confirma_residuo IS NULL) OR
   (fecha_confirmacion_residuo IS NOT NULL AND fecha_confirmacion_residuo>=fecha));

CREATE TABLE atencion_solicitud (
 id_atencion_solicitud INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 id_solicitud INT NOT NULL,
 id_cuadrilla INT NOT NULL,
 id_asignacion_vehiculo INT NOT NULL,
 estado ENUM('Asignada','Aceptada','En atención','Finalizada','Rechazada','Interrumpida') NOT NULL,
 fecha_asignacion DATETIME NOT NULL,
 id_usuario_asigna INT NOT NULL,
 fecha_aceptacion DATETIME NULL,
 id_usuario_acepta INT NULL,
 fecha_inicio DATETIME NULL,
 id_usuario_inicia INT NULL,
 fecha_cierre DATETIME NULL,
 id_usuario_cierra INT NULL,
 motivo_cierre VARCHAR(500) NULL,
 solicitud_abierta INT GENERATED ALWAYS AS (IF(fecha_cierre IS NULL,id_solicitud,NULL)) PERSISTENT,
 UNIQUE KEY uq_as_abierta (solicitud_abierta),
 KEY idx_as_historial (id_solicitud,id_atencion_solicitud),
 KEY idx_as_v19 (id_asignacion_vehiculo,fecha_cierre),
 CONSTRAINT fk_as_solicitud FOREIGN KEY (id_solicitud) REFERENCES solicitud(id_solicitud) ON DELETE RESTRICT,
 CONSTRAINT fk_as_cuadrilla FOREIGN KEY (id_cuadrilla) REFERENCES cuadrilla(id_cuadrilla) ON DELETE RESTRICT,
 CONSTRAINT fk_as_v19 FOREIGN KEY (id_asignacion_vehiculo) REFERENCES asignacion_vehiculo_operativa(id_asignacion_vehiculo) ON DELETE RESTRICT,
 CONSTRAINT fk_as_asigna FOREIGN KEY (id_usuario_asigna) REFERENCES usuario(id_usuario) ON DELETE RESTRICT,
 CONSTRAINT fk_as_acepta FOREIGN KEY (id_usuario_acepta) REFERENCES usuario(id_usuario) ON DELETE RESTRICT,
 CONSTRAINT fk_as_inicia FOREIGN KEY (id_usuario_inicia) REFERENCES usuario(id_usuario) ON DELETE RESTRICT,
 CONSTRAINT fk_as_cierra FOREIGN KEY (id_usuario_cierra) REFERENCES usuario(id_usuario) ON DELETE RESTRICT,
 CONSTRAINT chk_as_acepta CHECK ((fecha_aceptacion IS NULL AND id_usuario_acepta IS NULL) OR (fecha_aceptacion IS NOT NULL AND id_usuario_acepta IS NOT NULL)),
 CONSTRAINT chk_as_inicia CHECK ((fecha_inicio IS NULL AND id_usuario_inicia IS NULL) OR (fecha_inicio IS NOT NULL AND id_usuario_inicia IS NOT NULL AND fecha_aceptacion IS NOT NULL)),
 CONSTRAINT chk_as_cierra CHECK ((fecha_cierre IS NULL AND id_usuario_cierra IS NULL AND estado IN ('Asignada','Aceptada','En atención')) OR (fecha_cierre IS NOT NULL AND id_usuario_cierra IS NOT NULL AND estado IN ('Finalizada','Rechazada','Interrumpida'))),
 CONSTRAINT chk_as_hitos CHECK (
   (estado IN ('Asignada','Rechazada') AND fecha_aceptacion IS NULL AND fecha_inicio IS NULL) OR
   (estado='Aceptada' AND fecha_aceptacion IS NOT NULL AND fecha_inicio IS NULL) OR
   (estado IN ('En atención','Finalizada') AND fecha_aceptacion IS NOT NULL AND fecha_inicio IS NOT NULL) OR estado='Interrumpida'),
 CONSTRAINT chk_as_motivo CHECK ((estado IN ('Rechazada','Interrumpida') AND motivo_cierre IS NOT NULL AND CHAR_LENGTH(TRIM(motivo_cierre)) BETWEEN 1 AND 500) OR (estado NOT IN ('Rechazada','Interrumpida') AND motivo_cierre IS NULL)),
 CONSTRAINT chk_as_fechas CHECK ((fecha_aceptacion IS NULL OR fecha_aceptacion>=fecha_asignacion) AND (fecha_inicio IS NULL OR (fecha_aceptacion IS NOT NULL AND fecha_inicio>=fecha_aceptacion)) AND (fecha_cierre IS NULL OR fecha_cierre>=COALESCE(fecha_inicio,fecha_aceptacion,fecha_asignacion)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- END V21 STRUCTURE

-- BEGIN V21 GRANTS
START TRANSACTION;
INSERT INTO permiso (nombre,descripcion) VALUES
 ('solicitud.consultar','Consultar solicitudes de retiro especial en Operaciones'),
 ('solicitud.modificar','Evaluar, asignar y cancelar solicitudes de retiro especial'),
 ('solicitud.operar','Consultar y atender solicitudes propias de la cuadrilla vigente')
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion);
INSERT IGNORE INTO rol_permiso (id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso FROM rol r CROSS JOIN permiso p
WHERE (r.nombre IN ('RESPONSABLE_SECTORIAL','ADMINISTRATIVO_OPERATIVO') AND p.nombre IN ('solicitud.consultar','solicitud.modificar'))
 OR (r.nombre='OPERARIO' AND p.nombre='solicitud.operar');
COMMIT;
-- END V21 GRANTS
