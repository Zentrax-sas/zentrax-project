# PROMPT MAESTRO — ZEMYNA
## Segunda Entrega de Programación

Actuá como desarrollador senior especializado en **PHP, MySQL/MariaDB, JavaScript, APIs REST, MVC, Docker y testing**.

Vas a trabajar **SOBRE EL REPOSITORIO ACTUAL DE ZEMYNA**.

## IMPORTANTE

No rehagas el proyecto desde cero.

No reemplaces código que ya funciona solamente porque preferís otra solución.

No inventes tablas, entidades, endpoints ni funcionalidades sin revisar primero qué existe.

No dupliques conceptos existentes.

No borres datos actuales.

No realices cambios destructivos en la base de datos sin advertirlos previamente.

El objetivo es **completar y mejorar lo que ya existe para cumplir correctamente la segunda entrega de Programación**, manteniendo la arquitectura actual y dejando el proyecto preparado para continuar creciendo.

---

# 1. UBICACIÓN DEL PROYECTO

Trabajar principalmente sobre:

```text
03-proyecto-zemyna/programacion/
```

Revisar también cuando corresponda:

```text
docker-compose.yml
README.md
.gitignore
nginx.conf
.env
.env.example
```

y cualquier archivo relacionado con la ejecución del proyecto.

---

# 2. TECNOLOGÍAS ACTUALES

El proyecto utiliza actualmente:

- PHP
- MySQL / MariaDB
- HTML
- CSS
- JavaScript
- arquitectura organizada aproximadamente en MVC
- APIs JSON
- Leaflet
- MarkerCluster
- Docker
- sesiones PHP
- Git / GitHub

Mantener estas tecnologías salvo que exista una razón técnica clara para realizar un cambio.

---

# 3. PRIMERA FASE: AUDITORÍA COMPLETA

## NO MODIFICAR NADA TODAVÍA

Antes de escribir código, revisar completamente:

```text
frontend/
backend/
backend/api/
backend/controllers/
backend/models/
backend/helpers/
backend/config/
backend/routes/
backend/scripts/
base-datos/
```

También revisar:

- scripts SQL;
- schema;
- seeds;
- datos demo;
- importación de contenedores;
- Docker;
- login;
- sesiones;
- endpoints;
- CRUD;
- roles;
- permisos;
- frontend;
- tests existentes.

Luego devolver un informe con:

## EXISTE Y FUNCIONA

Qué funcionalidades están completas.

## EXISTE PERO NECESITA CORRECCIÓN

Qué componentes funcionan parcialmente o presentan inconsistencias.

## FALTA IMPLEMENTAR

Qué exige la segunda entrega y todavía no existe.

## INCONSISTENCIAS DETECTADAS

Especialmente entre:

- base de datos;
- modelos;
- controladores;
- API;
- frontend;
- documentación.

## ARCHIVOS QUE SERÍA NECESARIO MODIFICAR

Indicar archivo y motivo.

## ORDEN RECOMENDADO DE IMPLEMENTACIÓN

No comenzar cambios estructurales hasta terminar esta auditoría.

---

# 4. REQUISITOS DE LA SEGUNDA ENTREGA

Debe quedar funcionando:

## FRONTEND

- Correcciones de la primera entrega.
- Registro completo.
- Inicio de sesión completo.
- CRUD completo de usuarios.
- CRUD completo de contenedores.
- CRUD completo de camiones/vehículos.
- CRUD completo de centros de acopio.
- CRUD completo de maquinaria básica.

## BACKEND

- Endpoints para todos esos CRUD.
- Persistencia real mediante base de datos.
- Validaciones.
- Seguridad.
- Autenticación.
- Autorización.
- Separación de endpoints según roles y permisos.

## GITHUB

- Código correctamente organizado y versionado.
- Historial coherente.
- Sin credenciales reales expuestas.

## DOCKER

Todos los archivos necesarios para ejecutar el sistema utilizando Docker.

## BASE DE DATOS

- Correcciones de la primera entrega.
- Datos de prueba.
- Coherencia entre modelo y código.

## TESTING

Crear como mínimo:

# 30 TESTS AUTOMATIZADOS

Deben cubrir API, CRUD, autenticación, permisos, validaciones y errores.

---

# 5. NO ELIMINAR LOS CONTENEDORES REALES

Actualmente existen más de **11.000 contenedores** importados desde datos reales.

NO:

- eliminarlos;
- sustituirlos por tres registros demo;
- volver a importarlos innecesariamente;
- realizar una migración destructiva.

Conservar los datos existentes.

Los datos demo deben quedar separados o coexistir de manera controlada.

---

# 6. ROLES NUEVOS

Actualmente pueden existir roles antiguos como:

```text
Superusuario
Administrador
Operario
```

Revisar el modelo actual y migrarlo hacia esta estructura genérica.

## ADMINISTRADOR_TI

Responsable de:

- crear usuarios;
- modificar usuarios;
- suspender usuarios;
- realizar baja lógica;
- asignar roles;
- asignar sectores;
- administrar permisos;
- administrar accesos.

No administra la operación de residuos.

## RESPONSABLE_SECTORIAL

Administra los recursos maestros correspondientes a su sector.

NO crear roles diferentes como:

```text
JEFE_LOGISTICA
JEFE_MANTENIMIENTO
JEFE_ECOCENTRO
```

Utilizar:

```text
RESPONSABLE_SECTORIAL
+
SECTOR
```

Ejemplo:

```text
RESPONSABLE_SECTORIAL + LOGISTICA
RESPONSABLE_SECTORIAL + MANTENIMIENTO
RESPONSABLE_SECTORIAL + PUNTOS_Y_DESTINOS
```

## ADMINISTRATIVO_OPERATIVO

Organiza el trabajo diario.

Puede según permisos:

- consultar recursos;
- asignar vehículos;
- asignar operarios;
- crear solicitudes;
- organizar tareas;
- organizar recorridos;
- reasignar recursos;
- cambiar estados operativos permitidos.

## OPERARIO

Ejecuta tareas.

Puede según permisos:

- consultar sus tareas;
- iniciar tareas;
- modificar estados operativos;
- finalizar tareas;
- cerrar recorridos;
- registrar incidencias;
- adjuntar evidencia.

## INSPECTOR

Puede:

- consultar información;
- consultar estados;
- verificar situaciones;
- registrar incidencias;
- adjuntar fotografías/evidencia;
- agregar observaciones.

No administra recursos ni asignaciones.

---

# 7. SECTORES

Utilizar una estructura genérica.

Sectores iniciales:

```text
TI
LOGISTICA
MANTENIMIENTO
PUNTOS_Y_DESTINOS
OPERACIONES
INSPECCION
```

Debe ser posible agregar sectores posteriormente sin crear nuevos roles.

---

# 8. REGLA DE AUTORIZACIÓN

La autorización debe basarse conceptualmente en:

```text
ROL
+
SECTOR
+
PERMISO
=
AUTORIZACIÓN EFECTIVA
```

No alcanza con verificar únicamente el rol.

---

# 9. PERMISOS

Definir permisos claros.

Ejemplos:

## Usuarios

```text
usuario.crear
usuario.consultar
usuario.modificar
usuario.suspender
usuario.asignar_rol
usuario.asignar_sector
```

## Contenedores

```text
contenedor.crear
contenedor.consultar
contenedor.modificar
contenedor.baja
contenedor.cambiar_estado
```

## Vehículos

```text
vehiculo.crear
vehiculo.consultar
vehiculo.modificar
vehiculo.baja
vehiculo.cambiar_estado
vehiculo.asignar
```

## Lugares / Centros

```text
lugar.crear
lugar.consultar
lugar.modificar
lugar.baja
lugar.cambiar_estado
```

## Maquinaria

```text
maquinaria.crear
maquinaria.consultar
maquinaria.modificar
maquinaria.baja
maquinaria.cambiar_estado
```

## Incidencias

```text
incidencia.crear
incidencia.consultar
incidencia.modificar
incidencia.adjuntar_evidencia
```

El backend debe validar los permisos.

Ocultar un botón en frontend NO es seguridad suficiente.

---

# 10. AUTENTICACIÓN

Revisar el login existente.

Debe existir:

- autenticación contra la base de datos;
- password_hash / password_verify o equivalente seguro;
- sesión PHP;
- logout;
- usuario activo/inactivo;
- rol;
- sector;
- permisos.

Usar correctamente:

```text
401 = usuario no autenticado
403 = usuario autenticado pero sin permiso
```

Nunca almacenar contraseñas en texto plano.

---

# 11. CRUD DE USUARIOS

Debe permitir:

```text
CREATE
READ
UPDATE
BAJA LÓGICA
```

Evitar DELETE físico si existen registros relacionados.

---

# 12. CRUD DE CONTENEDORES

Ya existe código de contenedores.

NO reescribirlo completo.

Revisar y corregir:

- API;
- Controller;
- Model;
- validaciones;
- estados;
- filtros;
- coordenadas;
- dirección;
- relaciones;
- update;
- baja.

Mantener los más de 11.000 registros actuales.

---

# 13. RENDIMIENTO DEL MAPA

El mapa ya utiliza:

- Leaflet;
- MarkerCluster;
- carga por viewport;
- bounding box;
- límite de resultados;
- zoom mínimo;
- debounce.

MANTENER ESTA ESTRUCTURA.

NO cargar innecesariamente todos los contenedores.

NO realizar geocodificación mientras se cargan marcadores.

---

# 14. GEOCODIFICACIÓN INVERSA

Implementar geocodificación inversa definitiva pero simple.

Objetivo:

Cuando un usuario seleccione un contenedor, transformar:

```text
latitud + longitud
```

en una dirección comprensible.

Flujo:

```text
CLICK CONTENEDOR
↓
obtener id_contenedor
↓
backend busca coordenadas
↓
consultar caché
↓
si existe dirección:
    devolver dirección
↓
si no existe:
    reverse geocoding
↓
guardar dirección en caché
↓
devolver al frontend
```

---

# 15. ENDPOINT DE GEOCODIFICACIÓN

Crear:

```text
backend/api/geocodificacion.php
```

Debe recibir preferentemente:

```text
id_contenedor
```

El backend obtiene latitud y longitud desde la base de datos.

---

# 16. CACHÉ DE GEOCODIFICACIÓN

Crear solamente si no existe algo equivalente:

```text
geocodificacion_cache
```

Campos mínimos:

```text
id_contenedor
direccion
barrio
localidad
fecha_consulta
```

No es una entidad principal del negocio.

Es una optimización técnica.

---

# 17. EXPERIENCIA DEL USUARIO EN EL MAPA

Modificar el flujo:

```text
CLICK
↓
mostrar:
"Buscando dirección..."
```

Luego:

```text
Contenedor CTN-XXXX
Dirección:
Av. Italia y Comercio
Barrio:
Buceo
Estado:
Disponible
Coordenadas:
-34.xxxxxx, -56.xxxxxx
```

Mostrar:

```text
¿Es este el contenedor que querés reportar?
```

Botón:

```text
REPORTAR ESTE CONTENEDOR
```

Recién después abrir el formulario de incidencia.

---

# 18. FALLBACK DE GEOCODIFICACIÓN

Si falla el servicio externo:

NO bloquear al usuario.

Mostrar coordenadas y permitir generar igualmente la incidencia.

La incidencia debe conservar siempre:

```text
id_contenedor
```

como referencia principal.

---

# 19. CRUD DE VEHÍCULOS

Si ya existe:

```text
vehiculo
```

NO crear innecesariamente:

```text
camion
```

Analizar si `vehiculo` puede representar:

- camión;
- moto;
- camioneta;
- otros recursos móviles.

Completar CRUD requerido.

---

# 20. CRUD DE CENTROS DE ACOPIO

Actualmente existen conceptos relacionados con:

```text
centro
acopio
vertedero
```

Revisarlos antes de crear nuevas tablas.

Evitar duplicaciones.

---

# 21. CRUD DE MAQUINARIA

Revisar:

```text
tabla
model
controller
endpoint
frontend
```

Completar únicamente lo necesario.

---

# 22. SOLICITUDES

Actualmente ya existe el concepto:

```text
solicitud
```

NO crear automáticamente otra tabla llamada:

```text
solicitud_operativa
```

Primero analizar si la tabla actual puede evolucionar.

Modelo conceptual:

```text
SOLICITUD
↓
SECTOR RESPONSABLE
↓
UBICACIÓN
↓
ASIGNACIÓN
↓
RECURSO / OPERARIO
↓
TAREA
↓
ESTADO
↓
CIERRE
```

---

# 23. UBICACIÓN

NO crear una entidad específica:

```text
DOMICILIO
```

Diferenciar:

## Lugar permanente

Ejemplos:

```text
Ecocentro
Centro de acopio
Planta
Depósito
Base
```

## Ubicación puntual de solicitud

Ejemplos:

```text
domicilio
esquina
comercio
punto de retiro
```

Antes de crear una nueva tabla `ubicacion`, verificar si el modelo actual ya contiene una estructura equivalente.

---

# 24. DATOS MAESTROS Y ESTADOS

Separar:

```text
DATOS MAESTROS
```

de:

```text
DATOS OPERATIVOS
```

Ejemplo:

## Contenedor maestro

```text
codigo
capacidad
tipo
coordenadas
```

## Estado operativo

```text
Disponible
Lleno
Dañado
En mantenimiento
Fuera de servicio
```

Un operario puede necesitar:

```text
contenedor.cambiar_estado
```

sin tener permiso para modificar todos los campos del contenedor.

---

# 25. ENDPOINTS

Mantener estilo consistente con la API existente.

Los CRUD deben utilizar correctamente:

```text
GET
POST
PUT
PATCH
DELETE
```

cuando corresponda.

Utilizar códigos HTTP coherentes:

```text
200 OK
201 Created
400 Bad Request
401 Unauthorized
403 Forbidden
404 Not Found
405 Method Not Allowed
409 Conflict
500 Internal Server Error
```

---

# 26. VALIDACIONES

Validar siempre en BACKEND:

- campos requeridos;
- IDs;
- emails;
- longitudes;
- coordenadas;
- estados;
- tipos de datos;
- duplicados;
- relaciones;
- permisos;
- existencia de FK.

---

# 27. BASE DE DATOS

Revisar:

```text
schema.sql
init.sql
seeds
scripts de importación
```

Buscar:

- claves duplicadas;
- relaciones incorrectas;
- tablas sin uso;
- campos repetidos;
- nombres inconsistentes;
- FK faltantes;
- índices faltantes;
- datos demo mezclados con datos reales.

NO ejecutar:

```text
DROP DATABASE
DROP TABLE
TRUNCATE
```

sobre información existente sin autorización explícita.

---

# 28. DOCKER

Existe:

```text
docker-compose.yml
```

Revisarlo.

Verificar:

- Nginx;
- PHP-FPM;
- MariaDB/MySQL;
- phpMyAdmin;
- PDO MySQL;
- variables de entorno;
- rutas;
- volúmenes;
- inicialización SQL;
- nombre real de la base.

Usar:

```text
.env
```

para datos sensibles.

Mantener:

```text
.env.example
```

sin secretos reales.

Objetivo:

```bash
docker compose up
```

debe permitir levantar Zemyna correctamente.

---

# 29. TESTING

Crear mínimo:

# 30 TESTS AUTOMATIZADOS

Cubrir al menos:

## Autenticación

1. Login correcto.
2. Contraseña incorrecta.
3. Usuario inexistente.
4. Usuario inactivo.
5. Endpoint protegido sin sesión.

## Permisos

6. Administrador TI puede crear usuario.
7. Operario no puede crear usuario.
8. Responsable puede operar dentro de su sector.
9. Responsable no puede modificar otro sector.
10. Inspector no puede modificar recurso maestro.

## Usuarios

11. Crear usuario válido.
12. Email inválido.
13. Email duplicado.
14. Modificar usuario.
15. Baja lógica.

## Contenedores

16. Listar.
17. Buscar por ID.
18. Crear válido.
19. Crear inválido.
20. Modificar.
21. Cambiar estado.
22. ID inexistente.

## Vehículos

23. Crear.
24. Modificar.
25. Validar datos.

## Centros

26. Crear/consultar.

## Maquinaria

27. Crear/consultar.

## Geocodificación

28. ID inexistente.
29. Resultado desde caché.
30. Fallo externo con fallback controlado.

Si existen más tests útiles, crear más de 30.

Documentar claramente cómo ejecutarlos.

---

# 30. NO SOBREDISEÑAR

Para el volumen actual NO introducir innecesariamente:

- Redis;
- Elasticsearch;
- microservicios;
- Kubernetes;
- arquitecturas distribuidas;
- frameworks nuevos;
- dependencias enormes.

Mantener:

```text
PHP
+
MySQL/MariaDB
+
JavaScript
+
Leaflet
+
Docker
```

La prioridad es:

```text
simple
mantenible
funcional
defendible
```

---

# 31. NO INVENTAR REGLAS DE NEGOCIO

Si falta información para decidir algo:

marcar:

```text
DECISIÓN REQUERIDA
```

Explicar:

1. cuál es la duda;
2. alternativas;
3. recomendación;
4. consecuencias.

No inventar silenciosamente.

---

# 32. IMPLEMENTACIÓN POR FASES

Trabajar en este orden:

## FASE 1
Auditoría.

## FASE 2
Base de datos + roles + sectores + permisos.

## FASE 3
Login + sesiones + autorización.

## FASE 4
CRUD obligatorios.

## FASE 5
Mapa + geocodificación inversa.

## FASE 6
Frontend y permisos visibles.

## FASE 7
Docker.

## FASE 8
30 tests.

## FASE 9
Revisión integral.

---

# 33. DESPUÉS DE CADA FASE

Informar siempre:

```text
ARCHIVOS MODIFICADOS
QUÉ SE CAMBIÓ
POR QUÉ
CÓMO PROBARLO
QUÉ FALTA
RIESGOS O DECISIONES PENDIENTES
```

---

# 34. CRITERIO DE FINALIZACIÓN

La segunda entrega queda lista cuando se pueda demostrar:

- login funcional;
- sesión funcional;
- usuarios persistentes;
- roles;
- sectores;
- permisos;
- CRUD de usuarios;
- CRUD de contenedores;
- CRUD de vehículos;
- CRUD de centros de acopio;
- CRUD de maquinaria;
- endpoints funcionando;
- base MySQL/MariaDB persistente;
- autorización real en backend;
- mapa funcionando con más de 11.000 contenedores;
- geocodificación inversa bajo demanda;
- incidencias funcionando;
- Docker funcionando;
- datos de prueba;
- mínimo 30 tests;
- documentación de ejecución.

---

# 35. IDENTIDAD DEL PRODUCTO

El producto se llama:

```text
Zemyna
```

La empresa es:

```text
Zentrax SAS
```

No utilizar `SiGeRU` como nombre visible del producto salvo cuando sea necesario mencionar la consigna original.

---

# 36. INSTRUCCIÓN INICIAL

Comenzá ahora realizando únicamente la:

# AUDITORÍA COMPLETA

Todavía NO realices cambios estructurales.

Primero devolvé:

1. Estado actual del proyecto.
2. Requisitos de segunda entrega ya cumplidos.
3. Requisitos parcialmente cumplidos.
4. Requisitos faltantes.
5. Inconsistencias encontradas.
6. Archivos involucrados.
7. Prioridades.
8. Plan de implementación por fases.
9. Riesgos.
10. Decisiones que necesiten confirmación.

Luego esperá autorización antes de modificar estructura de base de datos, roles o relaciones principales.
