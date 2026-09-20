-- Requiere respaldo completo verificado antes de ejecutar (DDL con commit implícito).
-- Fecha del ciclo actual, America/Montevideo: se limpia al reabrir.
-- No se reconstruyen fechas históricas ni se usa fecha_reporte como resolución.
ALTER TABLE incidencia ADD COLUMN IF NOT EXISTS fecha_resolucion DATETIME NULL DEFAULT NULL;
