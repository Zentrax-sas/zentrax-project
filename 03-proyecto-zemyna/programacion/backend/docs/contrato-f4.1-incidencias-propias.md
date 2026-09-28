# F4.1: consulta de incidencias propias

## Autenticación y alcance

Usar la cookie de sesión obtenida con `POST /backend/api/login.php`.
No se admite identidad Bearer en este contrato. El usuario debe estar activo,
tener `recorrido.consultar` y `recorrido.operar` o `recorrido.modificar` en
OPERACIONES, y una pertenencia abierta en `usuario_cuadrilla`.
Se reutiliza la política de elegibilidad de recolección, también para TI.
No se requiere ni se concede `incidencia.modificar`.

El endpoint revalida roles/autorizaciones; el modelo verifica elegibilidad
vigente en BD. Usuario y cuadrilla provienen de sesión y pertenencia, nunca de
parámetros. El SELECT exige `incidencia.id_cuadrilla` igual a esa cuadrilla y
comprueba que la pertenencia siga abierta. No depende de un recorrido actual.

## Listado

`GET /backend/api/recoleccion.php?view=incidencias_propias`

Parámetros opcionales:

| Parámetro | Valores |
|---|---|
| `estado` | `Pendiente`, `En Proceso`, `Resuelta` (coincidencia exacta) |
| `page` | Entero positivo, 1–1000000; por defecto 1 |
| `limit` | Entero positivo, 1–100; por defecto 20 |

Sin estado explícito devuelve Pendiente y En Proceso. Para Resuelta:
`?view=incidencias_propias&estado=Resuelta`.
Orden: prioridad Alta, Media, Baja; fecha_reporte ASC; id_incidencia ASC.
Paginación por offset con una fila adicional para calcular `has_more`, como F2.
La paginación no es una fotografía: cambios concurrentes de prioridad/asignación
pueden mover filas entre páginas; actualizar desde la primera página.

## Detalle

`GET /backend/api/recoleccion.php?view=incidencias_propias&id_incidencia=1`

Acepta un ID positivo hasta 2147483647. No admite estado/page/limit junto con
el detalle. Permite consultar una incidencia propia resuelta. Devuelve el mismo
array `data` con un elemento y `meta: {page: 1, limit: 1, has_more: false}`.
Una incidencia ajena, sin asignar o inexistente devuelve el mismo 404.

## Ejemplo de respuesta

Ejemplo verificado con el fixture aislado de `IncidenciasPropiasTest`:

```json
{
  "success": true,
  "statusCode": 200,
  "data": [{
    "id_incidencia": 1,
    "tracking_number": "INC-2026-00001",
    "estado": "Pendiente",
    "prioridad": "Alta",
    "tipo_problema": "Contenedor Desbordado",
    "descripcion": "Reporte propio",
    "fecha_reporte": "2026-09-01 08:00:00",
    "fecha_resolucion": null,
    "id_contenedor": 1,
    "contenedor_codigo": "C-001",
    "contenedor_direccion": "Calle de prueba 123",
    "id_ruta": 1,
    "ruta_nombre": "Ruta del contenedor",
    "latitud": -34.9,
    "longitud": -56.1,
    "ubicacion_origen": "contenedor"
  }],
  "meta": {"page": 1, "limit": 1, "has_more": false}
}
```

IDs se serializan como enteros; contenedor/ruta pueden ser null. Coordenadas
son números o null; si no hay un par válido, ambas y su origen son null.
`ubicacion_origen` es `problema`, `contenedor` o null. Prima el punto registrado
en la incidencia sobre el del contenedor. Dirección es la del contenedor,
no una dirección inventada para un punto manual. `fecha_resolucion` puede ser
null incluso en un registro histórico resuelto.

`id_ruta` es la ruta directamente relacionada con la incidencia o, en su defecto,
la del contenedor. No es una ejecución de recorrido: F3 no persiste
`incidencia → id_recorrido`. No se devuelve `id_recorrido` ni información personal
del autor. Las fechas se conservan como cadenas de BD; las operaciones existentes
generan fechas en America/Montevideo, sin añadir un offset a registros históricos.

## Errores y métodos

| HTTP | Significado |
|---|---|
| 200 | Consulta válida; lista vacía si no hay resultados |
| 400 | Parámetros desconocidos, identidad/cuadrilla enviadas por cliente, ID/filtro/paginación inválidos o filtros de lista en detalle |
| 401 | Sin sesión, identidad inválida o usuario inactivo |
| 403 | Sin permisos/elegibilidad operativa vigente; TI sin elegibilidad también |
| 404 | Incidencia no accesible para la cuadrilla vigente |
| 405 | Método distinto de GET; `Allow: GET` para esta vista |
| 409 | Sin pertenencia vigente, `code: "sin_pertenencia"` |
| 503 | No se pudo consultar la BD o validar autorización |

Ejemplo de pertenencia ausente:

```json
{"success":false,"statusCode":409,"message":"No tenés pertenencia vigente a una cuadrilla.","code":"sin_pertenencia"}
```

Los guards generales de autenticación pueden responder sin `statusCode` dentro
del JSON; usar siempre el código HTTP. OPTIONS conserva el preflight global
existente (204). Las respuestas de consulta usan `Cache-Control: no-store`.
No enviar `id_usuario`, `id_cuadrilla`, `id`, `id_recorrido`, `admin`, `accion`
ni aliases: la allowlist es cerrada. GET no requiere cuerpo.

## Compatibilidad y límites

La vista propia no modifica incidencias ni implementa F4.2. POST/PUT/DELETE
dirigidos a esta vista no ejecutan acciones operativas. La consulta propia de
recorridos y sus acciones existentes permanecen disponibles en sus rutas previas.

Los endpoints administrativos y permisos globales existentes no se restringen
en F4.1. Un usuario con autorización global puede seguir accediendo a ellos según
sus reglas; no usar esos listados para implementar ownership en la app.
La nueva vista no concede esos permisos ni utiliza el PUT administrativo.

F4.2 deberá definir aceptación, rechazo, desplazamiento y etapas de atención;
no se les asigna ahora un estado o campo con otro significado.
