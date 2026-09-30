# F4.2 — Atención operativa de incidencias

La unidad de trabajo es la incidencia asignada a una cuadrilla. No es el recorrido.
La identidad proviene de la sesión PHP; cuadrilla, actividad, pertenencia y permisos
se consultan en backend. No se implementan app móvil, desplazamiento, push, GPS,
clasificación de vehículos, solicitudes especiales ni asignación automática.

## Modelo e integración administrativa

`atencion_incidencia` conserva una fila por intento. `incidencia.id_cuadrilla` sigue
siendo la asignación actual y `incidencia.estado` conserva sus tres valores.
No se agregan referencias a recorrido, vehículo o usa.

Se conservan `origen` e `Interrumpida` porque la migración, reapertura y las
intervenciones administrativas no representan aceptación, rechazo ni finalización
del chofer. Se elimina `causa_interrupcion`: `motivo_cierre` guarda la causa servidor
(`Reasignación`, `Desasignación`, `Resolución administrativa` o
`Reinicio administrativo`). Para `Rechazada` contiene el motivo escrito por el actor.

Campos: ID del intento, incidencia y cuadrilla histórica; estado; origen;
fecha/autor de registro, aceptación, inicio y cierre; motivo de cierre. La columna
generada `incidencia_abierta` y su UNIQUE impiden dos intentos abiertos de una incidencia.
FK RESTRICT conservan autores, cuadrilla e incidencia; CHECK validan parejas
autor/fecha, hitos, cierre, motivo y orden temporal. Las fechas son DATETIME con
precisión de segundos: dos hitos pueden tener igual fecha.

| Operación | Resultado |
|---|---|
| F3 asigna | Crea `Asignada`, origen `Asignacion`, actor servidor; mantiene todas las validaciones F3. |
| F3 reasigna/desasigna | Interrumpe el intento abierto; asignación nueva crea otro ID. Ambas tablas cambian en la misma transacción. |
| Administración resuelve | Interrumpe el intento con motivo `Resolución administrativa`; aplica regla funcional de resolución. |
| Administración reabre | Limpia fecha de resolución; si conserva cuadrilla crea `Asignada`, origen `Reapertura`. |
| Administración vuelve a Pendiente durante atención | Interrumpe y crea `Asignada`, origen `Reinicio administrativo`. |
| Administración cambia prioridad | No cambia la atención. |
| Administración pasa Pendiente a En Proceso | No inventa aceptación ni inicio operativo. |
| Eliminar con historial | 409; no destruye trazabilidad. Sin historial conserva la eliminación anterior. |

Estas reglas alcanzan tanto la actualización parcial como el payload completo legacy.
El detalle administrativo existente de `incidencias.php?id=...` agrega `atenciones`
en orden de ID descendente. No se añade pantalla ni se expone historial en tracking
público, mapas públicos o listados de otras cuadrillas.

## Permisos

Se auditó el catálogo: `incidencia.modificar` es gestión administrativa y
`recorrido.operar` describe explícitamente iniciar/atender/finalizar recorridos.
Ninguno expresa la operación acotada de incidencias propias. v18 agrega
`incidencia.operar` a OPERARIO, RESPONSABLE_SECTORIAL y ADMINISTRATIVO_OPERATIVO,
los roles operativos que ya ejecutan/organizan el trabajo regular.
No lo concede a ADMINISTRADOR_TI ni INSPECTOR.

Cada escritura revalida en BD usuario activo, asignaciones de rol vigentes,
sector OPERACIONES, elegibilidad existente (`recorrido.consultar` y
`recorrido.operar` o `recorrido.modificar`), permiso real `incidencia.operar`
y pertenencia vigente. No utiliza el bypass TI del helper general. Un usuario
TI que además tenga otra asignación operativa válida debe satisfacer exactamente
las mismas condiciones. No exige un recorrido actual.

## Consulta F4.1

Se conserva `GET /backend/api/recoleccion.php?view=incidencias_propias`, sus filtros,
detalle, límites y paginación. Ownership sigue en SQL antes de LIMIT/OFFSET.
Subconsultas escalares recuperan el último intento de la cuadrilla actual, sin
multiplicar filas por historial. Cada registro agrega:

```json
{"atencion":{"id_atencion_incidencia":105,"estado":"Asignada"}}
```

Puede ser `null` en registros históricos sin intento. Una incidencia finalizada
conserva el intento terminal para su detalle/filtro Resuelta. Rechazar quita
`id_cuadrilla`: deja de aparecer y su antiguo detalle devuelve 404.
La consulta no escribe. La vista conserva GET-only: POST sobre esa vista da 405.

## Escrituras

`POST /backend/api/recoleccion.php`, JSON y sesión PHP existente. Ejemplos:

```json
{"accion":"aceptar_incidencia","id_incidencia":42,"id_atencion_incidencia":105,"estado_operativo_esperado":"Asignada"}
```
```json
{"accion":"rechazar_incidencia","id_incidencia":42,"id_atencion_incidencia":105,"estado_operativo_esperado":"Asignada","motivo":"No contamos con el equipo necesario."}
```
```json
{"accion":"iniciar_atencion_incidencia","id_incidencia":42,"id_atencion_incidencia":105,"estado_operativo_esperado":"Aceptada"}
```
```json
{"accion":"finalizar_atencion_incidencia","id_incidencia":42,"id_atencion_incidencia":105,"estado_operativo_esperado":"En atención"}
```

IDs enteros positivos hasta 2147483647; admite sus representaciones decimales en
string, no booleanos ni floats. Lista cerrada de campos. Rechaza id_usuario,
id_cuadrilla, autores, fechas y estado funcional final del cliente. El motivo es
string, trim, 1–500 caracteres UTF-8; solo se admite al rechazar.

| Acción | Origen → destino operativo | Efecto funcional |
|---|---|---|
| Aceptar | Asignada → Aceptada | Conserva Pendiente o En Proceso. |
| Rechazar | Asignada → Rechazada | Conserva estado y fecha_resolucion; id_cuadrilla=NULL. |
| Iniciar | Aceptada → En atención | Pendiente → En Proceso; conserva En Proceso histórico. |
| Finalizar | En atención → Finalizada | Exige En Proceso; pasa a Resuelta. |

Cada acción registra su usuario y fecha servidor America/Montevideo. Otro integrante
vigente autorizado puede continuar. Finalización escribe cierre y fecha_resolucion
con el mismo instante, en una transacción. No opera Resueltas ni repite fechas.

Éxito HTTP 200:

```json
{"success":true,"statusCode":200,"data":{"id_incidencia":42,"estado":"Resuelta","fecha_resolucion":"2026-09-30 10:00:00","atencion":{"id_atencion_incidencia":105,"estado":"Finalizada"}}}
```

Errores: 400 payload; 401 sesión ausente/cuenta inactiva; 403 permiso o elegibilidad;
409 sin pertenencia (`sin_pertenencia`) o conflicto (`conflicto_operativo`);
503 persistencia indisponible. Incidencia ajena, inexistente o sin asignar devuelve
el mismo 404 y mensaje `Incidencia no accesible para esta cuadrilla.`.

```json
{"success":false,"statusCode":409,"message":"La atención cambió. Volvé a consultar la incidencia.","code":"conflicto_operativo"}
```

## Concurrencia

Todas las transiciones comienzan bloqueando incidencia, como F3. Luego bloquean
usuario, roles/permisos, pertenencia e intento. Las pertenencias existentes
también serializan sobre usuario. F3 conserva su orden adicional de recursos.
Se comprueban ID de intento, estado esperado, ownership y estado funcional después
de adquirir los bloqueos. UNIQUE es la última defensa contra dos abiertas.

Dos aceptaciones/inicios/finalizaciones no repiten efectos: una gana y otra recibe
409. Aceptar contra rechazar permite una sola decisión; si rechazo ganó, la otra
petición queda sin ownership y recibe 404. Una reasignación puede interrumpir una
aceptación ya confirmada, conservando ese hecho. Un intento anterior no puede
operar una asignación nueva, incluso A → B → A.

No hay claves de idempotencia ni repetición 200. El cliente vuelve a consultar tras
un conflicto o respuesta perdida. Errores MariaDB 1062/1205/1213 producen rollback
y 409 en las acciones y F3. Otros errores no exponen SQL y deshacen escrituras
parciales. SQLite prueba lógica y rollback, no bloqueos InnoDB.

## Instalación, datos y rollback

Aplicar `migration_v18_atencion_incidencia.sql` con respaldo y escritores detenidos,
después de v17 y del catálogo de roles. También aplicar v18 después de instalar
`schema.sql` y cargar roles: el schema define estructura, la migración concede el
permiso. Desplegar backend y migración coordinadamente; la tabla es requisito.

DDL tiene commit implícito. Concesiones e incorporación de datos se ejecutan en
una transacción posterior. La migración es reejecutable y no cambia migraciones
anteriores. Agrega un intento Asignada/Migracion a cada incidencia no resuelta
asignada que no tenga historia. No agrega intentos para Resueltas ni sin asignar.

`fecha_registro` de Migracion es incorporación al modelo, NO fecha histórica de
asignación. Autor de registro y todos los hitos quedan NULL. Se calcula desde UTC
con el desplazamiento actual -03:00 de Montevideo para no depender de tablas de
zonas horarias instaladas en MariaDB. Las acciones PHP usan America/Montevideo.
Asignaciones futuras sí registran fecha y autor reales.

No revertir eliminando historial utilizado. Antes de habilitar escrituras puede
revertirse controladamente una incorporación respaldada. Después de uso real,
detener escritores y preservar/exportar historial antes de decidir una reversión:
volver solamente al backend anterior deja sus futuras asignaciones sin historial.

No se aplicó la migración sobre la base de uso del proyecto durante el desarrollo.
La validación real usa exclusivamente un servidor MariaDB descartable.

## Pruebas reproducibles

Desde backend, con variables de integración HTTP real deshabilitadas:

```text
php vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests/AtencionIncidenciaTest.php
php vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result --filter "IncidenciaAdminTest|IncidenciasPropiasTest|RecoleccionTest" tests
php vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests
```

Desde programacion:

```text
node --test frontend/tests/login-session.test.cjs frontend/tests/map-experience.test.cjs
```

`AtencionIncidenciaMariaDbTest` es optativa: requiere ZEMYNA_F42_MARIADB_DSN y
ZEMYNA_F42_MARIADB_PASSWORD de un servidor temporal. Antes de crear una base
verifica que @@datadir esté bajo el TEMP del proceso y sea
`zemyna-f42-<hex>/data/`. Nunca admite la instancia habitual del proyecto.
Crea bases `f42_test_<hex>`, aplica schema y migración reales, y las elimina al
terminar. Los workers PHP usan conexiones distintas; la prueba comprueba
INNODB_TRX/LOCK WAIT antes de liberar el bloqueo que organiza la carrera.
No requiere servidor HTTP, navegador ni credenciales reales.

Se cubren: migración reejecutable, grants sin TI, UNIQUE/FK/CHECK, doble aceptación,
aceptar/rechazar, reasignar/aceptar, doble inicio/finalización y cambio de pertenencia.
Las pruebas en memoria cubren además IDOR, payloads manipulados, permiso revocado,
ABA, rollback, historial, legacy, rechazo/F4.1, F3 y consumidores del estado.

### Validación del 30/09/2026

- F4.2 en memoria: 40 pruebas, 184 aserciones.
- F4.2 + F3/F4.1 + recolección: 256 pruebas, 960 aserciones.
- Backend seguro completo: 845 pruebas, 3478 aserciones, 11 omitidas (3 HTTP
  sobre entorno real y 8 MariaDB optativas, ejecutadas separadamente).
- MariaDB 10.4.32 descartable: 8 pruebas, 74 aserciones, sin omisiones;
  incluye rollback ante fallo de la segunda escritura y carreras con LOCK WAIT
  comprobado. No se prueba recuperación ante caída del servidor.
- Frontend completo: 86 pruebas correctas. Sin cambios JS.
- `php -l`: 10 archivos PHP nuevos/modificados sin errores.
- `git diff --check`: sin errores.

Sin integración contra datos reales, despliegue de v18 en la base habitual,
prueba manual de navegador ni implementación/prueba en una app móvil.
