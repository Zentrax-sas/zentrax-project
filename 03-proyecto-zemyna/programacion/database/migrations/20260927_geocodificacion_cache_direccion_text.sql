-- Conserva display_name completo. TEXT admite hasta 65535 bytes, sin truncado.
-- No cambia las otras columnas, claves ni datos existentes.
ALTER TABLE geocodificacion_cache MODIFY COLUMN direccion TEXT NOT NULL;
