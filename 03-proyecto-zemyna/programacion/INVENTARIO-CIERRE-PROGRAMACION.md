# Inventario de cierre de Programación

Corte documental: 19/10/2026. Auditoría sobre `main`, HEAD inicial
`39925c302c04d14ac2f0d100f4a827076484369c`. Fetch verificado: `origin/main`
coincidía antes de preparar la publicación. Este documento registra el alcance
autorizado; consultar el historial Git para los hashes y el estado de publicación.

Se identificaron 67 archivos: 49 modificados y 18 nuevos, sin eliminaciones.
Se autorizan 62 archivos en seis commits; los cinco experimentales quedan fuera,
sin borrarlos ni descartarlos. Las rutas son relativas a
`03-proyecto-zemyna/programacion/`, salvo `README.md` raíz.

## 1. test(v19): completar fixture SQLite con tablas F6 (1 archivos)

- `backend/tests/AsignacionVehiculoTest.php`.

Fixture SQLite V19: incorpora las tablas F6 faltantes sin cambiar expectativas. Validar los 24 casos antes de publicar.

## 2. fix(operaciones): proteger integridad y continuidad de recorridos (17 archivos)

- `backend/controllers/ParticipaController.php`.
- `backend/controllers/UsaController.php`.
- `backend/controllers/RecorridoController.php`.
- `backend/controllers/UsuarioController.php`.
- `backend/models/Participa.php`.
- `backend/models/Usa.php`.
- `backend/models/IntegridadRelacionesOperativas.php`.
- `backend/models/RecoleccionOperativa.php`.
- `backend/models/Recorrido.php`.
- `backend/models/Usuario.php`.
- `backend/models/AsignacionVehiculo.php`.
- `backend/models/ContinuidadSolicitudOperativa.php`.
- `backend/models/ContinuidadRecorridoRegular.php`.
- `backend/tests/RecoleccionTest.php`.
- `backend/tests/IntegridadRelacionesOperativasTest.php`.
- `backend/tests/RecorridoRegularC2Test.php`.
- `backend/tests/ContinuidadRecorridoRegularTest.php`.

Unidad C1/C2/C3: guard compartido, reglas REGULAR/V19, protección de historia y continuidad. Depende del fixture V19. Separarla requiere staging parcial y validación aislada; no se propone aquí. No exige residuos uniformes en rutas mixtas.

## 3. fix(recoleccion): aclarar contexto APOYO y presentar incidencias (13 archivos)

- `backend/controllers/RutaApoyoController.php`.
- `backend/services/RutaApoyoService.php`.
- `backend/tests/RutaApoyoTest.php`.
- `frontend/public/assets/js/ruta-incidencia.js`.
- `frontend/public/assets/js/recoleccion.js`.
- `frontend/tests/ruta-incidencia.test.cjs`.
- `frontend/tests/recoleccion-mensajes.test.cjs`.
- `frontend/tests/recoleccion-solicitudes.test.cjs`.
- `frontend/public/assets/js/recoleccion-incidencias.js`.
- `frontend/public/assets/css/recoleccion.css`.
- `frontend/public/recoleccion.html`.
- `frontend/tests/recoleccion-incidencias.test.cjs`.
- `frontend/tests/map-experience.test.cjs`.

Mensajes F8.3, presentación de incidencias y mocks alineados viajan juntos: la prueba de rutas depende de la versión del script de incidencias en HTML. No cambia permisos, estados ni OSRM.

## 4. fix(admin): mejorar legibilidad y cierre del menu movil (3 archivos)

- `frontend/public/assets/css/admin.css`.
- `frontend/public/assets/js/admin.js`.
- `frontend/tests/admin-menu.test.cjs`.

Legibilidad administrativa y Escape/foco del menú móvil. Cuadrillas A1/A1.1/A2 y Mi recolección 2.0 ya estaban versionadas; no se presentan como nuevas.

## 5. fix(docker): completar instalacion limpia y proteger archivos internos (21 archivos)

- `Dockerfile`.
- `compose.yaml`.
- `docker/apache-zemyna.conf`.
- `README-Docker.md`.
- `base-datos/database/sql/schema.sql`.
- `base-datos/database/sql/migration_v20_incidencia_upload_token.sql`.
- `base-datos/database/sql/init.sql`.
- `backend/tests/DockerComposeContractTest.php`.
- `backend/tests/DockerInstallationSecurityTest.php`.
- `backend/tests/fixtures/mariadb_sql.php`.
- `backend/tests/AsignacionVehiculoMariaDbTest.php`.
- `backend/tests/AtencionIncidenciaMariaDbTest.php`.
- `backend/tests/ContinuidadSolicitudOperativaMariaDbTest.php`.
- `backend/tests/F1SecurityMariaDbTest.php`.
- `backend/tests/IncidenciaAsignacionV19MariaDbTest.php`.
- `backend/tests/ProteccionSolicitudOperativaMariaDbTest.php`.
- `backend/tests/ReporteOperativoMariaDbTest.php`.
- `backend/tests/RutaApoyoMariaDbTest.php`.
- `backend/tests/SolicitudAsignacionV19MariaDbTest.php`.
- `backend/tests/SolicitudV21MariaDbTest.php`.
- `backend/tests/SolicitudesPropiasMariaDbTest.php`.

Instalación limpia, permisos V19, compatibilidad V20, Apache y start_period de MariaDB de 4m. Incluye el adaptador DELIMITER para PDO y sus once consumidores normales; no incluye el consumidor experimental C1. No cambia puertos, datos demo ni credenciales.

## 6. docs(programacion): consolidar evidencia y guia de entrega (7 archivos)

- `backend/DASHBOARD.md`.
- `backend/RECOLECCION.md`.
- `backend/docs/contrato-v19-asignacion-vehiculo.md`.
- `base-datos/database/sql/00_INICIALIZAR.sql`.
- `GUIA-DEMO-PROGRAMACION.md`.
- `INVENTARIO-CIERRE-PROGRAMACION.md`.
- `README.md (raíz)`.

Documentación final y correcciones de afirmaciones desfasadas. Depende del conjunto funcional anterior. 00_INICIALIZAR.sql solo cambia comentarios, no instrucciones ejecutables.

## Harness experimental aplazado (5 archivos)

- `backend/tests/IntegridadRelacionesOperativasMariaDbTest.php`.
- `backend/tests/fixtures/c1_mariadb_sandbox.php`.
- `backend/tests/fixtures/c1_seed.php`.
- `backend/tests/fixtures/c1_worker.php`.
- `backend/tests/tools/c1-sandbox.ps1`.

No incluir en estos commits. La clase de pruebas redefine result(), un método
final de PHPUnit, y el seed/expectativas de concurrencia necesitan alinearse con
V19 obligatorio de C2. Su publicación requiere una tarea autorizada separada.
No se ejecutó Prepare ni se certificó concurrencia MariaDB.

## Evidencia y límites

La instalación limpia Docker `zemyna_demo_fix_20261019` fue confirmada por el
usuario: frontend/API, MariaDB, phpMyAdmin, login y mapa operativos; 32 tablas,
4 usuarios, 5 roles, 3 contenedores, 2 solicitudes y 2 triggers V20. Se utilizaron
18080/18081; no son los puertos por defecto. schema.sql y .env.docker devolvieron
403 y la raíz 302 esperado. Ver README-Docker.md.

El ajuste posterior start_period: 4m aún no fue validado por ejecución.
Las pruebas SQLite/dobles no certifican concurrencia real. OSRM/CDN/teselas no
ofrecen disponibilidad garantizada. No se agregan GPS, optimización ni No atendido.

## Validación previa a cada commit

Ejecutar pruebas focalizadas con archivos explícitos, sin descubrir todo backend/tests,
que incluye integraciones con escrituras y el harness experimental. Verificar cada
índice con diff --cached --check, nombres exactos y revisión del diff completo.
No incluir .env, .env.docker, vendor, cachés, logs, respaldos ni secretos.
La inicialización limpia no se ejecuta sobre bases existentes.

El grupo frontend dirigido del cierre comprende estos 13 archivos, desde frontend/:

```text
tests/login-session.test.cjs
tests/map-experience.test.cjs
tests/recoleccion-mapa.test.cjs
tests/recoleccion-recorrido.test.cjs
tests/cuadrillas-resumen.test.cjs
tests/reportes-operativos.test.cjs
tests/public-upload.test.cjs
tests/recoleccion-mensajes.test.cjs
tests/admin-menu.test.cjs
tests/asignaciones-vehiculo.test.cjs
tests/recoleccion-incidencias.test.cjs
tests/recoleccion-solicitudes.test.cjs
tests/ruta-incidencia.test.cjs
```

Ejecutarlos con node --test y la lista explícita. No escriben registros operativos;
los tests DOM no certifican apariencia. La revisión del remoto se repite antes del
push, sin merge, rebase ni force. La confirmación de publicación corresponde al
resultado real de Git, no a este inventario.
