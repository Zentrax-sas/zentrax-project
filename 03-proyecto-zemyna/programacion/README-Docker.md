# Instalación limpia de Programación

El Compose oficial de esta asignatura es **`03-proyecto-zemyna/programacion/compose.yaml`**,
junto con el `Dockerfile` de este directorio. El `docker-compose.yml` de la raíz del
repositorio no es el procedimiento de instalación de Programación. Especificar
siempre `-f compose.yaml` desde este directorio; no ejecutar un Compose descubierto
automáticamente desde la raíz.

La instalación corregida fue validada por el usuario en Ubuntu/WSL2 con el proyecto
`zemyna_demo_fix_20261019`. La evidencia y los puertos utilizados se detallan más
abajo. El ajuste posterior del healthcheck aún no se aplicó a los contenedores.
No ejecutar instalaciones ni recreaciones sin autorización para el entorno.

## Preparación aislada

1. Elegir un nombre de proyecto Docker nuevo y exclusivo para la prueba. Un nombre
   fijo reutilizado no garantiza aislamiento. No utilizar volúmenes ni directorios
   de datos de XAMPP o de otro proyecto. Los volúmenes declarados aquí no tienen
   nombres externos: Compose los separa por nombre de proyecto.
2. Preparar `.env.docker` a partir de `.env.docker.example`, con contraseñas nuevas
   y exclusivas. No modificar el `.env` compartido de XAMPP. La aplicación recibe
   `DB_HOST=db` y `DB_PASS` desde `DB_PASSWORD`; MariaDB recibe las mismas variables
   del proyecto aislado. No incluir secretos en Git ni en informes.
3. Comprobar que los puertos elegidos estén libres. Los valores de ejemplo son
   8080 para la aplicación y 8081 para phpMyAdmin. **8081 puede estar ocupado por
   Apache81.** Ante conflicto, detenerse y acordar la configuración antes de
   iniciar; no detener Apache ni cambiar puertos automáticamente. La BD no publica
   3306 en el host. Los puertos web actuales no están limitados a loopback.
4. Una vez autorizado, validar y construir/iniciar exclusivamente ese proyecto,
   usando el mismo nombre exclusivo en todos los comandos. Forma de referencia
   (sustituir `NOMBRE_NUEVO` por el identificador elegido):

   ```text
   docker compose --project-name NOMBRE_NUEVO --env-file .env.docker -f compose.yaml config --quiet
   docker compose --project-name NOMBRE_NUEVO --env-file .env.docker -f compose.yaml up -d --build
   ```

   No ejecutar `down -v`, borrar volúmenes ni importar SQL para forzar una
   reinstalación. Si un proyecto existe o la inicialización falla, conservarlo y
   revisar el estado antes de autorizar cualquier limpieza.

## Flujo de inicialización

En un directorio de datos vacío, el servicio `db` crea la base y el usuario
indicados en `.env.docker`, y procesa los montajes en este orden:

1. `01-schema.sql`: `base-datos/database/sql/schema.sql`, con la estructura vigente.
2. `02-init.sql`: `base-datos/database/sql/init.sql`, con los datos iniciales y el
   catálogo de permisos, incluido V19.

`schema.sql` es destructivo si se ejecuta sobre una base con datos. **No importar
estos archivos en la BD habitual.** `init.sql` completo tampoco es un actualizador
idempotente. Reiniciar un contenedor con un volumen ya inicializado no vuelve a
aplicar estos scripts ni instala permisos nuevos.

La aplicación espera que `db` esté saludable. Acceso previsto una vez iniciado:
`http://127.0.0.1:PUERTO_APP/frontend/public/login.html`. `config.js` resuelve el
backend por rutas relativas; no requiere el prefijo local de XAMPP.

La primera inicialización puede tardar varios minutos: en la prueba observada
MariaDB necesitó aproximadamente tres minutos. El healthcheck de `db` conserva
`healthcheck.sh --connect --innodb_initialized`, intervalo de 10 segundos, timeout
de 5 segundos y 12 fallos consecutivos, con `start_period: 4m` para tolerar ese
arranque. El primer éxito puede declarar healthy antes de cuatro minutos; no es
una espera obligatoria. Después de un éxito, los fallos consecutivos cuentan
normalmente. Si nunca tiene éxito, la gracia solo retrasa unhealthy, no lo evita.
También se aplica en reinicios; un proceso que termina sigue figurando como salido.

**Healthy no garantiza por sí solo una instalación completa.** Confirmar que
schema.sql e init.sql finalizaron y comprobar los datos y las funciones esperadas.
Un unhealthy transitorio no justifica borrar volúmenes ni reimportar el esquema.

Para diagnosticar el proyecto de prueba existente, sin recrearlo ni mostrar la
configuración completa con secretos, usar desde Ubuntu/WSL2:

```bash
docker ps -a --filter label=com.docker.compose.project=zemyna_demo_fix_20261019
docker logs --tail 100 zemyna_demo_fix_20261019-db-1
docker logs --tail 100 zemyna_demo_fix_20261019-app-1
docker inspect --format '{{json .State.Health}}' zemyna_demo_fix_20261019-db-1
```

Los nombres corresponden a esa prueba; verificar primero los nombres listados.
Los logs y la salida del healthcheck se revisan localmente y se redactan antes de
compartirlos. Revisar errores SQL y la finalización de ambos scripts, no solo el
estado healthy. El ajuste en el archivo no cambia contenedores ya creados.

## V19 y permisos

La instalación limpia crea, sin duplicarlos por nombre:

- `asignacion_vehiculo.consultar`.
- `asignacion_vehiculo.modificar`.

Las relaciones `rol_permiso` se agregan solo a `RESPONSABLE_SECTORIAL` y
`ADMINISTRATIVO_OPERATIVO`, exactamente como en
`migration_v19_asignacion_vehiculo.sql`. La PK de la pareja y `INSERT IGNORE`
evitan duplicaciones. No se otorgan estos permisos a TI, OPERARIO o INSPECTOR.
El acceso sigue exigiendo cuenta activa y autorizaciones personales vigentes en
OPERACIONES; el nombre del rol no constituye un bypass ni crea una autorización.

La migración histórica permanece intacta para instalaciones existentes. Este
cambio no las actualiza automáticamente. Si falta V19 en una instalación anterior,
debe revisarse y autorizarse su migración correspondiente; nunca ejecutar el
esquema de instalación limpia sobre ella. Solo el bloque de permisos V19 tiene
comportamiento repetible, no todo el archivo de datos iniciales.

## Configuración operativa explícita

Los vehículos iniciales conservan `funcion_operativa=NULL` cuando no existe una
clasificación declarada. No se deduce REGULAR/APOYO por matrícula, residuo o ruta.

Para preparar una demostración, mediante las interfaces existentes y un usuario
con los permisos efectivos necesarios:

1. En **Camiones**, revisar el vehículo y establecer explícitamente su función
   REGULAR o APOYO según la decisión del responsable; guardar y comprobarla.
   No modificar historiales ni cerrar utilizaciones para eludir un rechazo.
2. En **Cuadrillas**, revisar integrantes con habilitación operativa vigente y
   vehículos autorizados por `usa`. La autorización no equivale a una V19 abierta.
3. En la gestión de utilización, abrir V19 para una pareja admisible confirmada
   por el backend. No crear autorizaciones o utilizaciones por SQL.
4. Para un recorrido REGULAR, crear una ejecución Pendiente, asignarla con la
   utilización coherente y comenzar desde Mi recolección. C2 vuelve a comprobar
   V19 y función REGULAR al iniciar; permite rutas con residuos mixtos.
5. C3 impide perder el último integrante habilitado o cerrar/cambiar V19 durante
   un recorrido REGULAR En Proceso. Finalizar admite pendientes y conserva autoría;
   no cierra V19 automáticamente. APOYO conserva el flujo F6 sin recorrido ficticio.

El conjunto masivo de contenedores no forma parte de los dos scripts mínimos;
cualquier carga adicional requiere revisión y autorización separadas. OSRM y las
teselas/CDN de mapas dependen de servicios externos. Este Compose no configura
`ZEMYNA_OSRM_URL`; las rutas consultivas requieren configuración posterior
autorizada y no son condición para iniciar la aplicación ni registrar atenciones.

## Diagnóstico inicial y corrección validada

La primera instalación `zemyna_demo_2026` en Ubuntu/WSL2 alcanzó estado healthy,
pero MariaDB 10.11.19 interrumpió `01-schema.sql` con ERROR 1901 al crear V20
`incidencia_upload_token`. No comenzó `02-init.sql`: quedaron 30 tablas y cero
usuarios y contenedores. Healthy no garantiza un esquema completo.

El CHECK de consumo ahora comprueba las fechas. Dos triggers BEFORE INSERT/UPDATE
rechazan una foto sin fecha de consumo. Se conservan la unicidad de foto, las FK
y ON DELETE SET NULL: borrar una foto no borra la fecha de consumo ni habilita
otra subida. La misma definición está en schema.sql y la migración V20. Los
triggers usan IF NOT EXISTS, sin backfill ni cambios de usuarios. Reaplicar la
migración sobre una tabla existente no sustituye sus CHECK históricos; cualquier
actualización requiere revisión separada. Los clientes de consola interpretan
DELIMITER; los fixtures PDO usan un adaptador exclusivo de tests.

La imagen incorpora `docker/apache-zemyna.conf` como VirtualHost. Conserva el
DocumentRoot y las URLs; `/` y `/frontend/` redirigen a
`/frontend/public/index.html`. Solo permite archivos estáticos públicos, los CSS
de `frontend/src/styles` ya utilizados y las entradas PHP de `backend/api`.
El resto queda denegado, sin listado de directorios ni .htaccess que amplíen el
acceso. SQL, entornos, documentación, configuración, modelos, tests, fotos y logs
internos no son descargables. Las fotos siguen pasando por `backend/api/foto.php`
y sus controles existentes. No cambiar DocumentRoot a frontend/public sin adaptar
rutas: las páginas usan ../src/styles y config.js resuelve ../../backend.

La instalación limpia corregida `zemyna_demo_fix_20261019` funcionó según las
comprobaciones confirmadas por el usuario: MariaDB 10.11 completó schema.sql e
init.sql; frontend, API, phpMyAdmin, mapa con contenedores demo y login funcionaron.
Se utilizaron **18080 para la aplicación** y **18081 para phpMyAdmin**. Son los
puertos de esa prueba, no los valores predeterminados del Compose (8080 y 8081
cuando no se sobrescriben). No se modifican aquí puertos ni variables de entorno.

Verificaciones HTTP observadas, comunicadas por el usuario:

| Recurso | Resultado |
| --- | --- |
| `/base-datos/database/sql/schema.sql` | 403 |
| `/.env.docker` | 403 |
| `/` | 302, esperado por redirección a la entrada pública |

En el primer arranque se informó unhealthy antes de concluir la inicialización y
posteriormente db quedó healthy. El margen de 4 minutos se incorpora después de
esa prueba y queda pendiente validar su comportamiento en una ejecución autorizada.
La corrección no repara el volumen anterior parcialmente inicializado. Conservar
`zemyna_demo_2026` y sus volúmenes; no importar schema.sql sobre ellos.

## Prueba posterior en un proyecto nuevo

Requiere autorización posterior. Desde este directorio en Ubuntu/WSL2:

1. Elegir un proyecto nuevo distinto de `zemyna_demo_fix_20261019`, que ya existe.
   Los comandos siguientes usan el marcador `NOMBRE_NUEVO`. Verificar con
   `docker ps -a` y `docker volume ls`, filtrados por la etiqueta
   `com.docker.compose.project=NOMBRE_NUEVO`, que no exista. Si hay
   coincidencias, detenerse; no reutilizar ni borrar.
2. Revisar .env.docker sin imprimir secretos. Acordar dos puertos libres distintos
   de XAMPP y del proyecto anterior. La BD no publica puerto host.
3. Sustituir los puertos simbólicos por los acordados en ambos comandos:

   ```text
   APP_PORT=PUERTO_APP_LIBRE PHPMYADMIN_PORT=PUERTO_PMA_LIBRE docker compose --project-name NOMBRE_NUEVO --env-file .env.docker -f compose.yaml config --quiet
   APP_PORT=PUERTO_APP_LIBRE PHPMYADMIN_PORT=PUERTO_PMA_LIBRE docker compose --project-name NOMBRE_NUEVO --env-file .env.docker -f compose.yaml up -d --build
   ```

4. Confirmar mediante lecturas que ambos scripts finalizaron, existen las 32
   tablas esperadas (incluidas V20/V21), los dos triggers V20, cuatro usuarios demo
   con roles, tres contenedores activos con coordenadas y el catálogo V19.
   No mostrar hashes ni deducir contraseñas: usar credenciales demo conocidas
   legítimamente. La contraseña de MariaDB no es una contraseña de la aplicación.
5. Validar HTTP: raíz y /frontend/ redirigen; páginas, CSS y JS cargan; login,
   sesión y controles 401/403 funcionan; el mapa público devuelve los tres puntos
   dentro de su viewport. SQL, .env, documentación, modelos, tests, logs y uploads
   directos deben devolver 403/404. Probar archivos ocultos, extensiones dobles,
   directorios sin índice y PATH_INFO de API. foto.php conserva sus controles.
6. Con autorización específica de escritura solo sobre esa BD descartable,
   comprobar tokens expirados, foto sin consumo rechazada, consumo único, borrado
   de foto sin reactivar token y registro/seguimiento con evidencia. SQLite no
   sustituye MariaDB real ni la comprobación HTTP dentro de la imagen.

No ejecutar down -v ni limpieza global. La retirada del entorno nuevo requiere
planificación y autorización separadas, siempre con su nombre exclusivo.
