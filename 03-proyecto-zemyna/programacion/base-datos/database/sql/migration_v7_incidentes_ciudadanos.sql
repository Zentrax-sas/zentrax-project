-- Permite incidencias ciudadanas sin cuenta ni asignacion operativa inicial.
-- Conserva las claves foraneas y todos los registros existentes.

ALTER TABLE incidencia
    MODIFY COLUMN id_cuadrilla INT NULL,
    MODIFY COLUMN id_usuario INT NULL;
