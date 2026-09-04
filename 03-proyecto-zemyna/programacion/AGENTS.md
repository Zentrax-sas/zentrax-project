# Zemyna - Programacion Fullstack

## Proposito

Estas instrucciones se aplican a todo el contenido de `03-proyecto-zemyna/programacion/`.
El objetivo actual es completar la segunda entrega del Proyecto de Pasaje de Grado 2026 sin rehacer lo que ya funciona y dejando evidencia suficiente para que el equipo pueda comprender, demostrar y defender cada cambio.

## Contexto funcional

Zemyna es un sistema de gestion de residuos urbanos desarrollado por Zentrax SAS. El modulo de Programacion incluye frontend, APIs REST, logica de negocio y persistencia.

Para la segunda entrega deben funcionar:

- registro e inicio de sesion completos;
- CRUD de usuarios de todos los roles;
- CRUD de contenedores;
- CRUD de camiones o vehiculos;
- CRUD de centros de acopio;
- CRUD de maquinaria basica;
- endpoints y persistencia para esos CRUD;
- autenticacion y autorizacion por roles y permisos;
- pruebas unitarias mediante codigo;
- datos de prueba;
- archivos necesarios para ejecutar las aplicaciones con Docker.

## Tecnologias obligatorias

- Frontend: HTML5, CSS y JavaScript sin frameworks.
- Backend: PHP 8 puro, sin Laravel, Symfony ni otros frameworks web.
- Base de datos: MySQL 8 como objetivo oficial. Mantener compatibilidad con MariaDB solo cuando no contradiga la letra.
- Acceso a datos: PDO y consultas preparadas.
- Testing: PHPUnit instalado mediante Composer como dependencia de desarrollo.
- Despliegue: Docker y Docker Compose sobre Linux.
- Control de versiones: Git y GitHub.

No incorporar frameworks, ORMs, motores de base de datos ni herramientas nuevas sin explicar la necesidad y solicitar confirmacion.

## Fuentes de verdad

Antes de cambiar codigo, consultar en este orden:

1. La letra oficial del Proyecto de Pasaje de Grado 2026.
2. El codigo y el esquema actuales del repositorio.
3. Las correcciones escritas de los docentes.
4. Los ejemplos de clase del profesor.
5. La documentacion propia de Zemyna que siga vigente.

Si dos fuentes se contradicen, no adivinar. Informar la contradiccion y proponer una solucion antes de modificar.

## Forma de trabajo obligatoria

Antes de implementar una tarea:

1. Leer completamente los archivos relacionados.
2. Explicar el estado actual y el cambio propuesto.
3. Identificar modelos, controladores, endpoints, frontend, SQL y pruebas afectados.
4. Realizar el cambio minimo necesario.
5. Ejecutar validaciones y pruebas relevantes.
6. Informar archivos modificados, pruebas ejecutadas y resultado real.

No declarar una fase, CRUD o requisito como completo si no fue ejecutado y comprobado. Cuando el entorno no permita probar algo, marcarlo expresamente como "pendiente de validacion".

## Preservacion del proyecto

- No rehacer el proyecto desde cero.
- No reemplazar codigo funcional solo por preferencia personal.
- No duplicar entidades, tablas, endpoints o conceptos existentes.
- No borrar ni renombrar componentes sin revisar todas sus referencias.
- Preservar los mas de 11.000 contenedores importados desde datos abiertos.
- No ejecutar operaciones destructivas contra una base con datos sin advertencia y confirmacion.
- Mantener separados instalacion limpia, migraciones y carga de datos de prueba.
- No editar archivos historicos u obsoletos para implementar funcionalidad vigente.

## Arquitectura y organizacion

Respetar la estructura existente:

```text
programacion/
|- backend/
|  |- api/
|  |- config/
|  |- controllers/
|  |- helpers/
|  |- models/
|  `- tests/
|- base-datos/
|  `- database/sql/
`- frontend/
   |- public/
   `- src/
```

- Los modelos realizan acceso a datos.
- Los controladores contienen validaciones y logica de aplicacion.
- Los archivos de `api/` gestionan HTTP, autorizacion y serializacion JSON.
- El frontend consume las APIs; no accede directamente a MySQL.
- Evitar logica de negocio duplicada en endpoints o JavaScript.

La letra general describe varias aplicaciones independientes. No afirmar que la arquitectura distribuida esta cumplida hasta comprobar la separacion y el despliegue real de cada aplicacion.

## API REST

- Responder JSON consistente y declarar `Content-Type: application/json`.
- Utilizar codigos HTTP coherentes: 200, 201, 204, 400, 401, 403, 404, 409 y 500 segun corresponda.
- Validar el metodo HTTP y devolver 405 cuando no este permitido.
- Rechazar JSON invalido con 400.
- Validar tipos, campos requeridos, longitudes, formatos y relaciones.
- No devolver excepciones, consultas SQL, credenciales ni rutas internas al cliente.
- Las operaciones administrativas requieren autenticacion y permiso explicito.
- Los endpoints publicos deben estar identificados y justificados.
- La seguridad del backend es obligatoria aunque el frontend oculte botones.

## Autenticacion y autorizacion

- Mantener `password_hash()` y `password_verify()`.
- Regenerar el identificador de sesion al iniciar sesion.
- No almacenar contrasenas en texto plano.
- Aplicar `requireAuth()`, `requireRole()` o `requirePermission()` segun corresponda.
- Respetar el modelo vigente de rol, sector y permiso.
- No confiar en roles o permisos enviados por el frontend.
- No exponer operaciones administrativas de solicitudes, vecinos, fotos, acopios, vertederos o tipos de residuo sin revisar su autorizacion.
- Cualquier cambio en permisos debe incluir pruebas positivas y negativas.

## Base de datos

- Unificar el nombre configurado de la base con los scripts SQL.
- Usar InnoDB, UTF-8 (`utf8mb4`), claves primarias y foraneas coherentes.
- Usar consultas preparadas para valores externos.
- Preferir bajas logicas donde el proyecto necesite conservar historial.
- Los scripts de instalacion limpia pueden ser destructivos solo si su nombre y documentacion lo advierten claramente.
- Las migraciones de actualizacion no deben eliminar datos existentes.
- Los datos demo deben ser repetibles y distinguibles de los datos reales.
- Revisar compatibilidad con MySQL 8 antes de aceptar sintaxis probada solo en MariaDB.

## Frontend

- Mantener HTML, CSS y JavaScript sin frameworks.
- No simular como exitosas operaciones que la API no haya confirmado.
- Mostrar estados de carga, exito, error y listas vacias.
- Escapar contenido dinamico antes de insertarlo en HTML.
- Adaptar la interfaz a permisos sin tratar esa adaptacion como unica medida de seguridad.
- Verificar navegacion, registro, login y los cinco CRUD obligatorios.
- Mantener rutas portables; no codificar rutas exclusivas de una computadora.

## Docker

- No usar secretos reales dentro de `docker-compose.yml`.
- Usar `.env.example` con nombres consistentes y valores no sensibles.
- Evitar etiquetas `latest` en la version entregable.
- Asegurar que PHP incluya las extensiones requeridas, especialmente PDO MySQL.
- Incluir comprobacion de salud u orden de arranque cuando la aplicacion dependa de MySQL.
- Comprobar desde cero: construccion, inicio, inicializacion de BD, acceso web y ejecucion de pruebas.

## Git y documentacion

- No subir `.env`, claves, contrasenas, tokens, `vendor/` ni archivos temporales.
- Hacer commits pequenos y descriptivos asociados a una tarea verificable.
- No mezclar correcciones no relacionadas en el mismo commit.
- Documentar comandos reales de instalacion y prueba.
- Las afirmaciones de validacion deben indicar fecha, entorno, comando y resultado.
- No generar documentacion extensa o nuevos archivos `.md` si la informacion corresponde a uno existente.

## Definicion de terminado

Una tarea solo esta terminada cuando:

- el codigo esta implementado;
- las rutas y dependencias son coherentes;
- se ejecutaron las pruebas correspondientes;
- no se rompieron funciones relacionadas;
- la documentacion refleja el comportamiento real;
- el equipo puede explicar la solucion.

## Alcance actual

Priorizar la segunda entrega. Funciones de tercera entrega, refactorizaciones amplias y mejoras opcionales deben posponerse si ponen en riesgo registro, login, CRUD, seguridad, pruebas, base de datos o Docker.

