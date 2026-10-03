# F3 → V19: asignación manual de incidencias

## Identidad y disponibilidad

La fuente del vehículo actual es `cuadrilla → asignacion_vehiculo_operativa`
abierta (`fecha_fin IS NULL`) `→ vehiculo`. Una autorización `usa` no implica
utilización actual. Un recorrido no sustituye la asignación V19.

Ambas funciones requieren integrantes vigentes operativamente elegibles,
asignación abierta inequívoca, vehículo activo y fuera de mantenimiento,
clasificación válida y exactamente una autorización `usa` para la pareja.
En Servicio es admisible. No hay regla nueva de una incidencia por cuadrilla.

REGULAR exige un recorrido Pendiente/En Proceso, fecha_fin NULL, participación
única sin hora_fin y la misma cuadrilla/vehículo/autorización de V19. Las relaciones
operativas múltiples, ambiguas o incompatibles excluyen la opción.

APOYO no exige recorrido ni participa. Su opción presenta los tres campos de
recorrido como NULL, incluso si existe un recorrido coherente relacionado.
Si existe alguno, se validan igualmente participación, cronología, ambigüedad y
coherencia. No se crean recorridos, rutas ni participaciones ficticias.

## GET y PUT

GET `api/incidencias.php?opciones=asignacion` devuelve en `data`:

```text
id_cuadrilla, nombre, turno,
id_asignacion_vehiculo,
id_vehiculo, matricula, estado_vehiculo, funcion_operativa,
id_usa,
id_recorrido, estado_recorrido, ruta_nombre
```

REGULAR informa recorrido; APOYO devuelve los tres campos NULL. Cero candidatos
es una respuesta 200 válida. La consulta descarta relaciones no disponibles.

PUT para asignar/reasignar:

```text
accion: asignar
id_incidencia
id_cuadrilla
id_asignacion_vehiculo
id_usa
id_recorrido: entero para REGULAR; NULL u omitido para APOYO
cuadrilla_esperada: entero o NULL
estado_esperado: Pendiente | En Proceso | Resuelta
```

Desasignar usa id_cuadrilla NULL, mantiene incidencia/valores esperados y omite
asignación/usa/recorrido (NULL explícito también admisible). Se rechazan IDs extra,
vehículo, actor y fechas proporcionados por el cliente. Resuelta no puede asignarse
ni desasignarse. La misma cuadrilla no genera una nueva atención por cambiar V19.

## Autorización y persistencia

F3 consulta actividad y permisos vigentes directamente en BD, exige
`incidencia.consultar` y `incidencia.modificar` en OPERACIONES. No aplica bypass TI.
TI sin estos permisos devuelve 403 para opciones, asignar, reasignar y desasignar;
TI con concesiones reales en Operaciones puede operar como cualquier autorizado.
Los permisos cacheados de sesión no sustituyen esta comprobación.

No se cambió `hasEffectivePermission()` ni la autorización de otros módulos.
`can_assign` procede de esta misma autorización F3. No se exige permiso de modificar
utilizaciones V19 para asignar una incidencia.

La incidencia persiste únicamente `id_cuadrilla`. Reasignar/desasignar interrumpe
la atención vigente; asignar registra una nueva atención Asignada. Se conserva la
transacción y el historial de F4.2. No hay nuevas columnas ni migraciones.
F4.1 y F4.2 siguen dependiendo de pertenencia/cuadrilla/atención, sin vehículo.

## Concurrencia

Incidencia primero; luego autorización real, integrantes/pertenencias, cuadrilla,
versión V19 abierta, recorridos/participaciones implicados, vehículo, usa y atención.
Los SELECT de confirmación usan FOR UPDATE en MariaDB. Se exige el mismo
id_asignacion_vehiculo, no simplemente otra asignación abierta de la cuadrilla.
El vehículo siempre se deriva del registro bloqueado.

Cierre/cambio V19 o recorrido obsoleto devuelve 409 sin cambios parciales.
Permisos revocados devuelven 401/403. Campos inválidos devuelven 400. Deadlock,
timeout y conflicto de unicidad se traducen a 409 con rollback. Errores persistentes
no exponen SQL interno. No se rediseñó la inversión de locks existente entre V19
y CRUD vehículo; no se garantiza ausencia absoluta de deadlocks.

Después del commit F3, un cambio V19 no reasigna la incidencia: su responsabilidad
permanece en la cuadrilla. No se bloquea la utilización durante toda la atención.

## Frontend y pruebas

El selector existente usa id_asignacion_vehiculo. REGULAR muestra función y ruta;
APOYO muestra “Sin recorrido fijo”. Envía recorrido NULL para APOYO y conserva
desasignación, no-op de misma cuadrilla, doble envío bloqueado y refresco tras 409
sin perder filtros/página. El mensaje vacío explica la utilización abierta requerida.

`IncidenciaAsignacionV19Test` valida el contrato en SQLite. Su subclase
`IncidenciaAsignacionV19MariaDbTest` ejecuta el mismo contrato y carreras con
procesos/conexiones independientes cuando `ZEMYNA_F3_V19_MARIADB=1`.
Solo genera una base `zemyna_f3_v19_test_<12 hex>`, aplica el schema existente sobre
esa base vacía y la elimina al terminar. Nunca recibe un nombre externo.

También se actualizan IncidenciaAdminTest, IncidenciaApiAccessTest,
AsignacionVehiculoTest, IncidenciasPropiasTest, AtencionIncidenciaTest y
frontend/tests/map-experience.test.cjs. Las carreras no se ejecutan sobre la base
habitual. La implementación inicial no clasifica ni abre utilizaciones reales.

```text
php vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests
node --test frontend/tests/*.test.cjs
```

El primer comando se ejecuta desde backend y el segundo desde programacion.

## Evidencia de implementación (2026-10-03)

- Precheck: HEAD `64cf738`, árbol limpio. La integración queda sin staging ni commit.
- Backend seguro completo: 975 tests, 3745 assertions, 81 skipped, sin fallos.
  Los 39 casos MariaDB de esta integración requieren habilitación explícita y se
  ejecutaron separadamente; los otros 42 skips pertenecen a la suite existente.
- F3/V19 SQLite y acceso API: 85 tests, 368 assertions, sin fallos.
- MariaDB aislada: 39 tests, 209 assertions, sin fallos. Incluye cierre/cambio V19,
  cambio de recorrido, CRUD vehículo, dos operadores y rollback real.
- Frontend completo: 93 tests, 93 aprobados, sin fallos ni skips.
- Sintaxis: los 12 archivos PHP afectados pasan `php -l`.
- `git diff --check` sin errores; Git avisa únicamente conversiones LF/CRLF.
- La base habitual conserva conteos y hashes de filas idénticos en sus 30 tablas.
  Mantiene 0 utilizaciones abiertas y F3 devuelve 0 candidatos, resultado esperado.
- Las bases temporales F3/V19 fueron eliminadas; no quedan bases con ese prefijo.
- No se modificaron schema, migraciones, datos habituales ni VM. No hubo push.

Estas evidencias son de pruebas automatizadas y consultas locales. No constituyen
una nueva validación visual manual del flujo en navegador.
