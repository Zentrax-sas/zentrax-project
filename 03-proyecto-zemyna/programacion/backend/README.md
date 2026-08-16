# Backend Zemyna

## Endpoints principales

| Recurso | Método | Ruta | Descripción |
| --- | --- | --- | --- |
| Usuarios | GET | /api/usuarios.php | Lista usuarios |
| Usuarios | POST | /api/usuarios.php | Crea un usuario |
| Usuarios | PUT | /api/usuarios.php | Actualiza un usuario |
| Usuarios | DELETE | /api/usuarios.php | Elimina un usuario |
| Vecinos | GET | /api/vecinos.php | Lista vecinos |
| Vecinos | POST | /api/vecinos.php | Crea un vecino |
| Vecinos | PUT | /api/vecinos.php | Actualiza un vecino |
| Vecinos | DELETE | /api/vecinos.php | Elimina un vecino |
| Tipos de residuo | GET | /api/tipos_residuo.php | Lista tipos de residuo |
| Tipos de residuo | POST | /api/tipos_residuo.php | Crea un tipo de residuo |
| Tipos de residuo | PUT | /api/tipos_residuo.php | Actualiza un tipo de residuo |
| Tipos de residuo | DELETE | /api/tipos_residuo.php | Elimina un tipo de residuo |
| Rutas | GET | /api/rutas.php | Lista rutas |
| Rutas | POST | /api/rutas.php | Crea una ruta |
| Rutas | PUT | /api/rutas.php | Actualiza una ruta |
| Rutas | DELETE | /api/rutas.php | Elimina una ruta |
| Centros | GET | /api/centros.php | Lista centros |
| Centros | POST | /api/centros.php | Crea un centro |
| Centros | PUT | /api/centros.php | Actualiza un centro |
| Centros | DELETE | /api/centros.php | Elimina un centro |
| Acopios | GET | /api/acopios.php | Lista centros de acopio |
| Acopios | POST | /api/acopios.php | Crea un acopio |
| Acopios | PUT | /api/acopios.php | Actualiza un acopio |
| Acopios | DELETE | /api/acopios.php | Elimina un acopio |
| Vertederos | GET | /api/vertederos.php | Lista vertederos |
| Vertederos | POST | /api/vertederos.php | Crea un vertedero |
| Vertederos | PUT | /api/vertederos.php | Actualiza un vertedero |
| Vertederos | DELETE | /api/vertederos.php | Elimina un vertedero |
| Contenedores | GET | /api/contenedores.php | Lista contenedores |
| Contenedores | POST | /api/contenedores.php | Crea un contenedor |
| Contenedores | PUT | /api/contenedores.php | Actualiza un contenedor |
| Contenedores | DELETE | /api/contenedores.php | Elimina un contenedor |
| Vehículos | GET | /api/vehiculos.php | Lista vehículos |
| Vehículos | POST | /api/vehiculos.php | Crea un vehículo |
| Vehículos | PUT | /api/vehiculos.php | Actualiza un vehículo |
| Vehículos | DELETE | /api/vehiculos.php | Elimina un vehículo |
| Cuadrillas | GET | /api/cuadrillas.php | Lista cuadrillas |
| Cuadrillas | POST | /api/cuadrillas.php | Crea una cuadrilla |
| Cuadrillas | PUT | /api/cuadrillas.php | Actualiza una cuadrilla |
| Cuadrillas | DELETE | /api/cuadrillas.php | Elimina una cuadrilla |
| Maquinaria | GET | /api/maquinaria.php | Lista maquinaria |
| Maquinaria | POST | /api/maquinaria.php | Crea maquinaria |
| Maquinaria | PUT | /api/maquinaria.php | Actualiza maquinaria |
| Maquinaria | DELETE | /api/maquinaria.php | Elimina maquinaria |
| Incidencias | GET | /api/incidencias.php | Lista incidencias |
| Incidencias | POST | /api/incidencias.php | Crea una incidencia |
| Incidencias | PUT | /api/incidencias.php | Actualiza una incidencia |
| Incidencias | DELETE | /api/incidencias.php | Elimina una incidencia |
| Reclamos | GET | /api/reclamos.php | Lista reclamos |
| Reclamos | POST | /api/reclamos.php | Crea un reclamo |
| Reclamos | PUT | /api/reclamos.php | Actualiza un reclamo |
| Reclamos | DELETE | /api/reclamos.php | Elimina un reclamo |
| Fotos | GET | /api/fotos.php | Lista fotos de incidencias |
| Fotos | POST | /api/fotos.php | Crea una foto |
| Fotos | PUT | /api/fotos.php | Actualiza una foto |
| Fotos | DELETE | /api/fotos.php | Elimina una foto |
| Solicitudes | GET | /api/solicitud.php | Lista solicitudes |
| Solicitudes | POST | /api/solicitud.php | Crea una solicitud |
| Solicitudes | PUT | /api/solicitud.php | Actualiza una solicitud |
| Solicitudes | DELETE | /api/solicitud.php | Elimina una solicitud |
| Usa (N:M) | GET | /api/usa.php | Lista asociaciones cuadrilla-vehículo (filtrable por query param) |
| Usa (N:M) | POST | /api/usa.php | Crea asociación cuadrilla-vehículo |
| Usa (N:M) | DELETE | /api/usa.php | Elimina asociación cuadrilla-vehículo |
| Recorre (N:M) | GET | /api/recorre.php | Lista asociaciones vehículo-ruta (filtrable por query param) |
| Recorre (N:M) | POST | /api/recorre.php | Crea asociación vehículo-ruta |
| Recorre (N:M) | DELETE | /api/recorre.php | Elimina asociación vehículo-ruta |

## Configuración de entorno

Este backend no usa credenciales hardcodeadas ni contraseñas en el repositorio. Copiá el archivo de ejemplo:

```bash
cp .env.example .env
```

Luego completá los valores reales de conexión en `.env`:

```env
DB_HOST=localhost
DB_NAME=zemyna
DB_USER=root
DB_PASS=
```

Las credenciales de usuarios demo se cargan desde `base-datos/database/sql/init.sql` con contraseñas hasheadas, y no deben exponerse en texto plano en este README.

## Base de datos y migraciones

Para una instalación nueva, ejecutar `base-datos/database/sql/schema.sql` y luego
`base-datos/database/sql/init.sql` si se necesitan datos demo.

La migración `base-datos/database/sql/migration_v2_roles_normalizados.sql` es solo
para entornos de desarrollo que todavía usan el schema intermedio con `usuario.rol`.
Normaliza los roles en `rol`/`usuario_rol` y crea `mantenimiento`; no debe ejecutarse
en una instalación nueva.

La migración anterior está archivada como
`base-datos/database/sql/historico/migration_der_oficial_OBSOLETA.sql` y no debe
ejecutarse.
