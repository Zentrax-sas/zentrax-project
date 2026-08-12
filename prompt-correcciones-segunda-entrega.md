## CONTEXTO

Backend PHP puro (sin framework) + PDO, en `03-proyecto-zemyna/programacion/backend/`. Frontend HTML/JS vanilla en `03-proyecto-zemyna/programacion/frontend/public/`. La devolución técnica del profesor pide resolver, en este orden de prioridad, los siguientes 6 puntos antes de la entrega. Hacé los cambios **de a un punto por vez** y mostrame el resultado antes de seguir con el próximo.

---

## PUNTO 1: Login real contra la base de datos

Archivo: `backend/api/login.php`.

Hoy compara contra un array `$usuariosDemo` hardcodeado en el propio endpoint, con contraseñas en texto plano. Reemplazar por:
- Consultar la tabla `usuario` por `email` usando el `Model` `Usuario` (agregar un método `findByEmail($email)` a `backend/models/Usuario.php` que haga `SELECT * FROM usuario WHERE email = :email LIMIT 1`).
- Verificar la contraseña con `password_verify($password, $usuario['contraseña'])`.
- **Importante**: hoy `Usuario::create()` guarda `contrasena` tal cual viene del request (columna `contraseña` en texto plano — confirmado en `models/Usuario.php`). Cambiar `UsuarioController::create()`/`update()` para aplicar `password_hash($data['contrasena'], PASSWORD_BCRYPT)` antes de pasarlo al Model, igual que ya se hace en otros proyectos de referencia con `password_hash`.
- Los usuarios demo (`facu@zemyna.com`, etc.) pasan a ser exclusivamente **datos de carga inicial en `init.sql`** (con contraseña ya hasheada), nunca hardcodeados en el endpoint.
- Al loguear correctamente, seguir usando `$_SESSION['usuario']` pero con los datos reales de la fila de la tabla (`id_usuario`, `nombre`, `email`, `rol`, `id_centro`).

## PUNTO 2: Autenticación/autorización por sesión y rol en todas las APIs

Hoy cualquiera puede invocar `GET/POST/PUT/DELETE` en cualquier `api/*.php` sin sesión iniciada. Los roles del sistema son: `Administrador` y `Operario` (según el DER — si en el código/DB ya se usan 4 roles como "director/jefe/administrativo/operario" en vez de esos 2, avisame en la respuesta cuál es el esquema real de roles antes de aplicar el resto de este punto, para no romper nada).

- Crear `backend/helpers/auth.php` con dos funciones:
  - `requireAuth()`: si no hay `$_SESSION['usuario']` activo, corta con `http_response_code(401)` y `{"success":false,"message":"No autenticado."}`.
  - `requireRole($rolesPermitidos)`: llama a `requireAuth()`, y si `$_SESSION['usuario']['rol']` no está en `$rolesPermitidos`, corta con `http_response_code(403)` y `{"success":false,"message":"No tenés permiso para esta acción."}`.
- Aplicar `session_start()` + `require_once` de `auth.php` en **todos** los `api/*.php` (salvo `login.php` y el `GET` de recursos públicos como `contenedores.php`/`solicitud.php` si el frontend público los necesita sin login — confirmame cuáles son públicos antes de bloquearlos todos).
- Reglas mínimas: creación/edición/borrado de `usuarios`, `centros`, `cuadrillas`, `maquinaria`, `vehiculos` → solo `Administrador`. Un `Operario` no debe poder eliminar usuarios ni gestionar otras cuadrillas.

## PUNTO 3: Sacar credenciales y configuración fija del repositorio

- `backend/config/database.php` usa `root` sin contraseña hardcodeado. Cambiar a leer de variables de entorno: `getenv('DB_HOST')`, `getenv('DB_NAME')`, `getenv('DB_USER')`, `getenv('DB_PASS')`, con los valores actuales como fallback solo si la env var no existe.
- Crear `.env.example` en la raíz de `programacion/` con las claves `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` sin valores reales.
- Agregar carga de `.env` (con una función simple de parseo tipo `parse_env.php` en `backend/config/`, sin librerías externas, o `vlucas/phpdotenv` si el proyecto ya usa Composer).
- Crear `.gitignore` en la raíz del repo (`zentrax-project/`) que excluya `.env`, `/03-proyecto-zemyna/programacion/.venv/`, `vendor/`, `node_modules/`, y demás carpetas de entorno.
- `backend/README.md` publica la contraseña demo `123456` en texto plano — reemplazar esa sección por una referencia a `.env.example` y aclarar que las credenciales demo se cargan vía `init.sql`, sin mostrar el valor en texto plano si el hash ya está en la base.

## PUNTO 4: Rutas portables en el frontend

Archivos con ruta fija hardcodeada: `frontend/public/login.html`, `frontend/public/solicitud.html`, `frontend/public/mapa.js` (los tres definen `const APP_BASE_URL = 'http://localhost/zentrax-project/03-proyecto-zemyna/programacion/frontend/public'`).

- Crear un único archivo `frontend/public/config.js` con `const APP_BASE_URL = window.location.origin + '/ruta/relativa/actual';` calculado dinámicamente a partir de `window.location`, o directamente rutas relativas (`./api/...`) si el backend y frontend se sirven desde el mismo host.
- Incluir `config.js` vía `<script src="config.js"></script>` en `login.html`, `solicitud.html`, `admin.html`, `index.html`, `landing.html` (antes de cualquier otro script que use `APP_BASE_URL`), y eliminar la constante duplicada de `mapa.js` para que tome la global.
- El objetivo: que funcione en otra PC, Docker o servidor sin editar ningún archivo.

## PUNTO 5: Base SQL única y oficial

Hoy conviven `base-datos/database/sql/schema.sql`, `init.sql`, `migration_der_oficial.sql`, y además un dump viejo en la raíz del repo (`db/gestion_de_residuos (3).sql`) que todavía usa la tabla `camion` en vez de `vehiculo`.

- Confirmar que `schema.sql` es la fuente de verdad (DDL completo del DER oficial) y que coincide 100% con las columnas que usan los Models actuales (`Vehiculo.php`, `Contenedor.php`, `Usuario.php`, etc. — revisar cada uno).
- Agregar un comentario al principio de cada archivo SQL aclarando su rol: `schema.sql` = "ejecutar para instalación limpia", `init.sql` = "datos demo, ejecutar después de schema.sql", `migration_der_oficial.sql` = "histórico, solo para entornos con datos del schema viejo, no ejecutar en instalación nueva".
- Mover `db/gestion_de_residuos (3).sql` a una carpeta `base-datos/database/sql/historico/` (o eliminarlo si no aporta nada), dejando claro en un comentario que es una versión anterior no vigente y que usa `camion` (nombre viejo).
- Eliminar la duplicación de endpoints de vehículo: hoy existen `api/camiones.php`, `api/vehiculo.php` y `api/vehiculos.php`, los tres apuntando a `VehiculoController`. Dejar solo `api/vehiculos.php` como endpoint oficial, eliminar los otros dos, y actualizar cualquier referencia en el frontend (`admin.html`, `mapa.js`, etc.) que todavía apunte a `camiones.php` o `vehiculo.php`.

---

## SEGUNDA PRIORIDAD (aplicar solo después de los 5 puntos anteriores, si hay tiempo)

Elegí y aplicá las que más impacto/tiempo tengan, en este orden sugerido:

1. **Centralizar CORS y bootstrap**: crear `backend/config/bootstrap.php` con los `header()` de CORS, el manejo de `OPTIONS` (preflight) y el `require_once` de `database.php` + `auth.php`, e incluirlo al inicio de cada `api/*.php` en vez de repetir los mismos 4 `header()` en cada archivo.
2. **Baja lógica en vez de DELETE físico**: agregar columna `activo TINYINT(1) DEFAULT 1` (o `eliminado_en DATETIME NULL`) a `usuario` y otras tablas que necesiten trazabilidad; cambiar `delete()` en esos Models para hacer `UPDATE ... SET activo = 0` en vez de `DELETE FROM`.
3. **Paginación, filtros y búsqueda por ID**: los `GET` actuales hacen `SELECT *` sin filtros. Agregar soporte de query params `?id=`, `?page=`, `?limit=` en los Controllers que más se usan (`Contenedor`, `Incidencia`, `Usuario`).
4. **Validaciones robustas**: en cada Controller, validar no solo presencia de campos sino tipo, longitud máxima y que los valores de `ENUM` (`estado`, `rol`, `prioridad`, etc.) sean válidos antes de pasarlos al Model.
5. **Códigos HTTP diferenciados**: distinguir 400 (datos inválidos), 401 (no autenticado), 403 (sin permiso), 404 (no encontrado), 409 (conflicto, ej. email duplicado), 500 (error de servidor/BD) — hoy varios casos devuelven 400 genérico o degradan a datos mock sin avisar.
6. **Unificar nombres**: `contrasena` (variable/columna) vs `contraseña`, `vehiculo`/`vehiculos`/`camiones` (ya cubierto en Punto 5). Documentar cualquier alias legacy que se decida mantener.
7. **Separar CSS/JS del panel admin**: `frontend/public/admin.html` tiene CSS/JS embebido — extraer a `frontend/public/assets/css/admin.css` y `frontend/public/assets/js/admin.js`.
8. **Evitar `innerHTML` con contenido dinámico** en `admin.html`/`mapa.js` — reemplazar por `textContent` o construcción de elementos con `createElement` para reducir riesgo XSS.
9. **Autoload/bootstrap** para reemplazar los múltiples `require_once` manuales de cada Controller/API por un autoloader simple (`spl_autoload_register`) en `backend/config/bootstrap.php`.
10. Agregar `LICENSE` si corresponde, y ampliar `backend/README.md` con guía de instalación completa (XAMPP/Docker, creación de BD desde `schema.sql` + `init.sql`, cómo correr y probar los endpoints con curl/Postman).

---

## REGLAS GENERALES

- No tocar nada relacionado a testing (`plan-testing.md`, `casos-prueba.md`, `caja-negra.md`, `caja-blanca.md`, `resultados.md`) — se resuelve en otra sesión.
- Mantené el estilo de código y el formato de respuesta JSON (`success`, `data`, `message`, `errors`) ya establecido en el proyecto.
- Cualquier cambio de schema SQL debe reflejarse también en `migration_der_oficial.sql` o en un nuevo script de migración incremental, para no perder datos de desarrollo ya cargados.
- Si alguna corrección requiere una decisión de negocio que no está clara en el DER (ej. qué GET quedan públicos, cuáles son los roles reales), preguntame antes de aplicar el cambio en vez de asumir.
