# Demostración breve de la tercera entrega de Programación

Guion preparado el 18/10/2026 y actualizado el 19/10/2026 con la validación Docker
confirmada por el usuario. No acredita la ejecución completa de este guion.
Usar cuentas autorizadas y un escenario académico aislado, preparado y aprobado
antes de cualquier escritura. No crear usuarios, alterar permisos, iniciar atenciones
ni preparar recorridos sobre datos habituales por ejecutar este documento.

## Entorno y preparación

- Entorno local previamente utilizado: Apache81/PHP 8.1.25. Si continúa disponible,
  acceso: `http://127.0.0.1:8081/zentrax-project/03-proyecto-zemyna/programacion/frontend/public/login.html`.
  Verificar disponibilidad antes; esta guía no inicia servicios.
- Instalación limpia Docker confirmada en Ubuntu/WSL2: `zemyna_demo_fix_20261019`,
  aplicación en `http://localhost:18080/` y phpMyAdmin en 18081. Frontend/API,
  MariaDB, login y mapa funcionaron; se verificaron 32 tablas, 4 usuarios, 5 roles,
  3 contenedores, 2 solicitudes y los 2 triggers V20. Los archivos schema.sql y
  .env.docker devolvieron 403; la raíz devolvió 302 esperado. Son evidencias
  confirmadas por el usuario, no una nueva ejecución del agente. Ver
  [README-Docker.md](README-Docker.md); no ejecutar el Compose de la raíz.
- Seleccionar una cuadrilla con un integrante habilitado en OPERACIONES, vehículo
  clasificado REGULAR y activo, pareja `usa` válida, V19 abierta y ruta con
  contenedores. Confirmarlo en las interfaces existentes; no inventar IDs ni
  reutilizar artificialmente un recorrido finalizado. La ruta de 109 contenedores
  fue evidencia anterior, no una garantía sobre la BD actual.
- Preparar una cuenta técnica y otra operativa con permisos explícitos vigentes,
  sin imprimir contraseñas, cookies, tokens ni datos personales en la presentación.

## Secuencia sugerida (10–15 minutos)

| Paso | Acción manual en el escenario autorizado | Evidencia que mostrar |
|---|---|---|
| 1. Login y permisos | Iniciar sesión; mostrar navegación de la cuenta técnica y de la operativa | TI administra usuarios con permisos explícitos; no tiene acceso municipal por nombre del rol. El rol operativo es ADMINISTRATIVO_OPERATIVO, sin renombrarlo. No intentar escrituras para demostrar un 403. |
| 2. Preparación y API | Cuadrillas → detalle → Preparar jornada; revisar integrantes, `usa`, utilización vigente y recorrido Pendiente | La guía es consultiva. La asignación requiere V19 coherente y vehículo REGULAR. En Red del navegador puede mostrarse GET `backend/api/recoleccion.php` y su JSON autorizado, sin encabezados de sesión. |
| 3. Recolección | Con autorización para el caso de prueba: iniciar desde Mi recolección, atender un contenedor desde lista o ficha del mapa y observar respuesta | POST de `iniciar`, `atender`, `finalizar` con IDs de recursos; actor y fechas los fija el servidor. El éxito actualiza progreso solo tras confirmación. C1 conserva relaciones e historial. |
| 4. Mapa | Seleccionar un marcador, localizar su tarjeta, usar «Ver en mapa» y alternar Lista/Mapa en celular | Contenedores del endpoint autorizado, estados y selección sincronizados. No se unen puntos ni se afirma una secuencia optimizada. Si Leaflet o teselas fallan, usar Lista. |
| 5. Incidencia y tracking | En el escenario autorizado, registrar un problema académico desde el formulario público; conservar el código y consultarlo en seguimiento | Registro y consulta real por tracking. Usar datos ficticios permitidos; no revelar contactos ni credenciales. Si no se autoriza crear, usar una incidencia académica existente y aclarar que se demuestra solo seguimiento. |
| 6. Informes | Abrir Informe de incidencias; alternar abiertas/cerradas y fechas. Mostrar Reportes operativos si la cuenta tiene todas las fuentes requeridas | Totales del backend, filtros, cero frente a Sin datos. F7 añade solicitudes, atención y recorridos; no sustituye el informe simple de incidencias. |
| 7. Dashboard y alertas | Resumen operativo → actualizar/filtros | Conteos SQL y alertas `requiere_atencion`; si no hay alertas, mostrar ese estado sin crear incidencias para forzarlas. La campana no implementa notificaciones persistentes. |
| 8. Cierre y trazabilidad | Finalizar el recorrido académico tras la confirmación, aunque tenga pendientes; consultar resultado e historial | Autor y fecha de fin; no se inventan atenciones, no se cierra V19 ni se reescribe `participa`. C3 impide perder el último habilitado y cerrar/cambiar V19 durante la ejecución. Demostrar rechazos peligrosos mediante tests, sin suspender cuentas reales. |

La consulta propia puede devolver 409 `sin_recorrido` para APOYO sin recorrido
REGULAR. Explicar que puede atender incidencias/solicitudes asignadas mediante sus
permisos y contexto; no crear un recorrido ficticio. 401 exige nueva sesión, 403
no se elude cambiando de pestaña, 409 exige revisar contexto y 503 disponibilidad.

## Evidencia automatizada disponible

Resultados ya obtenidos en los pasos de cierre; no son ejecuciones Docker ni una
suite completa. Los grupos se solapan y no deben sumarse.

| Grupo | Evidencia registrada |
|---|---|
| V19, fixture F6 corregido | 24/24 tests, 91 aserciones; sin los cuatro fallos anteriores. |
| Recolección y C1/C2/C3 | 85 tests, 458 aserciones; incluye finalización con pendientes y rollback. |
| Regresión F6, asignación de incidencias y usuarios del paso 1 | 134 tests, 809 aserciones. |
| Frontend dirigido del paso 2 | 192/192 tests; mapa, selección, acciones, diálogos, permisos y respuestas obsoletas. |
| Contratos Docker y seguridad de instalación | 21 tests, 130 aserciones; contratos estáticos y semántica SQLite. No sustituyen MariaDB real. |
| Catálogo V19 limpio | Comparación con la migración histórica y repetición lógica SQLite: 2 permisos y 4 concesiones sin duplicación. No ejecutó el SQL completo en MariaDB. |

Para repetir pruebas dirigidas con autorización, desde `backend/`:

```text
php vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests/AsignacionVehiculoTest.php tests/ContinuidadRecorridoRegularTest.php tests/RecorridoRegularC2Test.php tests/IntegridadRelacionesOperativasTest.php tests/RecoleccionTest.php
```

Estos archivos usan dobles/SQLite en memoria. No sustituir el listado por todo el
directorio: contiene integraciones opcionales con escrituras y configuración propia.
El listado exacto de los 13 tests frontend se conserva en el inventario de cierre.

## Límites que deben declararse

- Instalación Docker real validada; concurrencia MariaDB C1/C2/C3 no certificada.
  El harness experimental queda fuera de la publicación. La ejecución del ajuste
  posterior del healthcheck a 4m permanece pendiente; editar Compose no lo aplica
  automáticamente a contenedores existentes.
- Leaflet/CDN/teselas y OSRM requieren servicios externos. OSRM no es necesario
  para demostrar atención de contenedores. La ruta real de F5 confirmada por el
  usuario en `INC-2026-BDA54` es evidencia histórica; no implica que el servicio
  esté disponible hoy ni que se haya repetido la consulta en este cierre.
- El Dijkstra PHP es una demostración académica separada. Las rutas viales son de
  OSRM, consultivas, sin garantías para vehículos pesados, GPS ni navegación paso
  a paso. No ejecutar consultas OSRM como parte automática de la demostración.
- No hay optimización de múltiples visitas, registro «No atendido» ni estimación
  histórica de progreso sin lista inmutable de contenedores esperados.
