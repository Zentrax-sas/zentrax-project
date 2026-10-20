# Zemyna

Zemyna es una plataforma desarrollada por **Zentrax SAS** para apoyar la gestión de residuos urbanos.

La propuesta reúne en un mismo sistema herramientas para la ciudadanía y para el personal encargado de la gestión. Desde la parte pública se pueden registrar incidencias y solicitudes de retiro. Desde la aplicación interna se administran usuarios, contenedores, vehículos, rutas, cuadrillas y otros recursos relacionados con la recolección.

El proyecto se encuentra en desarrollo y las funciones se van incorporando y probando por etapas.

## Funciones principales

- Registro de incidencias sobre contenedores y residuos.
- Generación de un código para consultar el estado de una incidencia.
- Solicitud de retiro de residuos reciclables o de gran volumen.
- Consulta de contenedores y puntos en el mapa.
- Inicio de sesión para usuarios internos.
- Administración de usuarios, roles y permisos.
- Gestión de contenedores, vehículos, rutas, cuadrillas y centros.
- APIs que reciben y devuelven información en formato JSON.

## Tecnologías utilizadas

- PHP 8.1 o posterior (validación local con PHP 8.1.25)
- MariaDB; imagen prevista para Docker: MariaDB 10.11
- HTML, CSS y JavaScript
- PDO para la conexión con la base de datos
- Leaflet para los mapas
- Rocky Linux como sistema operativo del servidor
- Docker para facilitar la instalación y el despliegue

## Organización del repositorio

El repositorio contiene la documentación de Zentrax y el desarrollo de Zemyna.

```text
01-emprendedurismo-zentrax/   Documentación de la empresa
02-web-zentrax/               Sitio web corporativo de Zentrax
03-proyecto-zemyna/           Desarrollo y documentación de Zemyna
04-docs/                      Documentos generales de apoyo
99-entregas-finales/          Material preparado para presentaciones y entregas
```

El código principal de Zemyna se encuentra en:

```text
03-proyecto-zemyna/programacion/
├── backend/
│   ├── api/                  Endpoints del sistema
│   ├── config/               Conexión y configuración
│   ├── controllers/          Validaciones y lógica de cada operación
│   ├── helpers/              Funciones compartidas
│   └── models/               Consultas y acceso a la base de datos
├── base-datos/
│   └── database/sql/         Esquema, datos iniciales y migraciones
└── frontend/
    ├── public/               Pantallas públicas y administrativas
    └── src/                  JavaScript y estilos
```

## Instalación local

### Requisitos

- Apache o un servidor compatible con PHP 8.1 o posterior.
- MariaDB con el esquema vigente del proyecto. No se acredita aquí una instalación en MySQL 8.
- Extensión PDO MySQL habilitada.
- XAMPP, Laragon o un entorno equivalente.

### Pasos básicos

1. Clonar el repositorio dentro de `htdocs`, `www` o la carpeta pública del servidor.
2. Crear una base vacía con codificación UTF-8. El nombre usado por la
   configuración de desarrollo es `gestion_residuosfinal`:

   ```sql
   CREATE DATABASE gestion_residuosfinal
     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

3. Desde `03-proyecto-zemyna/programacion/base-datos/database/sql/`, ejecutar la
   instalación mínima. El instalador elimina y recrea todas las tablas de la base
   seleccionada, por lo que no debe usarse sobre datos que deban conservarse:

   ```bash
   mysql --abort-source-on-error -u USUARIO -p \
     --database=gestion_residuosfinal < 00_INICIALIZAR.sql
   ```

   `00_INICIALIZAR.sql` ejecuta solamente `schema.sql` e `init.sql`. Las
   migraciones se reservan para actualizar instalaciones antiguas y no se aplican
   después del esquema final.

4. Opcionalmente, cargar una sola vez los 11.211 contenedores abiertos de la
   Intendencia. Sumados a los tres registros demo, quedan 11.214 contenedores:

   ```bash
   mysql --abort-source-on-error -u USUARIO -p \
     --database=gestion_residuosfinal < seed_contenedores_idm.sql
   ```

   Una segunda ejecución del seed se detiene ante las colisiones. No repetir la
   instalación mínima sobre datos existentes para eludirlas; revisar el destino
   y preparar una base descartable nueva si se autoriza una reinstalación.

5. Copiar el archivo `.env.example` con el nombre `.env`.
6. Completar en `.env` el nombre de la base, el usuario y la contraseña de MariaDB.
7. Abrir en el navegador:

   ```text
   03-proyecto-zemyna/programacion/frontend/public/landing.html
   ```

Las migraciones de la carpeta SQL se utilizan solamente para actualizar
instalaciones anteriores. Para una instalación desde cero se debe usar
`00_INICIALIZAR.sql`; no se deben agregar migraciones al final de ese flujo.

Para Docker, el procedimiento oficial es [README-Docker.md](03-proyecto-zemyna/programacion/README-Docker.md)
y `03-proyecto-zemyna/programacion/compose.yaml`, **no** el Compose de la raíz.
La instalación limpia `zemyna_demo_fix_20261019` fue validada por el usuario en
Ubuntu/WSL2: frontend/API, MariaDB, phpMyAdmin, login, mapa y protección HTTP de
archivos internos. Se utilizaron 18080/18081, distintos de los puertos por defecto.
El posterior ajuste del healthcheck a 4m sigue pendiente de validación por ejecución.

## APIs

Las APIs están dentro de `backend/api/`. Entre los recursos disponibles se encuentran:

- usuarios;
- contenedores;
- vehículos;
- rutas;
- cuadrillas;
- centros de acopio y vertederos;
- incidencias y reclamos;
- solicitudes de retiro;
- tipos de residuo.

Las peticiones y respuestas utilizan JSON. `vecinos.php` y `denuncias.php` son API
heredadas retiradas: sus operaciones GET/POST/PUT/DELETE devuelven 410. El registro
público vigente usa incidencias y su seguimiento por tracking.

Contratos: [recolección](03-proyecto-zemyna/programacion/backend/RECOLECCION.md),
[dashboard](03-proyecto-zemyna/programacion/backend/DASHBOARD.md) y
[documentación por módulo](03-proyecto-zemyna/programacion/backend/docs).

## Estado actual

El cierre de Programación incluye login y permisos efectivos sin bypass universal,
CRUD, recolección operativa y mapa, registro/tracking de incidencias, solicitudes
especiales, informes, dashboard con alertas y reglas C1/C2/C3. No implica que todos
los cambios locales ya estén publicados ni que se haya validado concurrencia real.

Ver [guía de demostración](03-proyecto-zemyna/programacion/GUIA-DEMO-PROGRAMACION.md)
e [inventario de cierre](03-proyecto-zemyna/programacion/INVENTARIO-CIERRE-PROGRAMACION.md).
Quedan pendientes la concurrencia MariaDB C1/C2/C3 y la ejecución del ajuste del
healthcheck. El harness experimental no integra la publicación final; consultar
el historial Git para identificar la versión publicada. No se agrega funcionalidad nueva.

## Equipo

- **Facundo Bustamante:** coordinación, programación, integración, instalación y despliegue.
- **Andrea Romero:** análisis, subcoordinación, arquitectura, redes, experiencia e identidad visual.
- **Diego Gómez:** datos, seguridad, soporte e integración.

## Empresa desarrolladora

**Zentrax SAS**  
Montevideo, Uruguay — 2026

