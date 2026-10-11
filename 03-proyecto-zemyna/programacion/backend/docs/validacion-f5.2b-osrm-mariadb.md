# F5.2B — validación de integración vial y MariaDB

## Alcance

Revisión de F5.1/F5.2A y ejecución dirigida. No frontend, migraciones ni cambios
en módulos operativos. No instala OSRM/Docker ni descarga datasets. F5.1 sigue
independiente: resultados OSRM nunca se atribuyen al Dijkstra propio.

Se reutiliza el binario MariaDB 10.4.32 ya instalado, con proceso separado
en loopback, puerto efímero distinto de 3306, `--no-defaults` y datadir exclusivo
`TEMP/zemyna-f52b-<32 hex>/data`. No utiliza config habitual, VM o servicio instalado.
La inicialización usa mysql_install_db existente sin registrar servicio Windows.
Solo esa instancia descartable ajusta su política de flush para agilizar fixtures.

## Pruebas MariaDB

`tests/RutaApoyoMariaDbTest.php` exige `ZEMYNA_F52B_MARIADB_DSN` con host 127.0.0.1,
puerto explícito distinto de 3306 y charset utf8mb4. Antes de crear una base
comprueba @@datadir dentro de TEMP y patrón f52b. No acepta una base proporcionada
por el cliente. Crea `f52b_test_<12 hex>`, aplica el esquema oficial, utiliza
datos ficticios y elimina su base al finalizar cada caso.

El segundo PDO es un escritor independiente: confirma cambios durante el
intervalo de espera HTTP simulado, cuando el lector ya cerró su primera
instantánea. Valida que la segunda lectura vea cambios confirmados, evitando
mantener una transacción REPEATABLE READ obsoleta a través de la llamada.
No simula cambios sobre el mismo PDO ni usa la BD habitual.

Cobertura: usuario/cuadrilla vigentes, permisos efectivos, IDOR, TI sin bypass,
V19 futura/cerrada/sustituida, permisos revocados durante consulta, reasignación
de incidencia con interrupción real del intento, ubicación modificada y pérdida
de pertenencia. La respuesta obsoleta queda sin data y devuelve 401/403/404/409
según el contexto. Es concurrencia entre conexiones durante HTTP, no un test
de contención FOR UPDATE de escritores F3/F4/V19 ni una prueba universal de carreras.

La comparación de contenido de todas las tablas oficiales comprueba ausencia
de escrituras en éxito y NoRoute. Un caso adicional utiliza una cuenta temporal
con exclusivamente SELECT: la consulta debe funcionar y un UPDATE de control
debe ser rechazado por MariaDB. La cuenta se elimina en finally.

```console
php vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/RutaApoyoMariaDbTest.php
php vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/RutaApoyoTest.php
php vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/DijkstraTest.php
```

Sin DSN descartable el test MariaDB declara skip; no interpretar un skip como PASS.
La validación registrada de esta fase requiere ejecución habilitada sin omisiones.

Resultado ejecutado con MariaDB 10.4.32, PHP 8.1.25 y PHPUnit 10.5.64:
**10 casos, 70 aserciones, PASS, sin skips**. Se ejecutaron primero nueve casos
(64 aserciones) y después el caso adicional SELECT-only de forma dirigida
(6 aserciones), sin repetir la suite. Los cambios concurrentes confirmados se
detectaron correctamente. Todas las bases f52b_test_* y la cuenta temporal se
eliminaron; solo permanecían las bases de sistema de la instancia descartable
antes de apagarla. F5.2A: **13 tests, 285 aserciones, PASS**. F5.1: **12 tests,
71 aserciones, PASS**. Sintaxis PHP y espacios verificados.

## Consulta OSRM real: bloqueada

Se revisaron las políticas oficiales antes de intentar acceso:

- https://github.com/Project-OSRM/osrm-backend/wiki/Demo-server
- https://routing.openstreetmap.de/about.html

Uso razonable no comercial, máximo una solicitud por segundo, sin scraping o
carga intensa, identificación User-Agent y atribución OSRM/OpenStreetMap.
El demo no garantiza disponibilidad, latencia o fecha de actualización.

Se intentó comprobar disponibilidad mediante HEAD a la raíz HTTPS, sin
coordenadas. El sandbox no pudo conectar. Con acceso escalado aprobado, cURL
de Windows falló en TLS (SEC_E_ILLEGAL_MESSAGE); PHP/cURL tampoco pudo verificar
la disponibilidad HTTPS. No se relajó TLS, no hubo fallback HTTP ni se envió
ninguna petición Route. No afirmar indisponibilidad global: el bloqueo observado
es de conectividad HTTPS desde este entorno.
PHP usa cURL 8.4.0 / OpenSSL 1.1.1w; el archivo CA configurado existe. Su
existencia no demuestra validez/actualización de la cadena ni resuelve el fallo.
Se requiere diagnosticar conectividad/TLS/proxy/CA fuera de esta validación;
no se modificó la configuración de PHP ni se instalaron certificados.

`scripts/validar_osrm_publico.php` prepara una única consulta opt-in después
de una comprobación HEAD satisfactoria. No abre BD ni carga datos de trabajos.
Usa dos puntos públicos aproximados del corredor 18 de Julio, Montevideo:

```text
origen:  latitud=-34.9061, longitud=-56.1960
destino: latitud=-34.9057, longitud=-56.1878
OSRM:    -56.1960000,-34.9061000;-56.1878000,-34.9057000
```

```console
php scripts/validar_osrm_publico.php --una-consulta-demo
```

No ejecutar como parte de suites ni repetir automáticamente. Revisar política
y conectividad antes de un nuevo intento. El script requiere CLI, respeta
timeout de 8 s, TLS verificado, ajuste máximo 50 m y validación del cliente real.
Entrega geometría/métricas/puntos ajustados solo si se valida una respuesta real.
El resultado de esta fase fue `code=disponibilidad`, statusCode 503 y exit 1:
**OSRM real NO VALIDADO; cero consultas de ruta enviadas**.

GeoJSON, orden longitud/latitud, métricas, errores y advertencias del servicio
sí se verifican mediante respuestas simuladas en tests dirigidos. Esa evidencia
no sustituye la compatibilidad real del motor. El script directo prueba cliente,
no HTTP autenticado end-to-end del endpoint operativo.

## Revisión de seguridad y problemas

Se confirmó URL exclusivamente de configuración del servidor, sin URL por
request, sin credenciales embebidas/redirects, timeout acotado, TLS verificado,
respuesta limitada a 1 MiB/10000 puntos, errores públicos y sin líneas rectas.
Dos lecturas cortas, READ ONLY en MariaDB; no se ejecuta HTTP dentro de transacción.
No requiere cambios funcionales para los escenarios que pasan en MariaDB.

Límites conservados: sin certificación geográfica persistida, sin datos de peso/
altura/anchura para certificar acceso pesado, sin cuota global en PHP, sin
versión monotónica para detectar cambios revertidos entre instantáneas. El mapa
base requiere disponibilidad propia, independiente de un motor vial local.

## Recursos locales aproximados, sin medición de OSRM

Geofabrik publica un extracto Uruguay de aproximadamente 53 MB en su página
consultada; no fue descargado: https://download.geofabrik.de/south-america/uruguay.html

La documentación OSRM advierte dependencia del perfil y crecimiento de archivos
durante extracción/preprocesamiento. Sus cifras de planet 2021 son orientativas,
no un benchmark de Uruguay actual:
https://github.com/Project-OSRM/osrm-backend/wiki/Disk-and-Memory-Requirements

Presupuesto inicial conservador para UN perfil automóvil de Uruguay (estimación
de planificación, no requisito mínimo garantizado):

| Recurso | Reserva propuesta |
|---|---|
| Dataset PBF | Alrededor de 53 MB, según publicación consultada |
| Disco disponible | 5–10 GB para imagen, datos generados, temporales y una actualización |
| RAM de preparación | 2–4 GB asignados; aumentar según pico medido |
| RAM al servir | 1–2 GB iniciales; medir archivos, RSS y concurrencia |
| CPU | 2–4 vCPU para preparación; no prometer duración sin benchmark |

Pipeline futuro: imagen Docker con versión/digest fijados, PBF con fecha/checksum,
perfil Lua versionado, osrm-extract → osrm-partition → osrm-customize → osrm-routed
MLD, volumen persistente y red privada sin publicación indiscriminada del puerto.
Fuente: https://github.com/Project-OSRM/osrm-backend

Internet requerido inicialmente para imagen/dataset y actualizaciones. Con
imagen y grafo preparados, el cálculo local puede funcionar sin Internet;
tiles/geocodificación no quedan resueltos por OSRM. No se instalaron ni
descargaron imagen, PBF o paquetes en esta fase.

## Continuidad

GO para preparar F5.3 contra el contrato y fixtures, manteniendo la sugerencia
consultiva y estados de indisponibilidad. NO-GO para dar por validada integración
OSRM real o cerrar la defensa vial end-to-end hasta resolver el bloqueo y ejecutar
la única consulta pendiente. No usar un resultado simulado como evidencia real.
