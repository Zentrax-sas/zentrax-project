-- Aditiva, sin bajas ni cambios a fechas/tracking existentes.
ALTER TABLE incidencia MODIFY estado ENUM('Pendiente','En Proceso','Resuelta','Cancelada') NOT NULL;
ALTER TABLE incidencia ADD COLUMN fecha_cancelacion DATETIME(6) NULL;
ALTER TABLE solicitud ADD COLUMN fecha_cancelacion DATETIME(6) NULL;
