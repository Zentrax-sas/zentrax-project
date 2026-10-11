# F6.3A — solicitudes propias del operario

API independiente: `api/solicitudes_propias.php`. No cambia F4, alta pública,
tracking, V21 ni el contrato administrativo F6.2. No requiere recorrido para APOYO.

## Autorización y alcance

Identidad exclusivamente de `$_SESSION['usuario']['id_usuario']`. Cuenta activa,
roles vigentes en OPERACIONES, `solicitud.operar` y elegibilidad operativa de F6.2:
`recorrido.consultar` y al menos `recorrido.operar` o `recorrido.modificar`.
Sin excepciones por nombre de rol ni permisos declarados por el cliente.
Exige exactamente una pertenencia abierta, cuyo inicio ya haya ocurrido.
Solicitud e intento abierto deben corresponder a esa cuadrilla actual.

No devuelve históricos sin intento, trabajos cerrados ni trabajos de otras
cuadrillas. Un detalle o acción fuera del alcance devuelve 404. Un usuario que
perdió toda pertenencia recibe 409; uno sin habilitación recibe 403.

## GET

Listado: sin parámetros o `page=1&limit=20` (máximo 100 por página).
Detalle: `id_solicitud=14`. Parámetros desconocidos o inválidos: 400.
Orden estable: `fecha DESC, id_solicitud DESC`.

Envoltorio: `success`, `data`, `message`, `errors`, `statusCode`. GET exitoso añade
`csrf_token`, vinculado a la sesión mediante `solicitud_propia_csrf`.
`data`: `items`, `page`, `limit`, `has_more`, `cuadrilla.id_cuadrilla`.
Detalle conserva el mismo envoltorio, con un único elemento en `items`.

Cada elemento contiene `id_solicitud`, `tracking_number`, `fecha`,
`tipo_solicitud`, `id_tipo_residuo`, `tipo_residuo`, `estado`, `direccion`,
`descripcion`, `id_cuadrilla`, `fecha_finalizacion`, `atencion`, `vehiculo`,
`estado_esperado`, `estado_operativo_esperado`, `version_esperada`,
`capacidades`, `motivo_bloqueo`.
`atencion`: `id_atencion_solicitud`, `estado`, `id_asignacion_vehiculo`.
`vehiculo`: V19 registrada, ID de vehículo, matrícula y función; NULL si inválido.
No incluye email, teléfono, autores administrativos ni historial de otras atenciones.

Trabajos Asignada/Aceptada/En atención con recursos inválidos siguen visibles:
`motivo_bloqueo` explica el impedimento y todas las capacidades son falsas.
La autorización del usuario y pertenencia siguen siendo obligatorias.

## PUT

JSON objeto; exige `X-CSRF-Token` del GET. Si hay Origin debe coincidir exactamente
con el origen del servidor; cross-site se rechaza. Clientes sin Origin funcionan
con token válido. Respuestas `Cache-Control: no-store`.

Campos obligatorios:

```json
{
  "accion": "aceptar_solicitud",
  "id_solicitud": 14,
  "id_atencion_solicitud": 1,
  "estado_esperado": "Programada",
  "estado_operativo_esperado": "Asignada",
  "version_esperada": "hash SHA-256 de 64 caracteres obtenido del GET"
}
```

`version_esperada` es opaca: no debe calcularla el cliente. Cambia con la solicitud
y sus intentos reales. No es intercambiable con la versión del endpoint administrativo.
Se rechazan campos desconocidos, identidad, autores, fechas o cuadrilla del cliente.

| Acción / capacidad | Origen del intento | Resultado |
|---|---|---|
| aceptar_solicitud | Asignada | Aceptada; solicitud Programada |
| rechazar_solicitud | Asignada | Rechazada; solicitud Pendiente; cuadrilla NULL |
| iniciar_atencion_solicitud | Aceptada | Ambos En atención |
| finalizar_atencion_solicitud | En atención | Ambos Finalizada |

Solo rechazo admite y exige `motivo`, texto recortado de 1–500 caracteres.
No se puede rechazar después de aceptar. Cada hito registra actor de sesión y
fecha del servidor en America/Montevideo. Finalización conserva la cuadrilla,
cierra el intento y registra `solicitud.fecha_finalizacion`.
Resultado: IDs de solicitud/intento, `estado_operativo`, `estado`, `fecha_operacion`.

## Transacciones, conflictos y errores

Lectura consistente dentro de transacción. Mutación: solicitud FOR UPDATE,
todos sus intentos FOR UPDATE, usuario/roles/permisos y pertenencia actuales,
validación compartida F3 de integrantes → cuadrilla → V19 → recorridos/vínculos
existentes → vehículo → usa. Luego vuelve a comprobar fecha de V19 y residuo.
Mantiene el orden de F6.2; el chequeo de pertenencia se incorpora antes de recursos.
No sustituye la V19 del intento por una nueva V19 abierta.

Revalida después de esperar locks; estados, intento y versión esperados evitan
acciones obsoletas. Un cambio administrativo que quita el trabajo del alcance
puede devolver 404. Otros cambios de contexto devuelven 409. Rollback completo
ante cualquier excepción; deadlock (1213), timeout (1205) y unicidad (1062): 409,
sin reintentos automáticos. El cliente debe refrescar listado/detalle.

400 contrato inválido; 401 sesión/cuenta inválida; 403 permisos o CSRF; 404 trabajo
no disponible; 409 conflicto; 500 fallo inesperado; 503 infraestructura no disponible.
Los errores no devuelven SQL, datos personales ni mensajes internos de PDO.

## Integración F6.3C

F6.3C.1 protege V19, vehículo compatible y usa dentro de sus transacciones,
reutilizando los locks de este flujo. Ver `contrato-f6.3c-proteccion-recursos.md`.
F6.3C.2 protege la continuidad en cambios ordinarios, permite la suspensión de
seguridad y recupera exclusivamente ante cero integrantes habilitados. Ver
[contrato de continuidad y recuperación](contrato-f6.3c2-continuidad-recuperacion.md).
Vencimientos automáticos y escrituras ajenas a las APIs pueden dejar un trabajo
temporalmente imposible de finalizar; no conservan autorización revocada.
F6.2 bloquea reasignar/desasignar/cancelar En atención. No se ofrece bypass para
usuarios revocados ni se conserva una autorización pasada.

Puntos compartidos de C.1 y C.2:

- `AsignacionVehiculo::mutate` (cerrar/asignar/sustituir) y `protectVehicle`: fila
  `asignacion_vehiculo_operativa` referenciada por el intento.
- `Vehiculo::update/delete` (`protectedWrite`): fila `vehiculo`
  (activo, mantenimiento, función, residuo).
- `Usa::create/update/delete`: pareja `id_cuadrilla/id_vehiculo`; no existe ID usa persistido
  en V21, se valida la relación actual única.
- `RecoleccionOperativa::asignar` (pertenencias), `Usuario::update/delete/activar`,
  `assignRole/finalizarAsignacion/actualizarVigenciaAsignacion/actualizarAsignacion`
  y `UsuarioRol::create/finalizar`: usuario, pertenencia y habilitación.
- `SolicitudPropiaOperativa::operate/resources` en iniciar/finalizar y
  `SolicitudOperativa::mutate/candidate`: solicitud/intento y recursos compartidos.

Los guards C.2 coordinan una puerta compartida sobre las cuadrillas antes de los
locks heredados; verifican el resultado completo dentro de la transacción y
revalidan con FOR UPDATE. Los caminos de recursos C.1 conservan su orden y pueden
producir deadlocks: 409 seguro y rollback, sin reintentos. La suspensión DELETE
no exige relevo; la recuperación comprueba cero habilitados. No se bloquean por
continuidad Asignada/Aceptada ni trabajos ya Finalizada.

GO para integrar frontend del flujo normal usando capacidades del servidor.
No se afirma protección universal ante DML externo o escrituras que eviten los
contratos. Los vencimientos automáticos requieren relevo legítimo o recuperación.

## Pruebas

Tests unitarios/contrato en SQLite y endpoint real con controlador doble aislado.
Tests MariaDB con schema oficial exclusivamente en servidor TEMP autorizado por
`ZEMYNA_F63_MARIADB_DSN`, base aleatoria `f63_test_*`; rechazan datadir habitual.
Hereda los escenarios funcionales, suma carreras reales entre operarios y contra
Administración, revalidaciones tras espera, timeout/deadlock y rollback con trigger.
No carga `.env` para establecer la conexión destructiva y elimina solo su base
descartable. Sin DSN descartable se omite explícitamente la suite MariaDB.
