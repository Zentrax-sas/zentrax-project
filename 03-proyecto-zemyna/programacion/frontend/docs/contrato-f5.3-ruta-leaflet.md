# F5.3 — ruta consultiva en Mi recolección

## Alcance y autorización

La acción «Ver ruta sugerida» se incorpora al detalle de una incidencia propia.
El contexto F4 permite mostrarla cuando informa permiso operativo, función APOYO,
V19 vigente, vehículo activo disponible o en servicio y coordenadas puntuales.
F5.5 permite intento Aceptada con incidencia Pendiente/En Proceso, o En atención
con incidencia En Proceso, siempre sin resolución. Asignada, Finalizada,
Rechazada, Interrumpida y demás estados quedan excluidos. El backend comprueba
que Aceptada no tenga inicio y que En atención tenga aceptación e inicio válidos,
ordenados y no futuros, con fecha_cierre NULL. No se cambian autores ni acciones.
Estos datos son indicios para la interfaz, no autorización: F5.2A comprueba los
permisos efectivos, pertenencia, ownership, recursos, ubicación y concurrencia.
Una incidencia no elegible o sin coordenadas muestra una explicación sin consulta.

No se modifica el backend ni las transiciones de F4, recorridos o asignaciones.
Los únicos puntos de integración en incidencias son montar la sección y limpiarla
al cambiar/cerrar el detalle o iniciar una acción operativa existente.
No hay persistencia de origen ni geometría. No hay rutas calculadas en JavaScript.

## Origen y contrato HTTP

Al abrir, el mapa se centra en el punto disponible de la incidencia. Ese centro
no selecciona automáticamente el origen. El usuario elige mediante un clic en
el mapa o mueve el mapa y pulsa «Usar centro del mapa como origen» (también permite
selección con teclado mediante las flechas de Leaflet). El texto identifica el
origen como manual, sin atribuirlo a la posición actual del camión.
No se solicita geolocalización ni se activa seguimiento GPS.

La única petición del módulo es GET autenticado por cookie, sin caché:

```text
/backend/api/ruta_apoyo.php?id_incidencia=42&origen_latitud=-34.9000000&origen_longitud=-56.1600000
```

No se envía destino, usuario, cuadrilla, vehículo ni URL del motor. El destino
se obtiene exclusivamente de la respuesta autorizada del backend. No se consulta
OSRM directamente desde el navegador. Se reutilizan Leaflet y el mapa base OSM
ya utilizados en Mi recolección; no se añaden dependencias.

Se valida `success`, carácter consultivo, motor OSRM, perfil driving, origen
coincidente, destino válido, métricas finitas no negativas, LineString GeoJSON
con 2–10000 pares longitud/latitud, dos puntos ajustados dentro del máximo
contractual de 100 m y advertencias de texto acotadas. Leaflet recibe directamente
esa geometría mediante `L.geoJSON`; no se conectan los puntos con líneas propias.
Las coordenadas de los marcadores se convierten al orden latitud/longitud de Leaflet.

Se muestran origen manual, destino autorizado y fuente, distancia estimada en km,
duración estimada en minutos, motor/perfil, ajustes a la vía y advertencias del
backend mediante `textContent`. Las estimaciones se identifican como no garantizadas.
La interfaz distingue OSRM del Dijkstra académico PHP y presenta:

> Ruta sugerida. No sustituye las indicaciones viales ni garantiza acceso para vehículos pesados.

## Errores y ciclo de vida

- Durante la consulta se deshabilita consultar; elegir otro origen cancela la anterior.
- 400: revisar el origen; 401: limpiar, emitir sesión expirada e ir al login.
- 403/404/409: limpiar geometría y mapa, bloquear consulta y pedir actualizar el detalle.
- 422: sin ruta disponible o ubicación con ajuste excesivo; nunca hay fallback recto.
- 502: respuesta vial inválida; 503: indisponibilidad/configuración/límite del motor;
  504: tiempo agotado. Fallos de red o JSON inválido muestran un mensaje seguro.
- Falta de Leaflet o de teselas muestra indisponibilidad del mapa base.

Se aborta la petición y se invalida su versión al cambiar origen/incidencia,
cerrar la sugerencia/detalle, refrescar incidencias, iniciar una acción operativa,
refrescar Mi recolección, abrir el reporte, navegar a otra sección, expirar sesión
o abandonar la página. Se eliminan capas, resultados y la instancia del mapa.
La comparación de versión se repite después de recibir HTTP y después de leer JSON:
incluso respuestas que ignoren AbortController se descartan. Cerrar la sugerencia
permite reabrirla sin conservar origen ni geometría.

No hay polling para detectar revocaciones posteriores a una respuesta ya entregada.
La autorización actual y el descarte de cambios durante la llamada corresponden a
F5.2A. La consulta no reserva recursos ni autoriza acciones operativas posteriores.

## Validación dirigida

Desde `programacion/frontend`:

```console
node --check public/assets/js/ruta-incidencia.js
node --check public/assets/js/recoleccion-incidencias.js
node --test tests/ruta-incidencia.test.cjs tests/recoleccion-incidencias.test.cjs tests/recoleccion-solicitudes.test.cjs
```

Resultado F5.5: 36 tests aprobados (14 de ruta, 22 de incidencias/solicitudes), sin
fallos ni omisiones. Sintaxis JS válida. `git diff --check` sin errores de espacios.
Las pruebas usan DOM/Leaflet y respuestas F5.2A simuladas: comprueban visibilidad,
selección explícita, GET sin campos de destino ni escrituras, GeoJSON, métricas,
advertencias seguras, errores HTTP/JSON, respuestas tardías, limpieza, reapertura
y navegación/integración con las acciones existentes.
No son pruebas de renderizado en un navegador ni validación de OSRM real.

## Procedimiento de regresión visual con respuestas simuladas

Usar una sesión y fixtures autorizados en un entorno descartable. No editar
datos habituales ni apuntar al demo OSRM público para esta comprobación.

1. Abrir Mi recolección → Incidencias asignadas → detalle de una aceptada o en atención APOYO.
   Revisar que la acción no aparezca con un estado no elegible o sin coordenadas.
2. Abrir la sugerencia: confirmar mapa legible en escritorio y móvil, atribución
   y controles; consultar permanece deshabilitado hasta elegir origen. Probar
   clic y selección del centro con teclado.
3. Interceptar únicamente GET `ruta_apoyo.php` con una respuesta simulada válida
   del contrato F5.2A: comprobar geometría con puntos intermedios, destino,
   distancia, duración, ajustes, advertencias y aclaración para vehículos pesados.
4. Simular 401/403/404/409/422/502/503/504 y JSON inválido. Comprobar limpieza,
   mensajes y bloqueo; usar una sesión descartable para el caso 401.
5. Retener una respuesta y cambiar origen/detalle, cerrar/reabrir, refrescar o
   navegar a Solicitudes: la respuesta tardía no debe dibujar nada. Confirmar
   que las acciones operativas conservan su flujo y que el módulo solo envía GET.

## Limitaciones y cierre

Una consulta directa real con PHP 8.1 y TLS validado obtuvo HTTP 200/Ok y una
LineString válida. F5.4 configuró el demo solo en Apache81. El circuito completo
desde una sesión autorizada fue confirmado por el usuario el 11/10/2026, según
la evidencia registrada abajo. Las pruebas automatizadas de F5.5 son simuladas
y no consultan el demo. Las teselas y recursos CDN dependen de red.
El perfil driving, el dataset y las estimaciones no certifican acceso para camiones.
No se sugiere orden de visitas ni se crean recorridos APOYO.

GO para cierre funcional académico F5: pruebas dirigidas aprobadas y validación
visual real confirmada. El demo OSRM admite demostración no comercial y uso
limitado (máximo una petición por segundo); no garantiza disponibilidad, latencia
ni actualización. No debe tratarse como backend operativo ilimitado. Política:
https://github.com/Project-OSRM/osrm-backend/wiki/Demo-server
No se incorpora GPS, navegación paso a paso ni optimización de múltiples visitas.

## Procedimiento para reproducir una prueba manual real F5.5

1. En Apache81 (127.0.0.1:8081), recargar Mi recolección con caché renovada e
   iniciar sesión como operario habilitado de una cuadrilla APOYO vigente.
2. Abrir el detalle de una incidencia ya En atención / En Proceso, con V19 y
   vehículo admisibles y coordenadas autorizadas. No iniciar ni finalizar atención
   para esta prueba: utilizar un trabajo ya en ese estado.
3. Pulsar Ver ruta sugerida, seleccionar manualmente el origen y pulsar Consultar
   ruta sugerida una sola vez. No usar datos personales adicionales ni repetir
   solicitudes al demo. No automatizar consultas reales.
4. Comprobar en Network HTTP 200 y datos consultivos OSRM; revisar línea GeoJSON,
   origen/destino, métricas, ajustes, advertencias y texto para vehículos pesados.
   Verificar que el estado y el botón Finalizar atención no cambian por consultar.
5. Cerrar la sugerencia/detalle y comprobar que desaparece la ruta. Registrar el
   resultado visual; ante error registrar HTTP/code sin reintentos.

## Evidencia manual confirmada — 11/10/2026

El usuario realizó y confirmó las siguientes comprobaciones con la incidencia
académica **INC-2026-BDA54** en Apache81, PHP 8.1.25, desde una sesión autorizada:

1. Ruta vial real dibujada en Leaflet mediante respuesta exitosa OSRM.
2. Marcadores de origen y destino visibles.
3. Distancia y duración estimadas visibles.
4. Limpieza de la geometría al cerrar el panel de ruta.
5. Conservación del estado operativo durante la consulta.
6. Finalización normal de la atención y resolución de la incidencia académica.

La conexión externa utilizó HTTPS con TLS y verificación de hostname activos.
La URL del demo se configuró exclusivamente en Apache81; no en el .env compartido.
No se atribuyen valores numéricos de distancia/duración ni métricas adicionales
a esta prueba. La finalización ocurrió mediante el flujo operativo normal,
después de la consulta informativa; la consulta de ruta no escribió estados.

Esta evidencia real de extremo a extremo se distingue de los **36 tests frontend
simulados**, de los **28 tests backend / 449 aserciones** de la auditoría final
y de las pruebas MariaDB descartables anteriores documentadas en F5.2B. No se
presentan las simulaciones como pruebas reales ni se modifica el informe histórico.
Sintaxis PHP/JavaScript válida; demo Dijkstra independiente A → B → D → E, costo 6.
