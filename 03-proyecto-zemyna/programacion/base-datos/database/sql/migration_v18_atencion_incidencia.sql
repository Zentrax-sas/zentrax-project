-- v18: F4.2. MariaDB 10.4. Aplicar sin escritores concurrentes y con respaldo.
-- DDL hace commit implícito. No reconstruye asignaciones ni hitos históricos.
CREATE TABLE IF NOT EXISTS atencion_incidencia (
    id_atencion_incidencia INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_incidencia INT NOT NULL,
    id_cuadrilla INT NOT NULL,
    estado ENUM('Asignada','Aceptada','En atención','Finalizada','Rechazada','Interrumpida') NOT NULL,
    origen ENUM('Asignacion','Reapertura','Reinicio administrativo','Migracion') NOT NULL,
    fecha_registro DATETIME NOT NULL,
    id_usuario_registra INT DEFAULT NULL,
    fecha_aceptacion DATETIME DEFAULT NULL,
    id_usuario_acepta INT DEFAULT NULL,
    fecha_inicio DATETIME DEFAULT NULL,
    id_usuario_inicia INT DEFAULT NULL,
    fecha_cierre DATETIME DEFAULT NULL,
    id_usuario_cierra INT DEFAULT NULL,
    motivo_cierre VARCHAR(500) DEFAULT NULL,
    incidencia_abierta INT GENERATED ALWAYS AS (IF(fecha_cierre IS NULL, id_incidencia, NULL)) PERSISTENT,
    UNIQUE KEY uq_ai_abierta (incidencia_abierta),
    KEY idx_ai_historial (id_incidencia, id_atencion_incidencia),
    CONSTRAINT fk_ai_incidencia FOREIGN KEY (id_incidencia) REFERENCES incidencia(id_incidencia) ON DELETE RESTRICT,
    CONSTRAINT fk_ai_cuadrilla FOREIGN KEY (id_cuadrilla) REFERENCES cuadrilla(id_cuadrilla) ON DELETE RESTRICT,
    CONSTRAINT fk_ai_registra FOREIGN KEY (id_usuario_registra) REFERENCES usuario(id_usuario) ON DELETE RESTRICT,
    CONSTRAINT fk_ai_acepta FOREIGN KEY (id_usuario_acepta) REFERENCES usuario(id_usuario) ON DELETE RESTRICT,
    CONSTRAINT fk_ai_inicia FOREIGN KEY (id_usuario_inicia) REFERENCES usuario(id_usuario) ON DELETE RESTRICT,
    CONSTRAINT fk_ai_cierra FOREIGN KEY (id_usuario_cierra) REFERENCES usuario(id_usuario) ON DELETE RESTRICT,
    CONSTRAINT chk_ai_origen CHECK ((origen='Migracion' AND id_usuario_registra IS NULL) OR (origen<>'Migracion' AND id_usuario_registra IS NOT NULL)),
    CONSTRAINT chk_ai_acepta CHECK ((fecha_aceptacion IS NULL AND id_usuario_acepta IS NULL) OR (fecha_aceptacion IS NOT NULL AND id_usuario_acepta IS NOT NULL)),
    CONSTRAINT chk_ai_inicia CHECK ((fecha_inicio IS NULL AND id_usuario_inicia IS NULL) OR (fecha_inicio IS NOT NULL AND id_usuario_inicia IS NOT NULL AND fecha_aceptacion IS NOT NULL)),
    CONSTRAINT chk_ai_cierra CHECK ((fecha_cierre IS NULL AND id_usuario_cierra IS NULL AND estado IN ('Asignada','Aceptada','En atención')) OR (fecha_cierre IS NOT NULL AND id_usuario_cierra IS NOT NULL AND estado IN ('Finalizada','Rechazada','Interrumpida'))),
    CONSTRAINT chk_ai_hitos CHECK ((estado IN ('Asignada','Rechazada') AND fecha_aceptacion IS NULL AND fecha_inicio IS NULL) OR (estado='Aceptada' AND fecha_aceptacion IS NOT NULL AND fecha_inicio IS NULL) OR (estado IN ('En atención','Finalizada') AND fecha_aceptacion IS NOT NULL AND fecha_inicio IS NOT NULL) OR estado='Interrumpida'),
    CONSTRAINT chk_ai_motivo CHECK ((estado IN ('Rechazada','Interrumpida') AND motivo_cierre IS NOT NULL AND CHAR_LENGTH(TRIM(motivo_cierre)) BETWEEN 1 AND 500) OR (estado NOT IN ('Rechazada','Interrumpida') AND motivo_cierre IS NULL)),
    CONSTRAINT chk_ai_fechas CHECK ((fecha_aceptacion IS NULL OR fecha_aceptacion>=fecha_registro) AND (fecha_inicio IS NULL OR fecha_inicio>=fecha_aceptacion) AND (fecha_cierre IS NULL OR fecha_cierre>=COALESCE(fecha_inicio,fecha_aceptacion,fecha_registro)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

INSERT INTO permiso (nombre, descripcion)
SELECT 'incidencia.operar', 'Aceptar, rechazar, iniciar y finalizar incidencias de la cuadrilla vigente'
WHERE NOT EXISTS (SELECT 1 FROM permiso WHERE nombre='incidencia.operar');
INSERT IGNORE INTO rol_permiso (id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso FROM rol r CROSS JOIN permiso p
WHERE r.nombre IN ('OPERARIO','RESPONSABLE_SECTORIAL','ADMINISTRATIVO_OPERATIVO') AND p.nombre='incidencia.operar';
-- No se concede a ADMINISTRADOR_TI. El backend exige sector, elegibilidad y pertenencia reales.

-- UTC-03:00 corresponde a America/Montevideo para la incorporación actual;
-- no depende de que MariaDB tenga cargadas tablas de zonas horarias.
INSERT INTO atencion_incidencia (id_incidencia,id_cuadrilla,estado,origen,fecha_registro)
SELECT i.id_incidencia,i.id_cuadrilla,'Asignada','Migracion',DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 HOUR)
FROM incidencia i
WHERE i.estado IN ('Pendiente','En Proceso') AND i.id_cuadrilla IS NOT NULL
AND NOT EXISTS (SELECT 1 FROM atencion_incidencia a WHERE a.id_incidencia=i.id_incidencia);
-- Reejecutable: no incorpora un segundo intento ni inventa historia resuelta.

COMMIT;
