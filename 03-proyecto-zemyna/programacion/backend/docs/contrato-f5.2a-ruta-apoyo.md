# F5.2A / F5.5 — sugerencia consultiva OSRM para incidencia propia

## Alcance y reglas reutilizadas

Backend independiente, PHP >= 8.1. No modifica F3/F4/F6/V19 ni usa Dijkstra F5.1.
No crea recorridos, participaciones, asignaciones, intentos ni geometrías persistidas.
No incluye frontend ni instala/ejecuta OSRM. Las consultas no aceptan trabajos sin
asignar y no calculan el orden de varias visitas.

Reutiliza `RecoleccionOperativa::elegibilidad` y `validarOpcionF3` en modo lectura,
sin FOR UPDATE ni operaciones mutadoras. Añade pertenencia abierta única e iniciada,
V19 iniciada, función APOYO, ownership de incidencia/intento, estado Aceptada o En atención y
resolución geográfica. No reutiliza `contextoIncidencias` como autorización:
es informativo y no comprueba por sí solo todos esos requisitos.

F4 tiene pertenencia de cuadrilla, no propiedad personal del intento por su autor
de aceptación: un integrante habilitado de la misma cuadrilla puede consultar.
F4 no conserva V19 en el intento; se comprueba V19 actual, sin atribuirle un vehículo
histórico ni modificar ese contrato. APOYO no exige recorrido; si hay relaciones
operativas existentes, `validarOpcionF3` exige su coherencia.

## HTTP

`GET /backend/api/ruta_apoyo.php` con cookie de sesión PHP:

```text
?id_incidencia=42&origen_latitud=-34.9000000&origen_longitud=-56.1600000
```

Los tres parámetros son obligatorios y la allowlist es cerrada. ID positivo hasta
2147483647. Coordenadas decimales, máximo siete decimales, finitas, latitud [-90,90],
longitud [-180,180]; rechaza arrays, booleanos, notación exponencial y campos de
destino/identidad/cuadrilla/vehículo/URL. El origen es una declaración del usuario,
no ubicación GPS actual ni seguimiento del vehículo. No impone área Montevideo:
la instancia/dataset y el control de ajuste determinan la disponibilidad vial.

Solo GET; OPTIONS y demás métodos responden 405 con `Allow: GET`. Sin CORS abierto,
`Cache-Control: no-store`, JSON UTF-8 y `nosniff`. Fetch Metadata cross-site devuelve
403. GET no muta dominio ni exige CSRF; no ofrece autorización a clientes cross-origin.
Se libera el lock de sesión antes de la consulta. No incluir querystrings geográficos
en logs de acceso de producción; revisar esa configuración al desplegar.

Identidad exclusivamente `$_SESSION['usuario']['id_usuario']`. Cuenta activa,
permisos actuales de OPERACIONES: `recorrido.consultar`, al menos uno de
`recorrido.operar`/`recorrido.modificar`, y `incidencia.operar`. No requiere permisos
administrativos de asignar ni usa bypass ADMINISTRADOR_TI. Exige pertenencia
vigente inequívoca, V19 abierta con fecha_inicio <= ahora, vehículo APOYO activo,
fuera de mantenimiento y relación usa única. En Servicio es admisible.

Incidencia de la cuadrilla, no resuelta, sin fecha_resolucion, con exactamente un
intento abierto de esa cuadrilla, aceptación registrada y fecha_cierre NULL.
En Aceptada conserva inicio NULL y admite incidencia Pendiente o En Proceso histórico.
F5.5 admite también En atención exclusivamente con incidencia En Proceso y fechas
DATETIME válidas: aceptación <= inicio <= ahora (America/Montevideo).
Rechaza Asignada, Finalizada, Rechazada, Interrumpida y cualquier otro estado,
fechas ausentes/incoherentes, incidencias resueltas y contextos ambiguos o perdidos.
La autoría sigue las reglas F4: un integrante vigente habilitado de la misma
cuadrilla puede consultar aunque otro integrante haya aceptado/iniciado. Nunca
se cambia el autor ni se permite consultar desde otra cuadrilla. No se introduce
una propiedad personal distinta de F4 ni se alteran sus transiciones o las de F6.

Destino: punto propio de incidencia o, si ambas coordenadas son NULL, punto del
contenedor relacionado. No usa centroide de ruta ni geocodifica dirección textual.
Contenedor vinculado debe estar activo y su residuo coincidir con el vehículo.
Sin contenedor no existe clasificación de residuo verificable en F4: se advierte
que la sugerencia no certifica compatibilidad de carga ni autoriza recogida.

**Verificable significa aquí trazable a un registro autorizado, válido por rango
y con ajuste vial limitado.** No hay certificación geográfica persistida en F4.
No afirmar que el punto fue levantado por GPS o verificado en campo. La respuesta
lo advierte; una certificación adicional requeriría una fase de datos separada.

Envoltorio: `success`, `data`, `message`, `errors`, `statusCode`; errores añaden
`code` seguro y data NULL. Ejemplo de data (valores viales ilustrativos):

```json
{
  "geometria": {"type":"LineString","coordinates":[[-56.16,-34.9],[-56.161,-34.901]]},
  "distancia_metros": 150,
  "duracion_segundos": 30,
  "puntos_ajustados": [
    {"latitud":-34.9,"longitud":-56.16,"ajuste_metros":0},
    {"latitud":-34.901,"longitud":-56.161,"ajuste_metros":0}
  ],
  "origen": {"latitud":-34.9,"longitud":-56.16},
  "destino": {"latitud":-34.901,"longitud":-56.161,"fuente":"problema"},
  "motor": "OSRM",
  "perfil": "driving",
  "consultiva": true,
  "advertencias": ["Origen declarado; no representa GPS actual del vehículo."]
}
```

La respuesta real incluye advertencias adicionales sobre ubicación, vehículos
pesados, dataset, estimaciones y separación del Dijkstra académico.

| HTTP | Situación |
|---|---|
| 400 | Contrato/origen inválido |
| 401 | Sesión/usuario inválido o inactivo |
| 403 | Permisos/elegibilidad denegados o Fetch Metadata cross-site |
| 404 | Incidencia inexistente o ajena; misma respuesta para evitar IDOR |
| 405 | Método no permitido |
| 409 | Pertenencia/V19/recursos/estado incompatibles o contexto cambiado |
| 422 | Sin coordenadas válidas, NoRoute/NoSegment o ajuste excesivo |
| 502 | Respuesta del motor inválida, incluidos HTTP no admitidos |
| 503 | Configuración/infraestructura ausente, transporte fallido o límite 429 del motor |
| 504 | Timeout del motor |

No devuelve URL interna, errores cURL, mensajes OSRM sin filtrar, SQL, autores,
matrícula, historial o datos de contacto. Errores operativos reutilizados son
mensajes de dominio. Ante conflicto se debe refrescar y consultar explícitamente.

## Concurrencia y ausencia de escrituras

Dos instantáneas cortas de lectura. En MariaDB se declara REPEATABLE READ, READ ONLY;
SQLite descartable se usa en tests. Primero valida y captura contexto; termina
la transacción antes de construir cliente/llamar OSRM. Luego inicia otra lectura,
revalida autorización y contexto y compara un SHA-256 interno de permisos efectivos,
pertenencia, incidencia, intento, V19, vehículo, usa/opción, contenedor y relaciones
operativas existentes. Nunca entrega geometría si cambia la huella.

También revalida cuando OSRM falla: una revocación no queda oculta por el fallo del
proveedor. Pérdida de alcance puede devolver 404; revocación 401/403; otros cambios
409. La huella no se entrega al cliente ni reserva recursos. Sin una versión
persistida monotónica no detecta una edición que se revierta exactamente entre
instantáneas. Tampoco garantiza vigencia después de la última lectura ni revalida
destrucción de cookie de sesión durante HTTP; los permisos y actividad sí se releen.
Las acciones reales deben seguir usando exclusivamente sus APIs F4/F6.

## Configuración y seguridad del cliente

Variables de entorno del servidor, nunca del request:

```text
ZEMYNA_OSRM_URL=http://osrm:5000
ZEMYNA_OSRM_TIMEOUT=5
ZEMYNA_OSRM_MAX_SNAP_METERS=50
```

No hay URL predeterminada ni uso automático del demo público. Timeout entero
1–8 segundos (conexión máximo 2). Ajuste máximo configurable 1–100 metros.
URL HTTP/HTTPS sin credenciales, query ni fragmento. Configuración ausente/inválida:
503 seguro. La URL es una frontera de confianza administrada: restringir permisos
de configuración y salida de red; el cliente no resuelve ni bloquea hosts privados,
porque debe poder alcanzar una instancia OSRM privada. HTTP solo en red privada
controlada; HTTPS para motor remoto. Sin redirects, proxies implícitos o TLS relajado.
Requiere extensión PHP cURL; si no existe devuelve indisponibilidad, sin instalarla.

Consulta únicamente Route, perfil driving, GeoJSON completo, sin alternativas,
instrucciones ni hints. Orden OSRM: longitud,latitud. No usa Table, Trip,
fallback_speed, líneas rectas ni rutas sintéticas. Respuesta máxima 1 MiB, 10000
puntos, LineString con pares numéricos finitos, métricas no negativas, dos waypoints
y una ruta. Extremos de geometría deben corresponder a puntos ajustados dentro
de 5 m. Controla ajuste declarado y separación geodésica independiente; esta última
solo valida ubicación y jamás sustituye el costo vial. No hay reintentos automáticos.

Añadir rate limiting por sesión/IP en el despliegue antes de exposición sostenida;
el timeout y tamaño acotan cada solicitud, pero no establecen una cuota global.
No usar el demo público como backend operativo ilimitado. No enviar direcciones,
identidades ni descripciones al motor: únicamente los dos pares geográficos.

## Vehículos, dataset y alternativa local

APOYO es compatibilidad operativa, no certificación de circulación de un camión.
El perfil driving depende del perfil Lua preparado en OSRM; el path no configura
peso, altura, anchura ni accesos. No garantiza calles aptas para vehículos pesados,
tráfico actual, obras, horarios o distancias mínimas: normalmente ofrece ruta rápida.
No puede deducirse transitabilidad desde función APOYO o residuo coincidente.

Procedimiento para una instancia propia, no ejecutado en F5.2A y distinto de
la validación real del demo registrada al final de este contrato:

1. Autorizar una instancia aislada y fijar versión de OSRM, extracto OSM, fecha,
   checksum, perfil Lua y límites. Preparar el grafo fuera de la BD habitual.
2. Verificar recursos y health del motor; configurar URL privada y variables.
3. Crear fixtures autorizados en BD descartable: operario APOYO, V19 coherente,
   incidencia aceptada con punto comprobado. No reutilizar registros habituales.
4. Ejecutar ruta entre puntos conocidos, verificar sentidos, acceso y ajuste;
   ensayar NoRoute y caída del motor. Documentar distancias/duración y dataset.
5. Probar cambio concurrente de contexto y comprobar ausencia de escrituras.
   Aprobar separación OSRM/Dijkstra y limitaciones antes de frontend F5.3.

## Pruebas dirigidas

`RutaApoyoTest` usa exclusivamente SQLite en memoria y transporte OSRM simulado.
Incluye autorización, IDOR, recursos, estados, coordenadas, origen/destino y orden,
errores del motor, ajuste, geometría, cambios durante llamada y triggers que
prohíben INSERT/UPDATE/DELETE en todas las tablas del fixture. Prueba HTTP real
anónima del endpoint en servidor PHP temporal (401/403/405, JSON, no-store, sin
CORS); nunca abre la BD habitual. La validación MariaDB anterior se documenta
en F5.2B; la validación manual autenticada con OSRM real se registra más abajo.

```console
php vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/RutaApoyoTest.php
php vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/DijkstraTest.php
```

Las pruebas automatizadas no ejecutan suites completas ni usan la BD habitual.

F5.5: PHPUnit dirigido en SQLite en memoria y OSRM simulado: **16 tests, 378
aserciones, PASS**. Incluye En atención, fechas inválidas/futuras, cierre,
ownership de cuadrilla, cambios concurrentes y triggers que prohíben escrituras
en ambos estados. PHP 8.1.25; sintaxis válida. La prueba MariaDB previa no se
repite en esta ampliación y no constituye validación MariaDB de las nuevas reglas.
La consulta directa real previa a F5.4 obtuvo HTTP 200/Ok, GeoJSON y validación
del cliente; el circuito autorizado completo se confirmó posteriormente el 11/10/2026.

Validación realizada: PHP 8.1.25, PHPUnit 10.5.64. F5.2A: **13 tests, 285
aserciones, PASS**, sin skips. F5.1 independiente: **12 tests, 71 aserciones,
PASS**. Los seis PHP de F5.2A pasan `php -l`; diff sin errores de espacios.
En el cierre original F5.2A no se había validado OSRM real ni concurrencia MariaDB;
las validaciones posteriores están descritas en F5.2B y en la ampliación anterior.

## Cierre funcional F5 — 11/10/2026

Validación manual realizada y confirmada por el usuario con la incidencia
académica **INC-2026-BDA54**, desde una sesión autorizada en Mi recolección.
Entorno: Apache81 en 127.0.0.1:8081, PHP 8.1.25 y
`ZEMYNA_OSRM_URL=https://router.project-osrm.org` configurada exclusivamente
en Apache81, sin modificar el .env compartido. Integración real mediante HTTPS
con validación TLS de certificado y hostname activa.

Comprobaciones confirmadas:

1. Ruta vial real dibujada en Leaflet mediante respuesta exitosa OSRM.
2. Marcadores de origen y destino visibles.
3. Distancia y duración estimadas visibles.
4. Limpieza de la geometría al cerrar el panel de ruta.
5. Conservación del estado operativo durante la consulta.
6. Finalización normal de la atención y resolución de la incidencia académica.

No se registran valores numéricos de distancia o duración de esta prueba final:
la evidencia disponible confirma su visualización, no esas métricas concretas.
La finalización fue una acción operativa posterior; consultar la ruta no resolvió
ni modificó el trabajo. Esta evidencia manual de extremo a extremo es distinta
de las pruebas con OSRM simulado y de la validación MariaDB descartable anterior
de F5.2B, cuyo informe histórico se conserva sin cambios.

Auditoría automatizada final: Dijkstra + RutaApoyo **28 tests, 449 aserciones,
PASS**; frontend dirigido **36 tests, PASS**; sintaxis PHP/JavaScript válida.
Demo independiente Dijkstra: A → B → D → E, costo simulado 6. OSRM no es una
ejecución de nuestro Dijkstra PHP.

GO para cierre funcional académico F5. El demo público se utiliza para demostración
no comercial, con uso limitado (máximo una petición por segundo) y sin garantía
de disponibilidad, latencia o actualización del dataset. No autoriza explotación
operativa ilimitada; antes de exposición sostenida deben establecerse límites
de consulta o una instancia propia. Política:
https://github.com/Project-OSRM/osrm-backend/wiki/Demo-server

La sugerencia no garantiza circulación de vehículos pesados. No incorpora GPS,
seguimiento continuo, navegación paso a paso ni optimización de múltiples visitas.
La caída del motor produce un error seguro, sin fallback recto ni ruta inventada.
