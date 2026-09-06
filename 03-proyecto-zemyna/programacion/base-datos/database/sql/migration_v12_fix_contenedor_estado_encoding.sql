-- Migracion v12: corrige el literal corrupto del estado Dañado.
-- Precondicion operativa: crear y verificar un respaldo completo antes de ejecutar.
-- ALTER TABLE provoca commit implicito en MySQL/MariaDB; ejecutar sin --force y detener ante cualquier error.

-- 1. Admitir temporalmente ambas representaciones sin modificar los otros estados.
ALTER TABLE contenedor
    MODIFY estado ENUM(
        'Disponible',
        'Lleno',
        'Da├▒ado',
        'Dañado',
        'Fuera de Servicio'
    ) NOT NULL;

-- 2. Corregir exclusivamente filas cuyos bytes corresponden al literal corrupto.
UPDATE contenedor
SET estado = 'Dañado'
WHERE HEX(estado) = '4461E2949CE2969261646F';

-- 3. Dejar solamente el contrato definitivo.
ALTER TABLE contenedor
    MODIFY estado ENUM(
        'Disponible',
        'Lleno',
        'Dañado',
        'Fuera de Servicio'
    ) NOT NULL;

-- Verificacion: debe devolver cero variantes corruptas y conservar el total de filas.
SELECT COUNT(*) AS estados_corruptos
FROM contenedor
WHERE HEX(estado) = '4461E2949CE2969261646F';

SELECT estado, COUNT(*) AS cantidad
FROM contenedor
GROUP BY estado
ORDER BY estado;
