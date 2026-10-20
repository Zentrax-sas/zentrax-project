# Integración local y operativa — 20 de octubre de 2026

Base del merge: `92230f4` + `059306f` (`wip-local-pre-facu`). No se ejecutaron
migraciones contra `gestion_residuosfinal` ni se modificaron las aplicaciones Ionic.

## Contratos preservados

- Cancelación pública: tracking INC/RET y clave Bearer del trámite; el tracking
  o un permiso interno por sí solos no autorizan. Solo pendiente sin asignación;
  revisión vigente, transacción y bloqueo de fila. Persiste origen CIUDADANA,
  fecha real y revisión, sin inventar usuario ni motivo interno.
- Cancelación operativa: mantiene endpoint, sesión, permiso vigente de Operaciones,
  usuario y motivo obligatorio de V21. Persiste origen OPERATIVA y actualiza revisión.
- Edición pública de solicitudes: bloquea cuadrillas en el mismo orden que V21,
  luego solicitud e intentos. No admite asignación ni intento abierto o iniciado,
  aunque este último haya cerrado. Un cambio de residuo invalida la confirmación;
  una corrección de descripción no la invalida. Una selección pública explícita
  del catálogo al crear un retiro conserva la clasificación pública válida de V21.
- Se conservan seguimiento corto y detallado, alta pública con tokens de evidencia
  V20, reporte de cuadrilla, consentimiento/servicio WhatsApp, geocodificación,
  retiro con punto y foto, novedades, autorización vigente y módulos operativos.
- CORS mantiene orígenes explícitos con credenciales para móvil; no se amplía el
  acceso administrativo ni se elimina CSRF. Los rechazos de origen no se cachean.

## Plan pendiente para la base real

Aplicar con respaldo vigente y escritores detenidos. DDL MariaDB hace commit
implícito: restaurar un respaldo es la recuperación, no un ROLLBACK de la sesión.
Primero repetir el prechequeo de esquema y datos para detectar cambios posteriores
al respaldo. No ejecutar `schema.sql` ni `init.sql` sobre la base real.

Orden de `base-datos/database/sql/`:

1. `migration_v15_incidencia_fecha_resolucion.sql`
2. `migration_v16_incidencia_ubicacion.sql`
3. `migration_v17_recoleccion_operativa.sql`
4. `migration_v18_atencion_incidencia.sql`
5. `migration_v19_asignacion_vehiculo.sql`
6. `migration_v20_incidencia_upload_token.sql`
7. `migration_v21_solicitud_operativa.sql` **adaptada de esta integración**
8. `migration_v22_compatibilidad_movil.sql`

V21 admite la fecha local existente y verifica su tipo nullable DATETIME; nunca
crea una segunda fecha. V22 conserva DATETIME(6), incorpora origen, CHECK y triggers,
y consolida las extensiones locales. Aplicar V21 y V22 en la misma ventana sin
escritores. Los históricos conservan origen NULL y sus datos originales; los
triggers impiden fabricar nuevas cancelaciones sin origen. No hay backfill de
autores ni de intentos. Las migraciones no son un comando de reejecución ciega.

La antigua V15 móvil se conserva como
`database/migrations/legacy_v15_solicitud_retiro_movil.sql`. Está sustituida por V22.
No repetir las SQL locales fechadas de consentimiento, retiro, cancelación y edición,
ni sus aplicadores PHP históricos: sus columnas ya existen o están consolidadas.
V22 también preserva la dirección TEXT del caché de geocodificación.

## Evidencia de ensayo

- MariaDB 10.4.32, puerto 3310, bases exclusivas
  `zemyna_integracion_test_20261020_final` y la variante `_clean`.
- Restauración del dump respaldado, V15–V22 y creación limpia: correctas.
- Huellas de cada fila y columna original conservadas; permisos previos conservados.
  Solo se agregan concesiones previstas: permiso 47→54 y rol_permiso 112→128.
- Conteos: contenedor 11215, incidencia 32, solicitud 16, usuario 5, foto 15,
  sin pérdidas ni cambios de contenido en esas tablas.
- Validación final PHP: 235 archivos sin errores de sintaxis.
- Suite completa: 1471 casos, 6275 aserciones, cero errores/fallos; 272 casos
  optativos omitidos por necesitar servidores descartables/configuración específica.
  Los avisos de propiedades dinámicas en dobles se corrigieron y verificaron aparte.
- Pruebas finales enfocadas: 24 casos, 117 aserciones; incluyen 8 casos V22 reales
  y bloqueo entre dos conexiones. Integridad del ensayo verificada después.
- HTTP en servidor local dirigido al ensayo: session/recoleccion/solicitudes
  protegidas 401; cancelación/edición sin clave 403; tracking inválido 400;
  preflight Capacitor 204 con origen explícito.
- Evidencia temporal privada e ignorada en Git: `backend/tmp/integration/`.

Ensayo reproducible (el nombre nuevo debe comenzar con `zemyna_integracion_test_`):

```powershell
C:/xampp/php/php.exe database/scripts/ensayar_integracion.php <dump.sql> <base_de_ensayo_nueva>
```

El importador rechaza instrucciones del dump que seleccionen otra base y conserva
las bases de ensayo. Con `--verificar` comprueba la integridad posterior a las pruebas
contra su informe. No está diseñado para aplicar migraciones a la base real.

Pendiente: autorizar y ejecutar el plan real después de revisar el esquema vigente,
y validar login y jornadas de Chofer contra la base actualizada. No se probó Docker
ni MySQL 8 en este ensayo; los casos omitidos no se presentan como aprobados.
