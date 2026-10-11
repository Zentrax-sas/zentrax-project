# F6.3C.2 — continuidad y recuperación excepcional

Sin cambios de V21, frontend, permisos, alta pública ni transiciones F4.

## Continuidad y suspensión

`ContinuidadSolicitudOperativa` comprueba la habilitación mediante
`SolicitudPropiaOperativa::authorize`: usuario activo, permisos reales y vigentes
en OPERACIONES (`solicitud.operar`, `recorrido.consultar` y `recorrido.operar`
o `recorrido.modificar`), pertenencia abierta, única e iniciada. Usa el reloj
America/Montevideo, sin deducciones por nombre de rol. La fecha hasta es inclusiva.

`RecoleccionOperativa::asignar` evalúa finalizar/trasladar dentro de su transacción.
PUT de usuarios agrupa usuario y todos los hitos del cambio de rol en una sola
transacción; evalúa el resultado completo, incluyendo reemplazo del mismo día,
cierre/reemplazo histórico y cambio de vigencia. POST/PUT de usuario_roles comparte
el mismo protocolo; la propiedad del rol se resuelve tras tomar la puerta.
Un resultado exitoso que reduzca a cero los habilitados de una cuadrilla con
solicitud En atención devuelve 409 y revierte todo. Un relevo plenamente habilitado
permite la salida del iniciador. No exige que finalice el mismo usuario que inició.
Asignada/Aceptada y Finalizada no reciben el nuevo bloqueo.

Las incoherencias entre estados, intento abierto y cuadrillas producen 409;
no se interpretan como ausencia de trabajo. Si ya había cero habilitados, no se
bloquean cambios ordinarios que puedan reparar la habilitación o no la reduzcan.
No se crean integrantes, roles ni permisos automáticamente.

DELETE de usuarios mantiene sus comprobaciones HTTP existentes y la baja lógica.
Es la suspensión de seguridad autorizada: comparte la puerta y el lock del usuario
pero no exige relevo, no borra pertenencias ni historia. No hay parámetro urgente.
La autorización legacy de usuarios no cambia; su tratamiento TI no se traslada
a la API de solicitudes, que sigue consultando permisos efectivos del servidor.

## Recuperación HTTP

PUT `api/solicitudes_admin.php`, nueva acción **recuperar**, separada de cancelar,
reasignar y desasignar. Requiere usuario activo, `solicitud.consultar` y
`solicitud.modificar` vigentes en OPERACIONES. Sin bypass TI ni permisos nuevos.
Conserva CSRF (`X-CSRF-Token`), compatibilidad sin Origin y respuestas no-store.

Campos obligatorios y únicos:

```json
{
  "accion": "recuperar",
  "id_solicitud": 1,
  "estado_esperado": "En atención",
  "estado_operativo_esperado": "En atención",
  "id_atencion_esperada": 1,
  "version_esperada": "hash SHA-256 de 64 caracteres del detalle administrativo",
  "motivo": "Motivo obligatorio de 1 a 500 caracteres"
}
```

No recibe autor, fecha, vehículo ni cuadrilla. La versión es la del contrato
administrativo, no la del detalle del operario. NULL en el intento no es válido.
El detalle añade `estado_operativo_esperado` y `capacidades.recuperar`; esta última
es informativa, siempre se revalida al ejecutar. No modifica las capacidades
anteriores para permitir intervenciones ordinarias durante En atención.

Condiciones comprobadas con locks: solicitud En atención, único intento abierto
En atención, misma cuadrilla, estados/intento/versión actuales y **cero integrantes
plenamente habilitados**. La pérdida del iniciador con relevo no es causa.
La indisponibilidad de vehículo o V19 por sí sola tampoco habilita esta acción.
Contexto incoherente devuelve 409 y requiere investigación; no fabrica historia.

Resultado: intento Interrumpida con cierre, autor de sesión y motivo; conserva
asignación, aceptación, inicio, autores y V19 histórica. Solicitud Pendiente y
cuadrilla NULL; no escribe finalización ni cancelación. Responde el detalle
administrativo actualizado. Repeticiones o expectativas obsoletas devuelven 409.
400 para contrato inválido; 401/403 para autorización/CSRF; 404 para inexistente;
409 para conflictos, deadlock o timeout; 500/503 para infraestructura, sin SQL ni
datos personales. Rollback completo y sin reintentos automáticos.

## Locks y alcance concurrente

Puerta común: `SELECT id_cuadrilla FROM cuadrilla ORDER BY id_cuadrilla FOR UPDATE`
antes de leer/escribir trabajos, usuarios o pertenencias. Usa filas existentes;
no necesita tabla ni migración. Es deliberadamente conservadora: serializa las
mutaciones F6 administrativas/operativas, los cambios ordinarios protegidos y
las suspensiones de seguridad entre cuadrillas. Limita el paralelismo; no debe
mantenerse durante confirmaciones del usuario ni operaciones de red.

Después, inicio/finalización y administración mantienen su orden heredado de
solicitud, intentos, autorización y recursos. Cambios de habilitación bloquean
usuario, pertenencias, solicitudes/intentos protegidos e integrantes/roles/permisos
necesarios; todas las lecturas decisorias son FOR UPDATE y actuales tras esperar.
La puerta evita dos salidas ordinarias simultáneas y serializa inicio contra
revocación, recuperación contra incorporación/restauración y finalización contra
recuperación. La autorización revocada nunca se conserva para finalizar.

Caminos heredados F3/F4/recursos C.1 no adquieren todos la misma puerta; pueden
formar ciclos con estos locks. Se conserva su seguridad mediante locks de recursos,
rollback y 409, sin prometer ausencia de deadlocks. El fixture dirigido de deadlock
F6.3A aumenta escrituras inocuas del competidor para conservar su peso y comprobar
que el endpoint víctima traduce el error, dado el nuevo lock de cuadrillas.

## Límites y validación

Los vencimientos por calendario no ejecutan guards: puede existir ausencia temporal
de habilitados. Se comprueban fechas reales al consultar/operar/recuperar; no se
reactiva ni renueva automáticamente. Un cambio futuro de vigencia se evalúa por su
efecto actual, no garantiza personal durante toda la duración desconocida del retiro.
OPERACIONES puede recuperar únicamente mientras persista la ausencia comprobada,
o TI/gestión de integrantes puede restablecer legítimamente la habilitación.

DML externo, cambios de concesiones o escrituras internas que eviten estos contratos
no comparten necesariamente la puerta. No hay garantía universal sobre esos caminos.
Los helpers internos de rol siguen agrupándose por sus controladores; no deben
invocarse como contratos administrativos independientes.

Tests `ContinuidadSolicitudOperativaMariaDbTest`, workers y router HTTP real exigen
DSN explícito, datadir TEMP `zemyna-f6[123]-*` y base aleatoria `f63_test_*`.
Nunca cargan datos destructivos en gestion_residuosfinal. Cubren continuidad,
roles, permisos, suspensión HTTP, CSRF, recuperación, carreras y rollback real.
La integración visual de recuperación queda pendiente; no modifica frontend.

## Validación ejecutada

Últimas ejecuciones dirigidas, sin suite completa:

| Grupo | Tests | Assertions | Resultado |
| --- | ---: | ---: | --- |
| Continuidad, recuperación y HTTP real (`testC2`) | 19 | 294 | PASS |
| UsuarioController, dobles sin conexión a BD | 65 | 333 | PASS |
| Recursos C.1, selección de estados/carreras/rollback | 7 | 106 | PASS |
| F6.2/F6.3, selección de permisos/transiciones/carreras/deadlock | 10 | 115 | PASS |
| F4, selección de carreras y rollback | 5 | 56 | PASS |

Se adaptó el doble de Usuario al nuevo límite transaccional; la integridad real
se comprueba en MariaDB, no mediante mocks de begin/commit. El fixture SQLite de
Recoleccion incorpora las tablas vacías necesarias para leer el guard; no se ejecutó
esa suite porque las pruebas con BD de este bloque se limitaron a MariaDB descartable.
Los fallos iniciales fueron fixtures: selección de víctima de deadlock y modificación
de fecha_inicio en una pertenencia histórica cerrada del relevo. Se corrigieron y
los casos dirigidos aprobaron. No hubo skips en las ejecuciones de la tabla.

Archivos propios del bloque (rutas relativas a backend):

- Modelos: `ContinuidadSolicitudOperativa.php` (nuevo), `RecoleccionOperativa.php`,
  `Usuario.php`, `UsuarioRol.php`, `SolicitudOperativa.php`, `SolicitudPropiaOperativa.php`.
- Controladores: `UsuarioController.php`, `UsuarioRolController.php`, `SolicitudAdminController.php`.
- Docs: este contrato nuevo, `contrato-f6.2-solicitudes-admin.md`,
  `contrato-f6.3-solicitudes-propias.md`, `contrato-f6.3c-proteccion-recursos.md`.
- Tests: `ContinuidadSolicitudOperativaMariaDbTest.php` (nuevo),
  `SolicitudesPropiasMariaDbTest.php`, `UsuarioControllerTest.php`, `RecoleccionTest.php`;
  fixtures nuevos `f63c2_worker.php` y `f63c2_http_router.php`.
