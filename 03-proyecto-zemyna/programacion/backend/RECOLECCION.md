# Recolección operativa — v17

Base histórica V17: `226f85f9aac5a4c01dd40e7c1fb051896ce160f5`. Este documento incorpora los contratos actuales de permisos y las reglas C1/C2/C3; conserva la consulta administrativa inicial.

## Modelo y reglas

- `usuario_cuadrilla`: historial con inicio/fin, asignador y finalizador. Una columna generada `usuario_vigente` con índice UNIQUE permite un solo vínculo sin fecha de fin por usuario, también ante escrituras simultáneas. Todas las fechas se generan en America/Montevideo.
- `recorrido`: reutiliza `Pendiente → En Proceso → Finalizado`, `fecha_inicio` y `fecha_fin`. Agrega solamente autores de inicio/fin (FK a usuario). `Cancelado` no admite operaciones. Antes de iniciar, fecha_inicio representa la fecha prevista; al iniciar se registra la fecha efectiva del servidor. Los registros históricos conservan sus valores y autores NULL.
- `atencion_contenedor`: recorrido, contenedor, usuario y fecha. UNIQUE(recorrido, contenedor). No modifica incidencias ni inventa peso, volumen u observaciones.
- Asignación del recorrido: usa `cuadrilla → usa → participa → recorrido → ruta`. No hay asignación automática. Si varias cuadrillas participan en un mismo recorrido, la consulta propia y la operación devuelven conflicto; administración conserva la consulta del historial. No hay estado activo/inactivo en cuadrilla o ruta; se comprueba existencia. El usuario debe estar Activo y tener permisos operativos vigentes en OPERACIONES.
- Se prioriza el recorrido En Proceso y luego el Pendiente más próximo por fecha e ID. No se permite iniciar otro mientras la cuadrilla tenga uno En Proceso. Vehículos dados de baja o En Mantenimiento impiden iniciar.
- El progreso usa los contenedores activos actuales de la ruta más los que ya tienen atención, aunque después se cambien de ruta o se den de baja. No es una instantánea inmutable de la planificación. Se conserva toda atención registrada.
- Finalizar admite pendientes y pide confirmación mostrando su cantidad; no inventa atenciones para completar la ruta.
- Las mutaciones bloquean filas en transacción (usuario, cuadrilla, recorrido). Traslados y cierres verifican la versión mediante el ID de pertenencia esperado. Errores y conflictos revierten la transacción.
- El CRUD general de recorridos no puede reescribir ni borrar un recorrido con autoría o atención operativa registrada.

### Integridad y continuidad C1/C2/C3

- C1 protege `usa` y `participa` mediante transacciones, comprobaciones decisivas y bloqueos compartidos. Una autorización referenciada no puede cambiar de pareja ni eliminarse; una participación histórica no se modifica ni elimina desde el CRUD heredado. Se permite gestionar relaciones libres y pendientes sin actividad bajo sus validaciones.
- C2 exige vehículo REGULAR y utilización V19 abierta, vigente e inequívoca para la misma cuadrilla/vehículo al asignar y nuevamente al iniciar. La creación de un recorrido Pendiente sin asignación sigue permitida. No se comparan los residuos de todos los contenedores: las rutas mixtas están admitidas. APOYO no se usa para recorridos REGULARES.
- C3 impide retirar, trasladar o desactivar al último integrante operativamente habilitado mientras exista un recorrido REGULAR En Proceso. Con relevo habilitado se conserva la operación válida. También impide cerrar o sustituir la utilización V19 de la pareja de ese recorrido mientras siga En Proceso; mantiene las protecciones F6.
- Finalizar conserva la confirmación y permite pendientes, registra autor y fecha, no cierra V19 y no reescribe `participa`. No existe registro de «No atendido» con motivos en este alcance.
- Las pruebas actuales verifican estas reglas con SQLite/dobles. La concurrencia real de C1/C2/C3 en MariaDB permanece pendiente; no se presenta como certificada por los tests en memoria.

## Permisos

| Operación | Permisos y sector | Destinatario |
|---|---|---|
| Lectura propia | recorrido.consultar, OPERACIONES/LOGISTICA + pertenencia | Integrante autorizado |
| Iniciar, atender, finalizar | recorrido.operar o recorrido.modificar, OPERACIONES + pertenencia y elegibilidad vigente | Integrante operativo |
| Consulta administrativa existente y progreso | recorrido.consultar + cuadrilla.consultar + cuadrilla.modificar, sectores existentes | Gestión de cuadrillas |
| Consultar integrantes/historial/elegibles | Lectura de recolección + cuadrilla.consultar y cuadrilla.modificar en OPERACIONES | Gestión operativa limitada, sin listado general de usuarios |
| Asignar recorrido y consultar opciones | recorrido.consultar + recorrido.modificar + cuadrilla.consultar + cuadrilla.modificar; escritura en OPERACIONES | Administración autorizada |
| Asignar, trasladar, cerrar pertenencia | cuadrilla.consultar y cuadrilla.modificar en OPERACIONES | Administración operativa autorizada |

`recorrido.operar` fue incorporado en V17: sus concesiones históricas no reemplazan el sector ni la pertenencia real. No se concede `recorrido.modificar` adicional a operarios. No existe bypass universal TI: todos los roles requieren permisos explícitos y sectores vigentes. ADMINISTRATIVO_OPERATIVO utiliza las herramientas municipales autorizadas; ADMINISTRADOR_TI conserva administración técnica con permisos explícitos. La consulta general de usuarios requiere su autorización técnica; la gestión limitada de integrantes no la concede. Roles y permisos se revalidan desde la base en cada petición, no solo al iniciar sesión.

## API

`backend/api/recoleccion.php`, siempre con sesión.

GET propio (sin view): pertenencia y recorrido actual/próximo. `id_recorrido` permite consultar un resumen finalizado relacionado con la cuadrilla vigente. No permite id_usuario ni elegir cuadrilla. Sin pertenencia: 409 `sin_pertenencia`; sin recorrido disponible: 409 `sin_recorrido` con la pertenencia real.

GET `view=administracion`: conserva catálogo, participaciones, estado, ruta y paginación (25).
`id_cuadrilla` + `id_recorrido` devuelve progreso y autores, bajo permisos administrativos.
GET `view=integrantes&id_cuadrilla=N`: integrantes actuales, historial operativo y candidatos elegibles con identificadores, nombres/apellidos y pertenencia mínima. No entrega correo, teléfono ni credenciales; `no_elegibles` se conserva como lista vacía, sin identidades/motivos de personas excluidas.
GET `view=asignables&id_cuadrilla=N&page=1`: recorridos pendientes sin participación ni actividad previa, paginados de a 25; vehículos relacionados disponibles y conflicto operativo de la cuadrilla.

POST JSON:
- `{ "accion": "iniciar", "id_recorrido": N }`
- `{ "accion": "atender", "id_recorrido": N, "id_contenedor": M }`
- `{ "accion": "finalizar", "id_recorrido": N }`
- Gestión de recorrido: `accion=asignar_recorrido`, `destino` (cuadrilla), `id_recorrido`, `id_usa` (relación con vehículo).
- Gestión: `accion=asignar`, `integrante`, `destino`.
- Gestión: `accion=trasladar`, `integrante`, `destino`, `pertenencia` vigente esperada.
- Gestión: `accion=finalizar_pertenencia`, `integrante`, `pertenencia` vigente esperada.

Los IDs de destino de gestión son recursos administrativos validados, nunca la identidad del actor. No se aceptan autores o fechas del cliente. 401 sin sesión/usuario activo, 403 sin permiso, 404 recurso ajeno/inexistente, 409 conflicto, 400 validación, 503 persistencia controlada.

## Interfaz

`frontend/public/recoleccion.html` muestra exclusivamente la pertenencia y el recorrido propios.
No carga catálogo administrativo, elegibles ni historial. Ofrece acciones según estado/permisos,
confirmación de inicio/cierre y resultados del servidor. Reportar problema reutiliza `crew-report.js`
y su endpoint protegido dentro de la pantalla operativa, sin enviar identidad. La zona horaria se indica una sola vez cuando hay fechas visibles. Sin recorrido se muestra una tarjeta con la cuadrilla, turno, explicación y acciones Actualizar información y Reportar problema. El enlace Volver al panel siempre apunta a admin.html.

Mi recolección 2.0 conserva lista y mapa Leaflet del recorrido autorizado. La selección es compartida; «Ver en mapa» centra el marcador integrado, sin enlace externo en las tarjetas de contenedores. Lista y ficha del mapa reutilizan el mismo flujo de atención. Los filtros, carga progresiva y progreso no constituyen una secuencia optimizada de conducción. Sin recorrido REGULAR, APOYO puede atender incidencias y solicitudes asignadas según permisos; `sin_recorrido` no exige crearle un recorrido ficticio.

`frontend/public/admin.html#cuadrillas` integra la gestión en el panel y su navegación existente.
El menú se habilita mediante GET `view=permisos`; cada consulta/mutación mantiene sus controles de backend.
La consulta propia no incluye capacidades administrativas. La gestión contiene listado paginado,
Resumen, Integrantes y Recorridos. El historial está separado y contraído inicialmente.
Los resúmenes y autores legibles son proyecciones de las mismas relaciones, sin estados nuevos.

Para incorporar a una persona: un responsable técnico autorizado gestiona previamente su cuenta y autorizaciones en Usuarios; luego el responsable operativo abre Cuadrillas → Ver detalle → Integrantes → Agregar operario. El selector contiene solo elegibles y explica de forma neutral la falta de candidatos, sin identificar personas excluidas. La regla requiere cuenta Activa, asignaciones vigentes en OPERACIONES, recorrido.consultar y recorrido.operar o recorrido.modificar. No depende del nombre del rol. El acceso a Usuarios solo se ofrece con el permiso técnico correspondiente. Usuarios sugiere Operaciones según los permisos del rol sin reemplazar un sector elegido; no hay una matriz nueva de combinaciones prohibidas.
Asignación y traslado tienen textos distintos; el traslado identifica origen y destino. Si la persona
ya pertenece al destino, se deshabilita confirmar. Finalizar pertenencia explica que conserva el historial.
El diálogo nativo gestiona foco y Escape; durante la escritura se bloquean cierre y envío repetido.

Las vistas reutilizan variables/componentes del panel, campos con etiquetas y foco visible. En móvil,
las tablas presentan filas como tarjetas etiquetadas. Todos los datos se incorporan mediante textContent.
No se declara validación visual aprobada sin navegador; los tests DOM comprueban interacción, no apariencia.

## Migración y verificación

Aplicar `migration_v17_recoleccion_operativa.sql` **antes de usar el código**. Es MariaDB 10.4, idempotente, preserva las 26 tablas originales y agrega dos. `schema.sql` + `init.sql` instalan las mismas estructuras y permisos, sin pertenencias automáticas. No ejecutar schema.sql sobre una base existente.

El respaldo completo se verificó restaurándolo en una base temporal y comparando conteos de las 26 tablas. La v17 se ejecutó dos veces en esa copia antes de aplicarse en la base local. También se valida instalación limpia separada.

Desde backend: `php vendor/phpunit/phpunit/phpunit --testdox --do-not-cache-result`.
Desde programación: `node --test frontend/tests/map-experience.test.cjs`.

`RecoleccionTest` prueba SQL aislado, historial, rollback, permisos, estados e identidad.
`RecoleccionHttpIntegrationTest` usa el entorno optativo `ZEMYNA_UTF8_HTTP_BASE`, `ZEMYNA_UTF8_ADMIN_EMAIL/PASSWORD`; crea usuarios/recursos temporales, comprueba HTTP y MariaDB, ejecuta solicitudes simultáneas con sesiones distintas y retira solo sus IDs al terminar. La suite de continuidad usa además las variables READER documentadas en Utf8HttpIntegrationTest. Nunca se imprimen credenciales.

La revisión visual de escritorio/móvil queda pendiente si no hay navegador operativo. No hay asignación automática, orden de visita, GPS continuo, notificaciones ni cambios al mapa público o a incidencias.

## Contrato de la solicitud del navegador

Mi recolección usa `buildApiUrl(..., { cacheBust: false })` y `fetch` con `cache: no-store`.
La consulta inicial es GET `/backend/api/recoleccion.php`, sin query ni cuerpo. El helper
conserva su comportamiento previo para las demás pantallas. No se admite el parámetro
`t` de cache-busting en esta API ni se permiten identidades en la consulta propia:
`id_usuario` e `id_cuadrilla` devuelven 400. Las pruebas ejecutan el config.js real y
capturan el primer fetch de los scripts servidos por Apache, antes de repetirlo por HTTP.
Las referencias versionadas en recoleccion.html fuerzan la carga de ambos scripts actualizados.

La consulta propia de un recorrido compartido devuelve 409 `recorrido_ambiguo`.
La interfaz distingue sesión vencida (con enlace al login), permiso insuficiente,
falta de pertenencia, falta de recorrido, ambigüedad, validación y error del servidor.

## Asignación administrativa de recorridos

Cuadrillas → Ver detalle → Recorridos → Asignar recorrido permite seleccionar una ejecución y un vehículo mediante una relación `usa` existente de esa cuadrilla. El modal informa ruta, estado, fechas y ausencia de cuadrilla previa. No agrega relaciones directas entre cuadrilla y ruta.

Solo se admite una ejecución Pendiente sin fecha de cierre, autoría, atención ni participación previa. Se rechaza una cuadrilla con otro recorrido Pendiente o En Proceso, un vehículo inactivo/en mantenimiento o que ya participe en otro recorrido operativo. Se conservan todas las participaciones históricas; no se simula una vigencia inexistente de `usa` o `participa`. `participa.hora_inicio` guarda la hora prevista de la ejecución seleccionada; el comienzo real sigue registrándose en `recorrido.fecha_inicio` mediante la acción Iniciar.

Se bloquean usuario, cuadrilla, recorrido y vehículo dentro de una transacción. La comprobación de ocupación del vehículo utiliza lectura bloqueante actual en MariaDB para evitar resultados obsoletos bajo REPEATABLE READ. Conflictos, duplicados y concurrencia devuelven 409; no hay cambios parciales. La pertenencia del operario continúa derivándose de la sesión y de usuario_cuadrilla.

No existía una pantalla del CRUD de recorridos: el enlace Crear o gestionar recorridos abre un formulario mínimo de creación pendiente que reutiliza `api/recorridos.php`, con `recorrido.crear`, y el catálogo protegido de rutas. No se duplica el CRUD ni se habilita edición del historial desde el modal. Para asociar vehículos a una cuadrilla deben existir previamente los registros administrativos en `usa`; no se inventan ni se crean automáticamente.

Estos ajustes no agregan migraciones ni cambian v17. Las bases que aún no tengan la implementación operativa deben aplicar v17. No se modifica automáticamente ningún usuario real para hacerlo elegible.
