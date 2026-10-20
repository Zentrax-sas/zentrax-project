# Consentimiento WhatsApp

El alta de incidencia y retiro guarda `consentimiento_whatsapp` (0/1) junto con `telefono` y `tracking_number` en la misma fila de `incidencia` o `solicitud`. No se envían mensajes, no se llama a Meta y no se requieren credenciales ni dependencias adicionales.

El cliente envía booleano en JSON para incidencias y `0`/`1` en multipart para retiros. La ausencia del campo significa falso. El backend rechaza valores ambiguos y consentimiento verdadero sin teléfono válido. El teléfono continúa siendo opcional cuando no hay consentimiento.

Aplicar una sola vez `database/migrations/20261012_consentimiento_whatsapp.sql` en la base configurada antes de desplegar el código. La migración es aditiva: agrega `incidencia.telefono` y el booleano NOT NULL DEFAULT 0 en ambas tablas; no altera teléfonos ni trackings existentes. Los registros históricos no adquieren consentimiento.

`SolicitudRetiroController` ya no invoca el helper histórico `WhatsAppService`. Un servicio de mensajería que se conecte posteriormente deberá consultar consentimiento y teléfono del trámite; la mera presencia de teléfono nunca autoriza un envío. Las consultas públicas de tracking no exponen teléfono ni consentimiento.

Validación de persistencia MySQL: activar `ZEMYNA_CONSENTIMIENTO_TEST=1` y ejecutar PHPUnit. `ConsentimientoWhatsAppPersistenciaTest` usa solamente `zemyna_consentimiento_test`, crea sus tablas si faltan y revierte las escrituras de cada prueba mediante transacciones. Las demás pruebas unitarias no requieren base real.
