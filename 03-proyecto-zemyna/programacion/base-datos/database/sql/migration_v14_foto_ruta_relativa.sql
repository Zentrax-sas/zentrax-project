-- Migración v14: conserva en foto.url únicamente nombres seguros de archivo.
-- No crea, elimina ni mueve fotografías y no modifica otras columnas.

START TRANSACTION;

UPDATE foto
SET url = SUBSTRING_INDEX(REPLACE(url, '\\', '/'), '/', -1)
WHERE url <> SUBSTRING_INDEX(REPLACE(url, '\\', '/'), '/', -1)
  AND SUBSTRING_INDEX(REPLACE(url, '\\', '/'), '/', -1)
      REGEXP '^[A-Za-z0-9][A-Za-z0-9._-]*\\.(jpg|jpeg|png|webp)$';

COMMIT;

SELECT id_foto, url
FROM foto
ORDER BY id_foto;
