# V19 — Función habitual y utilización temporal de vehículos

## Alcance y despliegue

`vehiculo.funcion_operativa` admite `REGULAR`, `APOYO` o NULL (clasificación pendiente).
No reemplaza tipo de residuo, disponibilidad ni compatibilidad. Una función habitual
no prohíbe otros trabajos. La migración no clasifica vehículos ni crea utilizaciones.

`migration_v19_asignacion_vehiculo.sql` es reejecutable sobre V18: agrega columna,
CHECK, tabla y permisos; repetirla no borra clasificación ni historial. El DDL hace
commit implícito: requiere el procedimiento de respaldo/despliegue del proyecto.
`schema.sql` es exclusivamente para instalación limpia; nunca aplicar sobre datos.
La base habitual requiere autorización separada antes de migrar. Este código depende
de V19: no desplegar sus endpoints sobre una instalación que conserve solo V18.

## Datos e integridad

`asignacion_vehiculo_operativa` guarda cuadrilla, vehículo, inicio/cierre, actores y
motivo de cierre. Dos columnas generadas con UNIQUE garantizan una fila abierta por
cuadrilla y una por vehículo. FK RESTRICT conservan recursos y actores. CHECK valida
cronología y correspondencia del cierre con actor y motivo. No hay estado redundante.

`usa` sigue siendo autorización administrativa. Abrir/cambiar exige exactamente una
pareja existente, vehículo activo, fuera de mantenimiento y clasificado, e integrante
operativamente elegible según RecoleccionOperativa. No crea `usa` ni altera `participa`.
No existe FK de la nueva pareja hacia `usa`: esa condición se revalida con bloqueo en
backend. Cualquier futura edición/baja de autorizaciones debe preservar esta regla.

Solo se puede cerrar una fila abierta cuya versión (ID) coincida. Cambiar cierra e
inserta dentro de una transacción; un fallo revierte también el cierre. No hay endpoint
de edición o eliminación histórica. Un cierre puede realizarse aunque la cuadrilla
haya perdido elegibilidad; eso permite retirar una utilización sin bloquear su cierre.

Mientras exista utilización abierta, el CRUD del vehículo rechaza con 409 baja,
mantenimiento y cualquier cambio de clasificación (incluido NULL). No cierra nada
automáticamente. Omitir funcion_operativa en un PUT legacy conserva el valor actual;
en POST la omisión crea NULL. Enviar NULL explícito solicita quitar clasificación.

## Seguridad y concurrencia

Sesión PHP identifica al actor. Se consulta actividad, rol vigente, permiso y sector
OPERACIONES directamente en BD. No se usa el bypass TI de hasEffectivePermission.
La matriz v8/v10 respalda conceder consultar/modificar a RESPONSABLE_SECTORIAL y
ADMINISTRATIVO_OPERATIVO. No se concede a TI, OPERARIO ni INSPECTOR.

Las mutaciones revalidan dentro de la transacción, bloquean usuarios e integrantes,
cuadrilla, asignación vigente y recursos afectados; las lecturas de conflicto son
bloqueantes en MariaDB. Las dos unicidades son defensa adicional a los bloqueos.
Deadlock, timeout de bloqueo o UNIQUE concurrente devuelve 409 y rollback; el cliente
debe consultar de nuevo. No se promete ausencia absoluta de deadlocks entre flujos.

Fechas: reloj del servidor en America/Montevideo. No se aceptan autores ni fechas
del cliente. Cerrar/cambiar requiere motivo no vacío de hasta 150 caracteres.

## API administrativa

`api/asignaciones_vehiculo.php`, sesión requerida, respuestas no-store.

- GET sin parámetros: cuadrillas y `puede_modificar`.
- GET `id_cuadrilla=N&page=1`: actual, autorizaciones y historial (20 por página,
  `has_more`; page máximo 1.000.000).
- POST abrir: `accion`, `id_cuadrilla`, `id_vehiculo`.
- POST cerrar: `accion`, `id_cuadrilla`, `id_asignacion_vehiculo`, `motivo`.
- POST cambiar: mismos campos de cerrar más `id_vehiculo`.

Acciones exactas: `abrir`, `cerrar`, `cambiar`. Los IDs de cuadrilla y vehículo son
recursos administrativos autorizados, nunca identidad del actor. Campos extra se
rechazan. 400 validación, 401 sesión/actividad, 403 permisos, 404 recurso inexistente,
409 conflicto, 405 método y 503 persistencia no disponible. Ningún SQL interno se expone.

Panel: Camiones permite clasificar. Cuadrillas → detalle muestra utilización actual,
función, inicio y estado del vehículo, historial y acciones según permiso. Las opciones
provienen de `usa`; el servidor vuelve a validar ocupación al confirmar. Los datos
externos se muestran como texto, no como HTML.

## Coherencia y fases posteriores

Abrir/cambiar rechaza recorridos Pendiente/En Proceso incompatibles o ambiguos. Asignar
e iniciar un recorrido, validar opciones F3 y reactivar mediante CRUD también comprueban
la utilización abierta. Finalizar un recorrido nunca cierra la utilización.

F3 conserva su requisito de recorrido: no incorpora apoyo sin recorrido. Su ampliación
queda pendiente. F4.1/F4.2 conserva ownership de cuadrilla, permisos y estados; no se
agrega vehículo a atencion_incidencia. El dashboard mantiene sus métricas actuales por
recorrido y estado; una utilización abierta no es una nueva métrica de trabajo ocupado.

Fuera: GPS/V20, apps móviles, Dijkstra/grafo, N:M de residuos, nuevas compatibilidades,
F6 y corrección del recorrido histórico de Beta. Vehículo 3 inactivo sigue rechazado.

## Validación reproducible

Desde backend, PHP 8.1 o compatible con PHPUnit instalado:

```text
php vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests/AsignacionVehiculoTest.php
php vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests
```

MariaDB es optativa mediante `ZEMYNA_V19_MARIADB=1`. Usa credenciales configuradas para
crear una base nueva `zemyna_v19_test_<12 hex>`, nunca un nombre recibido del cliente.
Aplica el schema real, simula V18, migra y repite V19, comprueba CHECK/FK/UNIQUE,
rollback y operaciones concurrentes con procesos PHP y conexiones independientes.
Reutiliza solo esa base aislada entre casos y la elimina al terminar la clase. Informa
su nombre en stderr. No ejecutar pruebas de integración HTTP sobre la base habitual.

```text
php vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests/AsignacionVehiculoMariaDbTest.php
node --test frontend/tests/*.test.cjs
```

El comando Node se ejecuta desde programacion. Las pruebas DOM no sustituyen la revisión
visual manual ni validan HTTP contra Apache. Los fixtures de regresión SQLite incluyen
la nueva tabla vacía; no introducen asignaciones ficticias en el sistema habitual.

## Resultado de validación — 2026-10-03

- Suite backend: 900 casos, 3569 aserciones, 42 omitidos y ningún fallo.
- Suite MariaDB V19 habilitada por separado: 31 pruebas, 150 aserciones y ningún fallo.
  Se ejecutó en una base temporal generada por el test y eliminada al terminar.
- Suite frontend: 90 pruebas aprobadas, sin fallos ni omisiones.
- Sintaxis de todos los PHP nuevos/modificados y `git diff --check`: sin errores.

En esta validación inicial la migración V19 aún no se había aplicado a la base
habitual. Posteriormente, con autorización explícita, se realizó el despliegue
local con respaldo y validación HTTP/visual en Apache. El resultado, los fixtures
retenidos y el estado final constan en
[informe-despliegue-local-v19-20261003.md](informe-despliegue-local-v19-20261003.md).
