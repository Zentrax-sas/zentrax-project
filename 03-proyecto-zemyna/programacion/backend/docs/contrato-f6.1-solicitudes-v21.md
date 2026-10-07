# F6.1 — estructura V21, residuo explícito y permisos

Este bloque no publica bandeja, asignación, consulta propia ni transiciones F6.
Solicitud e incidencia conservan tablas distintas. AtencionSolicitud ofrece solo
lectura interna del historial, sin endpoint ni concesión de acceso por sí misma.

## SQL y despliegue

SQL exacto: `../../base-datos/database/sql/migration_v21_solicitud_operativa.sql`.
MariaDB 10.4.32. Aplicación única sobre V20, con V19 presente y roles cargados.
El guard rechaza campos V21 ya existentes o tabla de atención instalada antes de
ejecutar DDL. No usar `--force`: el cliente debe detenerse al primer error.
No es una migración reejecutable de DDL. Ante ejecución parcial, inspeccionar y
recuperar explícitamente; no omitir errores ni volver a ejecutar a ciegas.

DDL tiene commit implícito. Los permisos se agregan posteriormente en una
transacción y su sección de concesiones sí puede repetirse. `schema.sql` contiene
la misma sección estructural y `init.sql` las mismas concesiones tras los roles.
El instalador limpio elimina tablas: nunca usarlo para migrar la BD habitual.

Solicitud incorpora cuadrilla actual, finalización, cancelación administrativa
(fecha/actor/motivo) y confirmación de residuo (fecha/actor). Campos nuevos NULL
para históricos. No se exige cuadrilla a Programada ni fecha a terminales
históricos. Fechas de cierre presentes requieren estado y cronología correctos;
cancelación presente exige los tres datos. Actor de confirmación exige fecha.

Atención conserva solicitud, cuadrilla histórica y FK a la versión V19,
Asignada/Aceptada/En atención/Finalizada/Rechazada/Interrumpida, pares fecha/actor
de asignación, aceptación, inicio y cierre, y motivo de cierre. La columna
generada persistente solicitud_abierta y UNIQUE limitan a un intento abierto.
CHECK verifican hitos, pares, cierre, motivo y cronología con NULL explícitos;
FK RESTRICT preservan recursos e historia. No se modifica V19 ni se agrega
vehículo libre. Correspondencia cuadrilla/V19, permisos, ownership y transiciones
se validarán transaccionalmente en los bloques operativos posteriores.

## Alta pública

POST `api/solicitud.php` mantiene CAPTCHA y requiere explícitamente
`id_tipo_residuo`: entero positivo o string decimal positivo hasta 2147483647,
existente en tipo_residuo. No hay inferencia por palabras, alias ni fallback.
Los demás campos son descripción, dirección, email, teléfono y tipo_solicitud
(`Gran volumen`/`Reciclables`). El servidor ignora estado, fechas, referencia,
autores, cuadrilla y campos de confirmación suministrados por cliente.

La fecha de creación y fecha_confirmacion_residuo se escriben con el mismo
instante servidor America/Montevideo. id_usuario_confirma_residuo queda NULL:
representa declaración explícita del vecino. La respuesta mantiene HTTP 201 y
tracking REF. El tracking público conserva exclusivamente referencia, estado,
fecha_reporte y tipo_problema; no agrega datos personales ni operativos.

El backend verifica existencia, no una matriz servicio/residuo. La coherencia
del servicio y categorías especiales requiere una política posterior: no se
afirma admisión de electrónicos, baterías o escombros.

## Formulario y catálogo

Se reutiliza GET `api/tipos_residuo.php` existente, sin crear un endpoint.
Reciclables ofrece Papel y cartón, Plástico, Vidrio y Metal. Gran volumen
selecciona Residuos voluminosos, mostrando esa categoría. IDs siempre tomados
del catálogo recibido, nunca de constantes. Nombres de alcance están definidos
en solicitud-residuos.js; un cambio de nombre exige revisar esa configuración.
Categorías ausentes/duplicadas se excluyen. Si no hay opción inequívoca, se
bloquea el envío y se ofrece actualizar catálogo. Cambiar servicio limpia la
selección anterior. No se admite multirresiduo ni se infiere desde descripción.

## Permisos iniciales

- RESPONSABLE_SECTORIAL y ADMINISTRATIVO_OPERATIVO: solicitud.consultar/modificar.
- OPERARIO: únicamente solicitud.operar.
- INSPECTOR y ADMINISTRADOR_TI: ninguna concesión F6.

Se buscan roles/permisos por nombre, sin IDs fijos. No se modifican concesiones
ajenas. El runtime futuro exigirá permisos reales y vigentes en OPERACIONES;
este bloque no introduce bypass TI ni endpoints administrativos.

## Históricos y aplicación futura

V21 no actualiza ni elimina solicitudes, ni crea intentos o confirmaciones.
Referencias, estados (incluidos casos incompletos), residuo y datos originales
se conservan. Una histórica con confirmación NULL requiere revisión F6.2 antes
de asignarse. La recuperación de Programada sin intento también queda para F6.2.

Procedimiento propuesto para gestion_residuosfinal (NO ejecutado):
1. Autorizar ventana, detener escritores y respaldar estructura/datos/grants.
2. Verificar V20/V19, tipos PK/FK, roles, ausencia de V21 parcial y espacio.
3. Ejecutar V21 con cliente que detenga al primer error, sin --force.
4. Verificar SHOW CREATE, grants y comparación de todas las filas históricas.
5. Desplegar/activar código y formulario coordinadamente; probar con datos nuevos
   autorizados. Hasta V21, el nuevo INSERT no es compatible con la estructura V20.
6. Ante fallo, mantener escritores detenidos: DDL no hace rollback automático.
   Recuperar desde respaldo o reparar el estado parcial de forma revisada.

## Validación aislada

SolicitudV21MariaDbTest solo acepta @@datadir bajo TEMP/zemyna-f61-<hex>/data/;
crea y elimina bases aleatorias f61_test_<hex>. Configurar
ZEMYNA_F61_MARIADB_DSN y opcional ZEMYNA_F61_MARIADB_PASSWORD del servidor temporal.
Sin DSN omite la prueba; un servidor habitual se rechaza antes de crear una BD.
Nunca ejecutar estas pruebas contra gestion_residuosfinal.

Se verifica preservación de nueve históricos representativos, grants exactos,
paridad migración/schema, integridad y alta/tracking reales. Pruebas dirigidas
de controlador y seguridad F1 conservan el estado/fecha/tracking servidor.
Frontend ejecuta HTML y scripts reales con catálogo de IDs desplazados.
