# F6.2A — Administración de solicitudes especiales

API independiente `api/solicitudes_admin.php`. No cambia alta pública,
tracking, F4, V19, schema ni migraciones. No expone el CRUD legacy.

## Identidad y permisos

Sesión PHP autenticada, usuario activo. Lectura exige `solicitud.consultar`;
opciones y mutaciones exigen consultar + modificar. Concesiones consultadas
directamente en BD mediante usuario_rol/rol_permiso/permiso, en OPERACIONES,
con fecha_desde <= fecha servidor y fecha_hasta NULL o >= fecha servidor.
No hay bypass TI ni autorización por nombre de rol o cache de sesión.
Actor e hitos proceden exclusivamente de sesión y reloj America/Montevideo.

## HTTP y CSRF

GET autenticado exitoso entrega `csrf_token` estable durante la sesión.
PUT requiere `X-CSRF-Token` igual al token de sesión. Origin, si existe,
debe coincidir exactamente en esquema, host y puerto con la petición;
Sec-Fetch-Site cross-site se rechaza. Clientes sin Origin son compatibles,
pero deben obtener y enviar el token con la misma cookie de sesión.
No se admiten URLs Origin con ruta, credenciales, query o fragmento.
La API no depende de permisos ni cambios en session.php. No admite DELETE.
Todas las respuestas administrativas llevan Cache-Control: no-store.
OPTIONS conserva el preflight general de bootstrap; no realiza operaciones.

Respuesta: success, data, message, errors, statusCode; HTTP coincide con statusCode.
401 sin sesión/cuenta inactiva; 403 permisos o CSRF; 400 parámetros/JSON;
404 solicitud inexistente después de autorizar; 409 estado, versión, candidato,
unicidad, deadlock o timeout; 500 fallo interno; 503 conexión indisponible.
Errores no incluyen SQL, excepciones, contacto ni contenido de solicitudes.

## Lectura

GET sin id: page=1, limit=20 (máximo 100, página máxima 100000).
Filtros opcionales estado (enum V21 o Cerradas), tipo_solicitud
(Reciclables/Gran volumen), desde/hasta YYYY-MM-DD por fecha de creación.
Hasta incluye todo el día, desde no puede superar hasta.
Orden fecha DESC,id_solicitud DESC. data contiene items,page,limit,has_more.
Items no incluyen dirección, descripción, email ni teléfono. Informan residuo
por ID, cuadrilla, fecha de confirmación y flags históricos.

GET id=<entero>&historial_page=1: detalle administrativo con contacto necesario,
tipo_residuo del catálogo, intento_actual e historial real (20 por página),
historial_page/has_more, flag historica_programada_sin_intento, capacidades y
motivo_bloqueo. Cada intento conserva id_cuadrilla e id_asignacion_vehiculo
históricos, actores y fechas; no se sustituye su V19 por la actual.
Entrega estado_esperado, id_atencion_esperada (NULL explícito) y version_esperada.
La versión SHA256 cubre la fila de solicitud y todos los intentos; no necesita
columna nueva y detecta ciclos de asignación/desasignación y clasificación.

GET id=<entero>&opciones=asignacion: requiere modificar, data={items,motivo}.
Vacío es 200; solicitud sin confirmación explica el bloqueo. Estados terminales,
En atención o incoherencias operativas producen 409.

## PUT

Campos comunes obligatorios: accion,id_solicitud,estado_esperado,
version_esperada,id_atencion_esperada (incluido NULL explícito).
IDs positivos enteros o strings decimales, hasta INT firmado; bool/float/array
no se admiten. Versión hexadecimal SHA256 de 64 caracteres.
Campos desconocidos, autores, fechas, vehículo libre o recorrido: 400.

| accion | Campos adicionales | Efecto |
|---|---|---|
| confirmar_residuo | id_tipo_residuo | Clasificación explícita existente, fecha/actor servidor |
| asignar | id_cuadrilla,id_asignacion_vehiculo,id_usa | Programada, nuevo intento Asignada |
| reasignar | mismos IDs, motivo | Interrumpe anterior y crea nuevo Asignada |
| desasignar | motivo | Interrumpe si existe, Pendiente, cuadrilla NULL |
| cancelar | motivo | Interrumpe si existe, Cancelada, cuadrilla NULL, datos de cancelación |

Motivo obligatorio, trim y 1..500 caracteres. Éxito devuelve detalle actualizado.
Confirmar solo Pendiente/Programada sin intento abierto. No inferencia automática.
Confirmación pública ya válida es suficiente; históricos sin confirmación deben
confirmarse antes de asignar. Correcciones sobrescriben la última confirmación:
V21 no contiene auditoría general de clasificaciones.

Reasignación requiere Programada e intento Asignada/Aceptada, destino distinto
en cuadrilla o versión V19. Desasignar requiere Programada. Cancelar admite
Pendiente/Programada. En atención y terminales no permiten estas mutaciones.
Inconsistencia entre estado, cuadrilla e intento se bloquea con 409.
Histórica Programada sin ningún intento: flag explícito; admite confirmar,
asignación inicial, desasignar y cancelar; no reasignar. No fabrica historia.
El motivo de devolver esa histórica a Pendiente no tiene campo persistente V21.

## Candidatos y recursos

Reutiliza RecoleccionOperativa::validarOpcionF3/opcionesIncidencia sin cambiarlos.
Añade V19 abierta con fecha_inicio <= ahora, APOYO, residuo del vehículo igual
al confirmado e integrante con pertenencia iniciada/vigente y solicitud.operar
vigente en OPERACIONES. Conserva elegibilidad existente de recorridos: activo,
recorrido.consultar y recorrido.operar o recorrido.modificar. La misma persona
debe cumplir ambas condiciones. Vehículo activo y no En Mantenimiento;
En Servicio admisible. usa única. APOYO no exige recorrido, pero uno relacionado
no puede ser ambiguo/incoherente. No crea ni modifica recursos.
No limita una solicitud por cuadrilla. Cambiar/cerrar V19 posteriormente no
reasigna ni interrumpe automáticamente: conserva referencia histórica del intento.

## Transacciones y bloqueos

Autorización previa evita filtración de existencia a no autorizados.
Luego transacción: solicitud -> intentos (lecturas FOR UPDATE MariaDB) -> actor
y concesiones actuales -> validación F3 de integrantes/pertenencias, cuadrilla,
V19, recorridos/participaciones si existen, vehículo y usa -> residuo V19/vehículo
e integrante habilitado F6. Estado, intento y versión se comparan bajo bloqueo.
Fecha servidor se captura tras esperar; cronología futura produce 409.
Interrupción, nuevo intento y solicitud se escriben en una sola transacción;
respuesta se construye antes del commit. Rollback ante cualquier fallo.
Solicitud serializa incluso si no hay intento; uq_as_abierta es segunda defensa.
Opciones no reservan recursos. No reintentos ciegos ni reparación automática.

Se conserva la inversión conocida entre locks V19 y CRUD vehículo, y posibles
conflictos actor/integrantes. No se promete ausencia de deadlocks: 1205/1213
devuelven 409 con rollback, igual que 1062. Cliente debe refrescar y confirmar.
No bloquea V19 durante toda la atención ni modifica sus contratos.

## Tests

SolicitudAdminTest: SQLite en memoria, contrato/permisos/transiciones/candidatos.
SolicitudAdminApiAccessTest: CSRF y endpoint real con doble del controlador.
SolicitudAsignacionV19MariaDbTest hereda contrato y prueba carreras con procesos
independientes, cambios V19/permisos/vehículo/usa y rollback después de interrumpir.
Requiere ZEMYNA_F62_MARIADB_DSN y opcional ZEMYNA_F62_MARIADB_PASSWORD.
Rechaza cualquier datadir fuera de TEMP/zemyna-f61-<hex>/data o
TEMP/zemyna-f62-<hex>/data. Crea/elimina únicamente f62_test_<12 hex> aleatorias.
Sin DSN se omite; nunca usar la instancia habitual para estos tests.

Primero backend/tests dirigidos; frontend administrativo es etapa posterior.
