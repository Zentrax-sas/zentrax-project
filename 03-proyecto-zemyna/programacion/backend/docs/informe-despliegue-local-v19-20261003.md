# V19 — Despliegue local controlado, 2026-10-03

V19 aplicada sobre `gestion_residuosfinal`, con respaldo verificado y validación
HTTP/visual sobre Apache local. Horarios expresados en America/Montevideo.
No se realizaron cambios de implementación durante esta validación.

## A. Precheck

HEAD inicial: `743a425`. Git mostraba únicamente los cambios V19 esperados, sin
staging. `git diff --check` pasó. Apache activo y login servido con HTTP 200.
MariaDB activa, versión `10.4.32-MariaDB`; conexión confirmada a
`gestion_residuosfinal`. La tabla V18 `atencion_incidencia` existía con sus
constraints; la tabla V19 y la columna `funcion_operativa` todavía no existían.
No se descartaron ni sobrescribieron cambios previos.

## B. Backup PRE-V19

Ruta exacta:
`C:\Users\facun\AppData\Local\Temp\zemyna-backups\gestion_residuosfinal_PRE-V19_20261003_130110.sql`.

Tamaño: **1.956.182 bytes**. `mysqldump.exe` terminó con **código 0**;
archivo existente y no vacío. Dump completo con `--single-transaction`,
`--routines`, `--events`, `--triggers`, `--hex-blob` y UTF-8.
SHA-256: `a833c042da13984e7564bad328102afcfeaf02f608b1afef9a5e440f1eb72d2d`.

El esquema de respaldo existente corresponde a Linux/VM (`/var/backups/zemyna`);
no se encontró un almacén equivalente de dumps locales en el proyecto.
Se utilizó un directorio local separado del contenido público de Apache.
Está dentro de Temp: conservar o trasladar este respaldo antes de limpiar Temp.
No se borraron respaldos anteriores ni se accedió a la VM.

## C. Estado previo de MariaDB

| Tabla | Filas previas |
|---|---:|
| vehiculo | 7 |
| usa | 4 |
| participa | 4 |
| recorrido | 4 |
| incidencia | 17 |
| atencion_incidencia | 5 |
| permiso | 49 |
| rol_permiso | 119 |

Recorridos: 1 Pendiente, 1 En Proceso, 1 Finalizado y 1 Cancelado.
Vehículos previos: IDs 1, 2, 3, 4, 11, 17 y 18. Inactivos: 3, 4 y 18.
Roles existentes incluidos TI, Administrativo Operativo y Responsable Sectorial
de Operaciones, Operario e Inspector. No se corrigieron datos previos.

## D. Resultado exacto de la migración

Se ejecutó exclusivamente `migration_v19_asignacion_vehiculo.sql` mediante PDO
sobre la conexión comprobada. `PDO::exec` devolvió **0**, sin excepción; el proceso
terminó con código 0. No se ejecutó `schema.sql` ni otra migración.
SHA-256 de la migración ejecutada:
`47e011139f87d7cf940aa06a7e08ed748aa3af477e62901feafe68761e321c8b`.

## E. Estructura desplegada

`SHOW CREATE TABLE` de ambas tablas quedó guardado en la evidencia JSON.
`vehiculo.funcion_operativa`: VARCHAR(7), ascii/ascii_bin, nullable, default NULL;
CHECK exacto para REGULAR/APOYO/NULL con comparación binaria y longitud.

`asignacion_vehiculo_operativa`: PK autoincremental; cuatro FK hacia cuadrilla,
vehículo y usuarios asignador/finalizador; columnas generadas STORED
`cuadrilla_abierta` y `vehiculo_abierto`; UNIQUE `uq_avo_cuadrilla_abierta` y
`uq_avo_vehiculo_abierto`; índices `idx_avo_cuadrilla_historial` e
`idx_avo_vehiculo_historial`; CHECK `chk_avo_fechas` y `chk_avo_cierre`.
Estructura acorde a la migración probada en MariaDB aislada.

## F. Preservación de datos

Inmediatamente después de migrar: los 7 vehículos originales seguían con NULL y
la tabla nueva tenía 0 filas. Huellas SHA-256 de filas completas confirmaron
preservación de vehículos (comparando sin la nueva columna), `usa`, `participa`,
recorridos, incidencias y atenciones. Incluye `id_tipo_residuo`.

La comprobación final repitió esta comparación, excluyendo únicamente los IDs
de fixtures nuevos documentados abajo: todos los datos originales se conservaron.
No se reclasificó ningún vehículo original ni se creó historia operativa original.

## G. Permisos desplegados

51 permisos y 123 relaciones rol_permiso finales: incremento exacto de 2 permisos
y 4 concesiones. `asignacion_vehiculo.consultar` y `.modificar` concedidos a
RESPONSABLE_SECTORIAL y ADMINISTRATIVO_OPERATIVO. Sin concesión a TI ni Operario.

## H. Pruebas HTTP reales

Base HTTP: `http://localhost/zentrax-project/03-proyecto-zemyna/programacion`.
Se usaron login real y cookies PHP regeneradas. Las cuentas existentes TI,
Administrativo Operativo y Operario aceptaron las credenciales demo documentadas
en el proyecto. No se cambiaron contraseñas ni se fabricaron sesiones.

37 solicitudes registradas en el flujo HTTP final, todas con el código esperado;
incluyen autenticaciones repetidas al reanudar el flujo. Las comprobaciones de
navegador se registraron por separado. El primer intento del ejecutor tomó la
cookie anterior a la regeneración y recibió 401: se corrigió el ejecutor para
usar el último PHPSESSID emitido y se repitió el flujo. No fue un defecto del API.

| Comprobación | HTTP observado |
|---|---:|
| Login de las tres cuentas existentes | 200 |
| Asignaciones sin sesión | 401 |
| Consulta/asignación con TI o Operario | 403 |
| Consulta vehículos y campo funcion_operativa | 200 |
| Consulta asignaciones con Operaciones | 200 |
| Clasificación inválida OTRO | 400 |
| Clasificación autorizada REGULAR/APOYO de fixtures | 200 |
| Apertura sin clasificación | 409 |
| Apertura con vehículo original 3 inactivo, sin modificarlo | 409 |
| Apertura con pareja usa inexistente, vehículo de prueba clasificado | 409 |
| Actor o fecha proporcionados por el cliente | 400 |
| Apertura válida | 200 |
| Detalle de asignación | 200 |
| Cambio válido A → B | 200 |
| Cierre válido | 200 |
| Segundo cierre de la misma versión | 409 |

## I. Política de vehículo asignado

Con A/REGULAR abierto: baja, mantenimiento, clasificación NULL y cambio a APOYO
devolvieron 409. Tras cambiar a B/APOYO, A pudo cambiar a APOYO con 200; cambiar B
abierto a REGULAR devolvió 409. Tras cerrar B: mantenimiento y quitar clasificación
devolvieron 200; baja de los tres vehículos de prueba devolvió 200.
Todos los cierres fueron explícitos mediante API, sin cierres automáticos.

No se repitieron carreras concurrentes sobre la base habitual. La evidencia de
concurrencia sigue siendo la suite aislada: 31 pruebas, 150 aserciones, aprobada
antes del despliegue. Se comprobaron las constraints efectivamente desplegadas.

## J. Validación visual del panel Apache

Chrome real en modo headless, viewport 1440×1000, con capturas inspeccionadas.
Camiones muestra Pendiente, REGULAR y APOYO. Cuadrillas muestra nombre de cuadrilla,
vehículo, función, inicio, estado, historial y botones Cambiar/Finalizar.
Los formularios y navegación se cargan; filtro V19CHK devuelve 3 camiones de prueba.
La edición de clasificación desde el formulario real de TI sobre A abierto
presentó el rechazo 409 de forma comprensible. TI no obtuvo formulario operativo:
vio el mensaje de falta de permisos. Las operaciones de abrir/cambiar/cerrar
se comprobaron por HTTP; los controles de asignación se inspeccionaron en el DOM
y visualmente, sin afirmar un recorrido manual completo de todos sus botones.

## K. Consola/navegador

Sin excepciones JavaScript en el panel. La consola registró respuestas de red
401 al consultar sesión anónima, 403 al consultar asignaciones como TI y 409 durante
la edición prohibida; son rechazos provocados intencionalmente.
Chrome dentro del sandbox falló por GPU; la revisión se completó fuera del sandbox
con perfil temporal propio y arranque oculto, y el navegador se cerró al terminar.
No se detectó un defecto de V19 que requiriera cambios de implementación.

## L. Regresión posterior

- Backend seguro completo: 900 casos descubiertos, 858 ejecutados y 42 omitidos;
  3569 aserciones, ningún fallo. Incluye vehículo, F3 y F4.1/F4.2.
- V19 específica repetida: 24 pruebas, 91 aserciones, ningún fallo.
- Frontend completo: 90 pruebas, ninguna omitida, ningún fallo.
- PHP -l de todos los PHP V19 nuevos/modificados: sin errores.
- git diff --check: sin errores.

No se habilitaron suites HTTP genéricas que alterasen datos reales, ni se repitió
concurrencia en la BD habitual. Las pruebas específicas HTTP son las de este informe.

## M. Estado final de la BD

V19 aplicada: **sí**. 10 vehículos totales: los 7 originales intactos y 3 fixtures.
Originales IDs 1/2/3/4/11/17/18: función NULL. Fixture 19: APOYO, Disponible,
inactivo; fixture 20: NULL, Disponible, inactivo; fixture 21: NULL, Disponible,
inactivo. Asignaciones: **0 abiertas y 2 cerradas**, ambas de prueba.
`usa`: 7 filas (4 originales + 3 fixtures). `participa`: 4; recorridos: 4;
incidencias: 17; atenciones: 5, todos los originales intactos.

Se observó el estado histórico conocido de Beta: recorrido En Proceso con vehículo
3 dado de baja. No se corrigió ni se cerró. Los fixtures F42CHK anteriores también
se conservaron intactos.

## N. Fixtures y cambios persistentes retenidos

Marca: `V19CHK_20261003`. Se retuvieron expresamente para identificar la evidencia:

- Cuadrilla 24, Matutino, centro 1, nombre V19CHK_20261003, sin integrantes vigentes.
- Usuario 30, nombre V19CHK_20261003, correo de prueba example.invalid, Inactivo;
  contraseña aleatoria no utilizada para autenticación y nunca registrada en evidencia.
- Registro usuario_rol 30, rol OPERARIO en OPERACIONES, fecha_desde y fecha_hasta
  2026-10-03; cuenta inactiva.
- Pertenencia 27: usuario 30/cuadrilla 24, inicio 13:08:21, fin 13:11:53,
  asignador y finalizador usuario existente 2.
- Vehículos 19/20/21: matrículas V19CHKA/V19CHKB/V19CHKC, marca PRUEBA V19,
  modelo V19CHK_20261003, capacidad 1, id_tipo_residuo 1, todos inactivos.
- `usa` 16 (vehículo 19), 17 (vehículo 21), 18 (vehículo 20), cuadrilla 24.
- Asignación 1: vehículo 19, 13:08:23–13:11:52; cierre por cambio controlado.
- Asignación 2: vehículo 20, 13:11:52–13:11:52; cierre por fin de validación local.
  Ambas registradas y cerradas por el usuario existente 2, con motivos V19CHK.

No se crearon recorridos, incidencias ni atenciones de prueba. No se eliminaron
filas históricas ni se restablecieron AUTO_INCREMENT. Se generaron los registros
normales de sesión por los logins de validación. No se conservan cookies en evidencia.

Evidencia completa bajo `C:\Users\facun\AppData\Local\Temp\zemyna-backups`:
JSON de migración/estructura, resultados HTTP, fixtures, estado final y huellas;
capturas login/panel/409; ejecutores CLI utilizados. Estos ejecutores se retiraron
del directorio público del proyecto al terminar.

## O. Archivos modificados

La implementación V19 pendiente permanece local: modelos/controladores/API de
asignación y vehículos, coherencia de recorridos/recolección, SQL V19/schema,
panel/JavaScript y fixtures/pruebas. En este despliegue solo se actualizó el contrato
y se agregó este informe. Los scripts temporales de ejecución se retiraron.
El listado literal se adjunta en R.

## P. git diff --check

Sin salida y código 0. No se detectaron errores de espacios en las diferencias.

## Q. git diff --stat

El resultado literal se adjunta abajo. Git diff --stat no incluye archivos nuevos
sin seguimiento; esos archivos se enumeran en git status --short.

## R. git status --short

El resultado literal final se adjunta abajo; los cambios permanecen sin staging.

## S. Confirmaciones

- V19 aplicada exclusivamente sobre gestion_residuosfinal.
- NO git add; NO commit; NO push; NO VM.
- No se corrigió Beta ni se eliminaron fixtures anteriores.
- Backup conservado y ninguna asignación de prueba abierta al terminar.
- Trabajo detenido después de este informe.

### Q. Salida literal de diff --stat

```text
 .../backend/controllers/VehiculoController.php     |  9 +++++-
 .../backend/models/RecoleccionOperativa.php        |  7 +++++
 .../programacion/backend/models/Recorrido.php      | 25 +++++++++++++++--
 .../programacion/backend/models/Vehiculo.php       | 31 +++++++++++++++++----
 .../backend/tests/AtencionIncidenciaTest.php       |  2 ++
 .../backend/tests/IncidenciaAdminTest.php          |  2 ++
 .../backend/tests/IncidenciasPropiasTest.php       |  2 ++
 .../programacion/backend/tests/RecoleccionTest.php |  2 ++
 .../base-datos/database/sql/schema.sql             | 32 ++++++++++++++++++++--
 .../programacion/frontend/public/admin.html        |  5 +++-
 .../frontend/public/assets/js/admin.js             |  4 ++-
 .../frontend/public/assets/js/cuadrillas.js        |  4 ++-
 12 files changed, 111 insertions(+), 14 deletions(-)
```

### R. Salida literal de status --short

```text
 M 03-proyecto-zemyna/programacion/backend/controllers/VehiculoController.php
 M 03-proyecto-zemyna/programacion/backend/models/RecoleccionOperativa.php
 M 03-proyecto-zemyna/programacion/backend/models/Recorrido.php
 M 03-proyecto-zemyna/programacion/backend/models/Vehiculo.php
 M 03-proyecto-zemyna/programacion/backend/tests/AtencionIncidenciaTest.php
 M 03-proyecto-zemyna/programacion/backend/tests/IncidenciaAdminTest.php
 M 03-proyecto-zemyna/programacion/backend/tests/IncidenciasPropiasTest.php
 M 03-proyecto-zemyna/programacion/backend/tests/RecoleccionTest.php
 M 03-proyecto-zemyna/programacion/base-datos/database/sql/schema.sql
 M 03-proyecto-zemyna/programacion/frontend/public/admin.html
 M 03-proyecto-zemyna/programacion/frontend/public/assets/js/admin.js
 M 03-proyecto-zemyna/programacion/frontend/public/assets/js/cuadrillas.js
?? 03-proyecto-zemyna/programacion/backend/api/asignaciones_vehiculo.php
?? 03-proyecto-zemyna/programacion/backend/controllers/AsignacionVehiculoController.php
?? 03-proyecto-zemyna/programacion/backend/docs/contrato-v19-asignacion-vehiculo.md
?? 03-proyecto-zemyna/programacion/backend/docs/informe-despliegue-local-v19-20261003.md
?? 03-proyecto-zemyna/programacion/backend/models/AsignacionVehiculo.php
?? 03-proyecto-zemyna/programacion/backend/tests/AsignacionVehiculoMariaDbTest.php
?? 03-proyecto-zemyna/programacion/backend/tests/AsignacionVehiculoTest.php
?? 03-proyecto-zemyna/programacion/backend/tests/fixtures/asignacion_vehiculo.php
?? 03-proyecto-zemyna/programacion/backend/tests/fixtures/vehiculo_worker.php
?? 03-proyecto-zemyna/programacion/base-datos/database/sql/migration_v19_asignacion_vehiculo.sql
?? 03-proyecto-zemyna/programacion/frontend/public/assets/js/asignaciones-vehiculo.js
?? 03-proyecto-zemyna/programacion/frontend/tests/asignaciones-vehiculo.test.cjs
```
