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

- PHP 8
- MySQL 8
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

- Apache o un servidor compatible con PHP 8.
- MySQL 8.
- Extensión PDO MySQL habilitada.
- XAMPP, Laragon o un entorno equivalente.

### Pasos básicos

1. Clonar el repositorio dentro de `htdocs`, `www` o la carpeta pública del servidor.
2. Crear la base ejecutando:

   ```text
   03-proyecto-zemyna/programacion/base-datos/database/sql/schema.sql
   ```

3. Para cargar información de prueba, ejecutar después:

   ```text
   03-proyecto-zemyna/programacion/base-datos/database/sql/init.sql
   ```

4. Copiar el archivo `.env.example` con el nombre `.env`.
5. Completar en `.env` el nombre de la base, el usuario y la contraseña de MySQL.
6. Abrir en el navegador:

   ```text
   03-proyecto-zemyna/programacion/frontend/public/landing.html
   ```

Las migraciones de la carpeta SQL se utilizan solamente para actualizar instalaciones anteriores. Para una instalación desde cero se debe comenzar con `schema.sql`.

## APIs

Las APIs están dentro de `backend/api/`. Entre los recursos disponibles se encuentran:

- usuarios;
- vecinos;
- contenedores;
- vehículos;
- rutas;
- cuadrillas;
- centros de acopio y vertederos;
- incidencias y reclamos;
- solicitudes de retiro;
- tipos de residuo.

Las peticiones y respuestas utilizan JSON. La lista de endpoints y métodos admitidos se encuentra en el README de la carpeta `backend`.

## Estado actual

En esta etapa ya están integrados el login con la base de datos, la estructura MVC, los principales CRUD, la normalización inicial de roles y el seguimiento público de incidencias.

Todavía estamos trabajando en ampliar los permisos por tipo de usuario, completar las pruebas, mejorar el seguimiento de solicitudes y terminar de integrar algunos flujos operativos.

## Equipo

- **Facundo Bustamante:** coordinación, programación, integración, instalación y despliegue.
- **Andrea Romero:** análisis, subcoordinación, arquitectura, redes, experiencia e identidad visual.
- **Diego Gómez:** datos, seguridad, soporte e integración.

## Empresa desarrolladora

**Zentrax SAS**  
Montevideo, Uruguay — 2026

