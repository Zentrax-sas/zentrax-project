# PROMPT PARA IA DE VSCODE — Importar contenedores reales de la IdM + optimizar Leaflet

Pegar dentro del proyecto abierto en VSCode. Hacer TAREA A primero, mostrarme el resultado, y recién después TAREA B.

---

## CONTEXTO

Tengo el dataset real y público de la Intendencia de Montevideo `Contenedores_domiciliarios.csv` (11.211 filas), con columnas: `GID` (id único), `COD_CIRCUITO` (código de circuito de recolección, 131 valores distintos, formato `A_DU_RM_CL_109`), `TURNO_HORARIO` (texto de horario/frecuencia), `X`/`Y` (coordenadas **UTM zona 21S / EPSG:32721**, no lat/lon), `MOTIVO` (vacío en la mayoría, o `"Mantenimiento"` / `"Sin instalar"` en algunos).

No coincide 1 a 1 con la tabla `CONTENEDOR` del DER (`id_contenedor`, `codigo`, `capacidad`, `direccion`, `latitud`, `longitud`, `estado`, `id_tipo_residuo`, `id_ruta`). Hay que transformar y completar con criterios definidos abajo — **no inventes otros criterios, preguntame si algo no está cubierto**.

---

## TAREA A: Script de importación (una sola vez, no forma parte de la app en runtime)

Crear `backend/scripts/importar_contenedores_idm.php`, un script de **línea de comandos** (no un endpoint HTTP, no lo expongas en `api/`) que:

### 1. Lea el CSV
Recibe la ruta al CSV como argumento (`php importar_contenedores_idm.php /ruta/Contenedores_domiciliarios.csv`). Delimitador `;`, con encabezado. Manejar el encoding del archivo (probar `latin-1`/`ISO-8859-1`, convertir a UTF-8 con `mb_convert_encoding` si hace falta para que `MOTIVO`/`TURNO_HORARIO` no salgan con caracteres corruptos). Recortar espacios (`trim()`) de todos los campos de texto, que vienen con padding fijo.

### 2. Convierta coordenadas UTM (EPSG:32721) → lat/lon (WGS84)
Implementar la conversión con la fórmula estándar UTM→lat/lon en PHP puro (sin librerías externas, sin depender de Python/pyproj). Parámetros: zona 21, hemisferio Sur, elipsoide WGS84 (a = 6378137, f = 1/298.257223563).

Validar el resultado contra estos dos casos de referencia (ya verificados):
```
X=569167.409189918, Y=6144930.92890772  ->  lat ≈ -34.8355663, lon ≈ -56.2435326
X=566459.492523661, Y=6143405.71838873  ->  lat ≈ -34.8494996, lon ≈ -56.2730254
```
Si tu implementación no da estos valores (con margen de ±0.0001), revisá la fórmula antes de seguir — no sigas con datos mal convertidos.

### 3. Genere las `RUTA` a partir de `COD_CIRCUITO`
- Un registro de `RUTA` por cada valor distinto de `COD_CIRCUITO` (131 en total).
- `nombre` = el propio `COD_CIRCUITO` (trimeado, cabe en `VARCHAR(50)`).
- `zona` = el prefijo de letras antes del primer `_` (ej. de `A_DU_RM_CL_109` → `"Zona A"`; de `CH_DU_RM_CL_113` → `"Zona CH"`). Los prefijos posibles son: `A, B, C, CH, D, E, F, G`.
- Insertar estas 131 rutas ANTES que los contenedores (por la FK), y armar un mapa en memoria `COD_CIRCUITO -> id_ruta` según el `id_ruta` autogenerado (o hacé un `SELECT` después de insertar para recuperar los IDs reales).

### 4. Mapee cada fila del CSV a un `CONTENEDOR`
- `codigo` = `'IDM-' . GID` (único, cabe en `VARCHAR(20)`).
- `capacidad` = valor fijo `1100` (litros, estándar de contenedor domiciliario en Montevideo — no viene en el CSV, es un supuesto documentado, dejalo como constante fácil de cambiar al principio del script).
- `direccion` = texto genérico: `"Ubicación importada IdM (sin dirección catastral) — X: {X}, Y: {Y}"` (placeholder, ya definido así a propósito, no hagas geocodificación inversa).
- `latitud`, `longitud` = resultado de la conversión del paso 2.
- `estado`: mapear desde `MOTIVO` (ya trimeado):
  - Si `MOTIVO` está vacío → `'Disponible'`.
  - Si `MOTIVO` = `'Mantenimiento'` → `'Fuera de Servicio'`.
  - Si `MOTIVO` = `'Sin instalar'` → `'Fuera de Servicio'`.
  - Cualquier otro valor no previsto → `'Disponible'` por defecto, pero logueá un `echo` avisando qué valor nuevo apareció, para que lo revisemos.
- `id_tipo_residuo` = el `id_tipo_residuo` de la fila `TIPO_RESIDUO` cuyo `nombre = 'Orgánico'` (buscalo con un `SELECT` al principio del script, no lo hardcodees, puede variar entre entornos).
- `id_ruta` = el `id_ruta` correspondiente a su `COD_CIRCUITO`, del mapa armado en el paso 3.
- **No hay `TURNO_HORARIO` en la tabla `CONTENEDOR` ni en `RUTA` en el DER actual** — no lo persistas en esta tarea, descartalo (si en el futuro se quiere guardar el horario de recolección por ruta, sería un cambio de modelo aparte, no lo hagas ahora).

### 5. Genere el `INSERT` en lotes (no fila por fila)
- Insertar en bloques de 500 filas por sentencia `INSERT INTO contenedor (...) VALUES (...), (...), ...;` dentro de una transacción (`START TRANSACTION` / `COMMIT` cada, por ejemplo, 5000 filas, para no mantener una transacción gigante de 11.211 filas abierta de punto a punto).
- El script debe poder ejecutarse directo contra la base (usando `config/database.php`, mismo patrón de conexión que el resto del proyecto) O, alternativamente, generar un archivo `.sql` de salida (`base-datos/database/sql/seed_contenedores_idm.sql`) para poder revisarlo antes de correrlo — dejá ambas opciones controladas por un flag de línea de comandos (`--ejecutar` vs `--generar-sql`), default a generar el `.sql` para que yo lo revise antes de aplicarlo.
- Mostrar progreso cada 1000 filas procesadas (`echo` con contador), y al final un resumen: cuántos contenedores, cuántas rutas, cuántos con `estado != 'Disponible'`.

### 6. Documentación
Agregar un comentario al principio de `seed_contenedores_idm.sql` (o del script) explicando: fuente del dataset (Intendencia de Montevideo, contenedores domiciliarios), fecha de importación, y los supuestos documentados arriba (capacidad fija 1100L, tipo de residuo Orgánico para todos, dirección placeholder, mapeo de `MOTIVO`→`estado`).

---

## TAREA B: Que Leaflet no se rompa con 11.211 marcadores

Hoy `frontend/public/mapa.js` (función `renderContenedores`) agrega cada `L.circleMarker` directo a `map` con `.addTo(map)`, uno por uno, sin clustering. Con 11 mil filas esto va a colgar el navegador. Vamos a combinar dos técnicas: **clustering** + **carga perezosa por viewport** (bounding box), para no traer nunca los 11 mil de una.

### 1. Agregar el plugin `Leaflet.markercluster`
En `frontend/public/index.html`, agregar (junto a los `<link>`/`<script>` de Leaflet ya existentes):
```html
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
```
(ubicarlos después de los de Leaflet base, antes de `mapa.js`).

### 2. `frontend/public/mapa.js` — usar un `markerClusterGroup` en vez de `addTo(map)` directo
- Crear una sola instancia `const clusterGroup = L.markerClusterGroup({ chunkedLoading: true, disableClusteringAtZoom: 18 });` y agregarla al mapa una vez (`clusterGroup.addTo(map)`).
- En `renderContenedores`, en vez de `marcador.addTo(map)` para cada uno, usar `clusterGroup.addLayer(marcador)`.
- Cuando se recarguen los contenedores (ver punto 3), limpiar con `clusterGroup.clearLayers()` antes de volver a agregar, en vez de recrear el mapa entero.

### 3. Carga por viewport (bounding box) en vez de traer todo de una
- **Backend**: en `backend/controllers/ContenedorController.php` → `getAll($filters)`, agregar soporte opcional de `bbox` en `$filters` (`min_lat`, `min_lon`, `max_lat`, `max_lon`). Si vienen los 4, agregar al `WHERE` de la query: `latitud BETWEEN :min_lat AND :max_lat AND longitud BETWEEN :min_lon AND :max_lon`. Si no vienen, mantener el comportamiento actual (paginado con `page`/`limit`, sin filtrar por zona) para no romper otros usos del mismo endpoint (panel admin, etc.).
- En `backend/api/contenedores.php`, sumar `min_lat`, `min_lon`, `max_lat`, `max_lon` a `$filters` desde `$_GET`, igual estilo que `id`/`page`/`limit` ya existentes.
- **Base de datos**: agregar un índice compuesto para que el filtro por rango sea rápido:
  ```sql
  CREATE INDEX idx_contenedor_geo ON contenedor (latitud, longitud);
  ```
  Agregarlo a `schema.sql` y a un `migration_index_geo.sql` aparte para entornos ya creados.
- **Frontend (`mapa.js`)**: en `cargarContenedoresMapa()`, en vez de pedir todo el listado una sola vez:
  - Calcular el bounding box actual con `map.getBounds()` (`getSouthWest()`/`getNorthEast()`).
  - Pedir `GET /api/contenedores.php?min_lat=...&min_lon=...&max_lat=...&max_lon=...&limit=2000` con esos valores.
  - Volver a pedir (con `clusterGroup.clearLayers()` + recarga) cada vez que el mapa dispare `moveend` o `zoomend`, pero con un **debounce de ~400ms** para no disparar un fetch por cada pequeño movimiento mientras el usuario arrastra o hace zoom.
  - Si el zoom actual es muy bajo (ej. `map.getZoom() < 12`, todo Montevideo a la vista), mostrar un mensaje tipo "Acercate en el mapa para ver los contenedores" en vez de pedir miles de puntos de una zona enorme — definí el umbral de zoom que te parezca razonable según cómo se vea en la práctica.

### 4. Mantener el fallback local (`contenedoresIM`) para cuando la API no responda, sin romper el nuevo flujo de clustering/bbox — ajustalo para que también pase por el `clusterGroup` en vez de `addTo(map)` directo.

---

## REGLAS GENERALES

- No mezcles los 11.211 contenedores reales con los datos de demo de `init.sql` (los 2-3 de ejemplo) — van en un archivo de seed aparte (`seed_contenedores_idm.sql`), documentado como opcional/pesado, no se ejecuta en una instalación limpia por defecto.
- El script de importación (Tarea A) no debe modificar ningún endpoint ni controller existente — es standalone.
- Mostrame el resultado de cada tarea (contenido de los archivos, y el resumen de conteos al final de la importación) antes de pasar a la siguiente.
- Si algo del CSV no encaja con los criterios de arriba (por ejemplo aparece un valor de `MOTIVO` que no sea vacío/Mantenimiento/Sin instalar), avisame en vez de decidir vos qué hacer con él.
