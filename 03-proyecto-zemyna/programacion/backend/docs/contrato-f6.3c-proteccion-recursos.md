# F6.3C.1 — protección transaccional de recursos

Sin cambios de schema, frontend, F4 ni reglas funcionales F6.2.
`ProteccionSolicitudOperativa` es un guard interno; exige una transacción activa.
No autoriza operaciones ni sustituye los permisos de los endpoints existentes.

## Trabajo protegido y errores

Identifica solicitud e intento En atención, intento abierto, cuadrilla coincidente
y referencia exacta a la V19. Comprueba además las incoherencias relevantes:
uno de los estados En atención sin el otro, cierre de un intento En atención,
V19 cerrada, discrepancia de cuadrilla y solicitud En atención sin intento abierto
o con otra V19. Esas situaciones devuelven 409; no se interpretan como ausencia
de trabajo. Los intentos históricos correctamente cerrados de trabajos actuales
no bloquean recursos anteriores.

Los mensajes no incluyen identidad, contacto, descripción ni SQL. No reintenta
automáticamente. Cada modelo revierte la transacción completa ante excepciones.
V19 y vehículo conservan sus traductores de deadlock/timeout a 409; UsaController
añade traducción segura de 1205/1213/1062 y relación referenciada (1451) a 409.

## Integración mínima

- `AsignacionVehiculo::mutate`: después de bloquear y revalidar la V19 actual,
  `assertAssignmentCanClose` impide cerrar/cambiar antes de cualquier escritura.
- `Vehiculo::protectedWrite`: conserva `protectVehicle`, que ya protege bajas,
  mantenimiento y función con V19 abierta. Añade `assertVehicleCompatible`:
  el residuo propuesto debe coincidir con todos los trabajos protegidos. Permite
  matrícula, marca, modelo y capacidad cuando no invalidan la compatibilidad.
- `Usa::create/update/delete`: ahora cada escritura tiene su propia transacción.
  Lee la pareja anterior como pista, bloquea vehículos anterior/nuevo en orden
  ascendente, relee la fila con FOR UPDATE y rechaza cambios ocurridos durante
  la espera. `assertUseChange` inspecciona ambas parejas y las V19 del vehículo
  sin esconder inconsistencias mediante un filtro prematuro de cuadrilla.
  Bloquea pérdida de la pareja necesaria y duplicación desde create/update.
  Permite update sin cambios y relaciones distintas que no vuelven ambigua
  la pareja requerida. Conserva validaciones y FK previas.

La nueva regla F6 no protege Asignada/Aceptada ni intenta impedir modificaciones
de sus recursos. Se conservan restricciones previas, por ejemplo baja de un
vehículo con V19 abierta o FK de participa. Tras Finalizada el guard libera los
recursos; siguen rigiendo las restricciones anteriores.

## Protocolo concurrente

F6.3A ya realiza lecturas FOR UPDATE de solicitud e intentos, usuario/permisos,
pertenencia, cuadrilla, V19 exacta, recorridos existentes, vehículo y pareja usa
antes de iniciar atención. Se inspeccionó y reutilizó ese protocolo sin modificarlo.

Los guards se ejecutan **después** de adquirir el recurso compartido:
V19 en cerrar/cambiar; vehículo en CRUD vehículo y CRUD usa. En usa también
se bloquea la fila anterior y la pareja correspondiente. Todos los chequeos del
guard usan FOR UPDATE, de modo que leen el estado actual tras una espera y no
la instantánea anterior de REPEATABLE READ. La pista inicial de usa nunca decide
la autorización de escritura: se compara contra la relectura bloqueante.

Si inicio obtiene primero el recurso, conserva su lock hasta registrar ambos
estados En atención y commit: la modificación espera y luego ve el trabajo.
Si la modificación obtiene primero el recurso, puede modificarlo mientras está
Aceptada: inicio espera y revalida V19, vehículo, residuo y usa; no puede iniciar
con un recurso invalidado. No pueden confirmar ambas operaciones incompatibles.

El orden heredado no es globalmente uniforme: F6 y F3 parten del trabajo, V19
parte de usuarios/cuadrilla/V19, y CRUD vehículo/usa parte de vehículo. Los guards
leen trabajo después del recurso y pueden formar ciclos. Se mantienen los órdenes
existentes para limitar el impacto compartido; vehículos de usa se ordenan por ID.
Los deadlocks/timeout se tratan como 409 con rollback, no se promete ausencia de
deadlocks. Las carreras reales comprueban códigos seguros y que nunca quedan
En atención junto con un recurso invalidado ni dos commits incompatibles.

## Pruebas y pendientes

`ProteccionSolicitudOperativaMariaDbTest` usa schema oficial en servidor TEMP,
base aleatoria propia `f63_test_*`, conexión explícita y rechazo de datadir habitual.
Incluye estados, liberación, modificaciones inocuas, parejas anterior/nueva,
inconsistencias, carreras contra inicio y rollback con trigger. Worker separado
`fixtures/f63c_worker.php` exige el mismo guard de servidor/base descartables.
Regresión dirigida F3/F4/V19/F6.2/F6.3A también en MariaDB descartable.

La prueba heredada F4 `testReasignacionContraAceptacion` tenía un fixture anterior
al contrato F3/V19: actor sin consultar/modificar y body sin V19. Se actualiza solo
ese caso con permisos vigentes reales, V19/función REGULAR de ambas cuadrillas y
ID esperado explícito. No cambia producción, transiciones ni permisos del sistema.

F6.3C.2 añade continuidad ordinaria y recuperación ante ausencia comprobada de
habilitados; ver [contrato específico](contrato-f6.3c2-continuidad-recuperacion.md).
No se conserva autorización revocada, no se permite finalizar con recursos
inválidos. La recuperación pertenece a C.2. DML externo que evita
estos contratos y los cambios directos en otros recursos (por ejemplo participa)
no quedan protegidos por este guard. No se promete protección universal frente
a escrituras ajenas a los caminos auditados.
