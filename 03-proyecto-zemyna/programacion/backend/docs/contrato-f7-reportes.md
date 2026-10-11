# F7.1 — informes operativos

API independiente `GET api/reportes_operativos.php`. No modifica Dashboard,
informe de incidencias, F4/F6, schema, migraciones ni frontend. Solo agregados;
no es una segunda bandeja, no devuelve filas de personas ni exportaciones.

## HTTP y autorización

Parámetros únicos: `informe=solicitudes|atencion|recorridos|todos` (predeterminado
`todos`), `desde`, `hasta`. Fechas opcionales AAAA-MM-DD; vacío equivale a ausencia.
Rango válido 1000-01-01 a 9999-12-30; desde <= hasta. Arrays, fechas inexistentes,
identidad, sector, paginación, parámetros desconocidos y timestamps de caché: 400.

Usuario activo de sesión; permisos efectivos leídos de usuario_rol/rol_permiso/
permiso, sector OPERACIONES, fecha_desde <= hoy y fecha_hasta NULL o >= hoy.
No consulta nombres de roles, no utiliza el bypass TI del helper legacy.

| Informe | Permisos obligatorios |
| --- | --- |
| solicitudes | solicitud.consultar |
| atencion | incidencia.consultar + solicitud.consultar |
| recorridos | recorrido.consultar + contenedor.consultar |
| todos | Los cuatro permisos anteriores |

La consulta combinada se deniega completa si falta una fuente. No hay nuevos
permisos ni concesiones. Autorización y agregados comparten una transacción
MariaDB REPEATABLE READ, READ ONLY. No bloquea escritores con FOR UPDATE.
Una revocación concurrente puede ocurrir después de la instantánea; la siguiente
petición la revalida. No se mantienen autorizaciones cacheadas de sesión.

Respuesta: `success`, `data`, `message`, `errors`, `statusCode`. `data` incluye
`informe`, `zona_horaria`, `periodo`, `generado_en` y solo los bloques solicitados.
Cache-Control: no-store; GET únicamente, Allow: GET ante 405. Al ser solo lectura
no requiere CSRF. Sin CORS abierto, sin autores/identidades/contacto/descripciones,
direcciones, motivos libres, referencias de seguimiento ni matrícula.

401 sesión/cuenta inválida; 403 permisos; 400 contrato; 405 método; 500 consulta
fallida; 503 conexión ausente/perdida. Errores sin SQL ni mensajes de PDO.
OPTIONS se rechaza 405 antes del preflight genérico de bootstrap, sin abrir CORS;
incluye no-store y Allow: GET incluso sin sesión. No modifica el bootstrap compartido.

## Semántica temporal

America/Montevideo. Los DATETIME del dominio son horarios locales, no se convierten
usando la zona horaria del servidor SQL. Hitos con columna >= desde 00:00:00 y
columna < día siguiente a hasta 00:00:00. Límite ausente queda abierto; sin fechas
se incluyen todos los hitos conocidos. NULL nunca se sustituye por otra fecha.
`generado_en` es hora del servidor de aplicación, no una fecha de actividad.

Los estados actuales y diagnósticos actuales NO se filtran por el período.
No representan el estado que tenía cada trabajo durante un período histórico.
La respuesta tiene tamaño acotado por ENUMs y un número fijo de métricas; no hay
listados ni parámetros page/limit. Los agregados se calculan en SQL sobre todos
los registros correspondientes; no se derivan de una página de resultados.

## Solicitudes

- `estados_actuales`: COUNT(*) por estado de solicitud; cero para estados vacíos.
- `periodo.creadas`: COUNT(*) por solicitud.fecha dentro del período.
- `periodo.finalizadas`: estado Finalizada y fecha_finalizacion dentro del período.
- `pendientes_clasificacion_historica_actual`: solicitudes Pendiente/Programada
  sin fecha_confirmacion_residuo. Es un diagnóstico de confirmación ausente,
  no prueba de antigüedad ni fecha de migración; no incluye terminales bloqueadas.
- `finalizadas_sin_fecha_actual`: Finalizada con fecha_finalizacion NULL;
  se excluyen del conteo por hito incluso sin filtro porque la fecha no es conocida.

Se consulta solicitud directamente. Dos intentos no convierten una solicitud en
dos trabajos. No se unen intentos para calcular estos conteos.

## Atención operativa

Bloques independientes `incidencias` (atencion_incidencia) y `solicitudes`
(atencion_solicitud), con las mismas métricas:

- `estados_actuales`: conteo por estado de todos los intentos reales, incluidos
  cerrados históricos. No es un conteo de trabajos ni se suma con solicitudes.
- `hitos_periodo.aceptados`: fecha_aceptacion dentro del período, cualquiera sea
  el estado actual del intento. `iniciados`: mismo criterio con fecha_inicio.
- `finalizados`, `rechazados`, `interrumpidos`: estado correspondiente y
  fecha_cierre dentro del período. Una interrupción no es una finalización.
- `duracion_atencion.finalizados` e `interrumpidos`: separado por resultado;
  promedio_segundos = AVG(TIMESTAMPDIFF(SECOND,fecha_inicio,fecha_cierre)), solo
  con ambas fechas presentes, cierre >= inicio y cierre dentro del período.
  `muestras` = COUNT(*) de esas mismas filas. Promedio con dos decimales; NULL
  con cero muestras. No mezcla tiempo de espera/aceptación con atención.

Un intento puede aportar aceptación e inicio en fechas diferentes, o aceptación
en otro período y cierre en el actual. No sumar los hitos como trabajos únicos.
No fabrica filas de atención para históricos sin intento. No atribuye vehículos
a incidencias: atencion_incidencia no conserva V19. Tampoco expone vehículos en
este informe agregado de solicitudes, aunque su intento sí conserva V19.

## Recorridos

- `iniciados`: fecha_inicio en período e id_usuario_inicio presente. Sin autor
  no se distingue inicio real de fecha prevista; no se infiere el hito.
- `finalizados`: estado Finalizado y fecha_fin en período.
- `atenciones_contenedores`: COUNT(*) de atencion_contenedor.fecha_atencion en
  período. UNIQUE(recorrido,contenedor) evita duplicados dentro de una ejecución;
  el mismo contenedor en dos ejecuciones representa dos atenciones reales.
- Diagnósticos actuales `finalizados_sin_fecha_actual` y `sin_autor_inicio_actual`
  (En Proceso/Finalizado sin autor) explican cobertura histórica incompleta.

No calcula esperados, cumplimiento, kilómetros, combustible ni kilos. No utiliza
la composición actual de ruta como planificación histórica. Añade hitos de inicio
verificables y resultados operativos, sin copiar tarjetas del Dashboard.

## Pruebas y límites

ReporteOperativoMariaDbTest exige DSN ZEMYNA_F7_MARIADB_DSN, datadir TEMP
zemyna-f7-* y crea exclusivamente f7_test_<12 hex>. Schema oficial, datos ficticios,
FK/CHECK/UNIQUE reales; elimina su base al finalizar. Router HTTP de tests requiere
el mismo servidor validado y una base f7_test_* explícita. Nunca usa la habitual.

No hay paginación/exportación/frontend en F7.1. Las consultas globales pueden
necesitar índices con mayor volumen; no se agregan sin evidencia de EXPLAIN.
No hay estados pasados reconstruibles ni denominador histórico de cumplimiento.

## Validación ejecutada

MariaDB 10.4.32 descartable y PHP 8.1.25: **8 tests, 129 assertions, PASS**,
sin skips. Incluye conteos/hitos, múltiples intentos, fuentes vacías, diagnósticos
históricos, límites diarios, UTC frente a Montevideo, vigencias/permisos/TI,
privacidad, errores seguros y endpoint HTTP real (200/400/401/403/405, OPTIONS,
JSON, no-store y sin CORS abierto). Regresión dirigida de Dashboard::consultar
e Incidencia::report contra el mismo schema MariaDB oficial.

Se corrigió una fecha del fixture incompatible con CHECK de V21; no se relajaron
restricciones del schema. Cinco archivos PHP con sintaxis válida. Sin suite completa,
sin conexiones de prueba a la habitual, sin cambios a archivos existentes.
