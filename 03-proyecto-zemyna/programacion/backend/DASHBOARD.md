# Resumen operativo

Implementado sobre `06fe21ed7b8e78df1caecc0f4422d0d1e66f4760`. Reemplaza únicamente los indicadores y recorridos ficticios del resumen. Conserva `admin.html#resumen`, la navegación y los módulos existentes. No hay migraciones nuevas; utiliza la estructura operativa v17 ya existente.

## Acceso y sectores

`GET api/dashboard.php` requiere cuenta activa y sesión. Revalida roles y permisos vigentes desde MariaDB en cada solicitud. Exige conjuntamente `incidencia.consultar`, `recorrido.consultar`, `cuadrilla.consultar`, `vehiculo.consultar` y `contenedor.consultar` en OPERACIONES. Conserva la excepción ADMINISTRADOR_TI de los helpers; además comprueba `contenedor.consultar` sin excepción sectorial, como la API administrativa de contenedores. No necesita permisos de escritura para leer el resumen.

La política es acceso completo o 403; no hay respuestas parciales por permisos. El enlace a Cuadrillas solo se ofrece con `cuadrilla.modificar`, porque la vista administrativa de ese módulo también lo exige. El menú del resumen se habilita tras validar la sesión y consultar `view=permisos`; modificar el hash no evita la autorización del backend.

Los sectores del proyecto pertenecen a las autorizaciones de usuarios. Incidencias, recorridos, cuadrillas, vehículos y contenedores no tienen una columna de propiedad por sector ni una relación que permita particionarlos por sector sin inventar una regla. Este dashboard es explícitamente global de Operaciones: no filtra por sector del autor, ni por pertenencia, ni presume que un centro identifica un sector. No presenta un selector ficticio. El parámetro opcional `sector` solo admite `OPERACIONES`; cualquier otro devuelve 403, incluso para un administrador. Un usuario con todos los permisos en otro sector obtiene 403, también si envía `sector=OPERACIONES`.

## Contrato

Respuesta correcta: `success: true`, `statusCode: 200`, `data` con:

- `periodo`: desde/hasta anulables y descripción.
- `sector`: OPERACIONES.
- `actual`: seis indicadores actuales.
- `periodo_resultados`: totales y promedio del período, con cantidad de muestras del promedio.
- `incidencias_por_estado_prioridad`: filas estado/prioridad/cantidad, como máximo nueve combinaciones.
- `contenedores_problematicos`: hasta cinco filas código/dirección pública/cantidad.
- `recorridos`: conteos por estado y campos de avance no calculables en null, con motivo.
- `historicas_sin_fecha_resolucion`: cantidad global de incidencias Resueltas sin fecha conocida, para explicar su exclusión temporal.
- `requiere_atencion`: hasta seis situaciones agregadas, sin identidades ni texto privado.
- `generado_en`: fecha/hora local de America/Montevideo.
- `enlaces`: destinos administrativos existentes; algunos pueden ser null.

`GET ?view=permisos` devuelve únicamente habilitación y enlaces; aplica la misma autorización. No acepta otra vista. Errores: 401 sin sesión/cuenta activa, 403 permiso o sector, 400 parámetros o fechas inválidos, 405 método, 503 error de consulta sin detalles SQL. JSON UTF-8, sin HTML, `Cache-Control: no-store`. No devuelve tracking, nombres de personas, contacto, descripciones de incidencias ni coordenadas propias.

## Fechas

Solo se admiten `fecha_desde`, `fecha_hasta`, `sector`, `view`. No admite identidad, paginación o timestamps de caché. Fechas vacías/ausentes significan límite abierto. Formato estricto AAAA-MM-DD, calendario válido, desde <= hasta. Rango admitido 1000-01-01 a 9999-12-30, para que el día siguiente del límite superior sea representable.

Cada métrica usa `columna >= desde 00:00:00` y `columna < día siguiente a hasta 00:00:00`, con parámetros preparados. Así se incluyen las horas del último día. No se sustituye una fecha desconocida por otra de significado diferente.

## Definiciones de indicadores

| Indicador | Definición | Fecha del filtro |
|---|---|---|
| Incidencias activas | Cantidad en Pendiente o En Proceso, tengan o no contenedor | Ninguna: estado actual |
| Recorridos en proceso | Cantidad de ejecuciones en En Proceso | Ninguna |
| Cuadrillas sin recorrido | Cuadrillas sin relación usa/participa con ejecución Pendiente o En Proceso; mismo criterio de existencia de proximo() | Ninguna |
| Camiones disponibles | Vehículos activos con estado Disponible, sin participación en ninguna ejecución Pendiente o En Proceso | Ninguna |
| Camiones ocupados o no disponibles | Vehículos activos en otro estado o con participación operativa. Complemento del anterior entre vehículos activos | Ninguna |
| Contenedores con prioridad alta | Contenedores distintos no nulos de incidencias activas Alta, independientemente de baja lógica del contenedor | Ninguna |
| Incidencias reportadas | Todas las incidencias, incluidas las que no tienen contenedor | fecha_reporte |
| Incidencias resueltas | Estado Resuelta. Sin filtros incluye históricos sin fecha; con algún límite requiere fecha de resolución que lo satisfaga | fecha_resolucion |
| Resolución promedio, horas | Promedio de segundos entre reporte y resolución / 3600; solo Resueltas con fechas conocidas y resolución >= reporte. Dos decimales; null sin muestras | fecha_resolucion |
| Recorridos finalizados | Ejecuciones Finalizado. Sin filtros incluye todos; con fechas no utiliza inicio como reemplazo del fin | fecha_fin |
| Atenciones de contenedores | Una por pareja recorrido/contenedor atendida. El mismo contenedor en dos ejecuciones representa dos atenciones legítimas, no un duplicado | atencion_contenedor.fecha_atencion |
| Tabla de recorridos por estado | Cantidad de cada estado, incluido Cancelado, según la cohorte de inicio. Pendientes tienen inicio previsto; los demás conservan la fecha registrada | fecha_inicio |
| Gráfico por estado/prioridad | Estado/prioridad actuales de las incidencias reportadas en el período; no reconstruye su estado pasado | fecha_reporte |
| Top de contenedores | Cantidad de reportes con contenedor. Cantidad descendente, código ascendente e ID ascendente para empate estable; LIMIT 5 | fecha_reporte |

Los totales se calculan en SQL sobre todos los registros correspondientes, nunca desde una página visible. Los valores 0 son conteos reales. null significa que no existe información suficiente.

### Avance y limitaciones históricas

No existe una instantánea de contenedores esperados por ejecución. La ruta y sus contenedores activos pueden cambiar; RecoleccionOperativa muestra la ruta actual más atenciones ya registradas, no una planificación histórica inmutable. Por eso esperados, pendientes y porcentaje histórico se devuelven en null y se muestran como Sin datos. Se aplica igualmente a recorridos sin contenedores y finalizados con posibles pendientes; no se interpreta un cierre como 100% ni se divide por cero. Sí se cuentan las atenciones registradas y los recorridos reales.

La disponibilidad de recorrido por cuadrilla no significa que esté libre de conflictos: los vehículos inválidos y recorridos compartidos se informan por separado. No se inventa vigencia para usa o participa.

## Requiere atención

Sin filtro temporal; no persiste avisos. Situaciones con cantidad mayor a cero:

- Crítico: incidencias activas Alta.
- Advertencia: contenedores con al menos tres incidencias activas. Umbral explícito de revisión, no una regla de bloqueo. La consulta previa mostró un contenedor con tres y otros con uno.
- Informativo: cuadrillas sin ejecución operativa relacionada.
- Crítico: recorridos Pendiente/En Proceso sin ningún vehículo activo y fuera de mantenimiento relacionado.
- Advertencia: vehículos distintos dados de baja o En Mantenimiento relacionados con ejecuciones operativas. En Servicio no se considera inválido para su propia operación.
- Crítico: recorridos operativos relacionados con más de una cuadrilla distinta; se deduplican vehículos/participaciones de una misma cuadrilla.

No sumar estas cantidades entre sí: pueden describir la misma situación desde distintos aspectos. Cada una tiene su nivel textual y enlace autorizado. Sin situaciones se muestra el mensaje solicitado.

## Rendimiento y presentación

Cantidad fija de consultas agregadas, sin N+1 ni descarga de listados completos. Una transacción de lectura mantiene coherencia entre agregados de la respuesta bajo el aislamiento predeterminado de MariaDB/InnoDB. EXISTS y COUNT(DISTINCT) evitan multiplicar resultados por participa/usa. Los valores de filtros se parametrizan; las columnas y condiciones estructurales son constantes internas.

Se revisaron EXPLAIN de ranking, cuadrillas sin recorrido y recorridos compartidos. En la base local, el ranking usa idx_incidencia_contenedor (estimación 13 filas) y PRIMARY de contenedor. Las tablas operativas pequeñas usan exploraciones de dos filas y referencias PRIMARY; ordenamiento/agrupación usa tablas temporales según el plan. No se demostraron necesidades de índices nuevos.

Sin librería de gráficos ni nuevas CDN. Dos visualizaciones HTML/CSS con etiquetas y tablas equivalentes. Seis tarjetas actuales; cuadrícula adaptable, controles táctiles, foco visible y estados aria-live. Los datos se incorporan mediante textContent; los destinos se restringen a hashes internos conocidos. Actualizar conserva filtros aplicados, deduplica solicitudes idénticas y cancela/descarta respuestas anteriores. Un error transitorio conserva el último resultado identificado; 401/403 oculta datos y menú.

## Verificación

DashboardTest usa una base SQLite aislada para clasificación, límites, datos vacíos, históricos, privacidad, permisos vigentes y sectores; producción usa TIMESTAMPDIFF de MariaDB para la duración. DashboardHttpIntegrationTest usa Apache/MariaDB con el entorno opcional ZEMYNA_UTF8_HTTP_BASE y las credenciales de pruebas existentes, nunca embebidas aquí. Crea registros identificados DB..., compara todos los agregados con SQL directo, resuelve una incidencia temporal por HTTP y ejecuta tres EXPLAIN. Elimina los datos por IDs exactos en finally y compara huellas de todas las filas de las tablas involucradas antes/después. No usa el recorrido real de Diego para mutaciones.

La suite JavaScript existente incorpora el dashboard y mantiene las pruebas de mapas, incidencias, informe, Usuarios, Cuadrillas y Mi recolección. Las pruebas DOM no certifican apariencia: la revisión visual en escritorio y móvil queda pendiente si no hay navegador disponible.
