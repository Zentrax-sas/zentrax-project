# PROMPT PARA IA DE VSCODE (Copilot / Claude Code / Cursor, etc.)

Pegar todo el bloque de abajo tal cual, dentro del proyecto abierto en VSCode (para que la IA tenga acceso a los archivos reales del repo).

---

## CONTEXTO DEL PROYECTO

Estoy desarrollando el backend de **Zemyna**, una app web de recolección de residuos urbanos. El stack es **PHP puro (sin framework)**, con MySQL 8, siguiendo este patrón de arquitectura por capas:

```
backend/
  api/                  -> Un archivo por entidad. Es el punto de entrada HTTP (front controller manual).
  controllers/          -> Un Controller por entidad. Orquesta Model <-> respuesta JSON.
  models/               -> Un Model por entidad. Encapsula acceso a datos (PDO) y validación básica.
  config/database.php   -> Conexión PDO centralizada.
base-datos/database/sql/
  schema.sql            -> DDL de todas las tablas.
  init.sql               -> INSERTs de datos de ejemplo/demo.
```

### Patrón exacto que ya está implementado (usar como plantilla obligatoria)

**1) Model** (`backend/models/Contenedor.php`), sigue esta forma:
- Clase con `private $conn`, `private string $table_name`, y propiedades públicas = columnas de la tabla.
- Constructor recibe `$db` (conexión PDO) y lo guarda en `$conn`.
- Método `read()`: hace `SELECT * FROM tabla`, prepara y ejecuta con PDO, devuelve el `$stmt` (o `null` si no hay conexión).
- Método `create()`: valida que los campos obligatorios no estén vacíos. **Debe ejecutar el INSERT real usando `$conn->prepare()` + `bindParam()` + `execute()`**, no solo devolver `true`.
- Método `update()`: arma el `UPDATE ... SET ... WHERE pk=:pk`, con `bindParam()` de cada campo y `execute()` real.
- Método `delete()`: arma el `DELETE FROM tabla WHERE pk=:pk`, con `bindParam()` y `execute()` real.
- Todos los métodos deben funcionar de forma segura si `$conn` es `null` (devolver `false`/`null` sin romper), para no perder el modo "mock" que ya usa el proyecto cuando no hay base de datos conectada todavía.

**2) Controller** (`backend/controllers/ContenedorController.php`), sigue esta forma:
- Constructor recibe `$db` e instancia el Model correspondiente.
- `getAll()`: llama a `Model->read()`, si hay `$stmt` devuelve `["success"=>true, "data"=>..., "message"=>"..."]`; si no, devuelve datos mock de ejemplo (2 a 5 registros representativos) con el mismo formato de respuesta.
- `create($data)`: mapea `$data['campo'] ?? valor_default_o_null` a las propiedades del Model, llama a `Model->create()`, devuelve `["success"=>bool, "data"=>null, "message"=>"...", "errors"=>[...]]` en caso de error.
- `update($data)`: igual que `create` pero incluye el id y llama a `Model->update()`.
- `delete($id)`: asigna el id al Model y llama a `Model->delete()`.
- Los mensajes de éxito/error están en español, tono amigable, mencionan el nombre de la entidad.

**3) API endpoint** (`backend/api/contenedores.php`), sigue esta forma EXACTA (calcarla, cambiando solo nombre de clase/entidad):
```php
<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type");

require_once __DIR__ . '/../controllers/<Entidad>Controller.php';
require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();
$controller = new <Entidad>Controller($db);

$method = $_SERVER["REQUEST_METHOD"];

switch ($method) {
    case "GET":
        $response = $controller->getAll();
        http_response_code(200);
        echo json_encode($response);
        break;

    case "POST":
        $data = json_decode(file_get_contents("php://input"), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "JSON inválido.", "errors" => [json_last_error_msg()]]);
            break;
        }
        $response = $controller->create($data ?? []);
        http_response_code($response['success'] ? 201 : 400);
        echo json_encode($response);
        break;

    case "PUT":
        $data = json_decode(file_get_contents("php://input"), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "JSON inválido.", "errors" => [json_last_error_msg()]]);
            break;
        }
        $response = $controller->update($data ?? []);
        http_response_code($response['success'] ? 200 : 400);
        echo json_encode($response);
        break;

    case "DELETE":
        $data = json_decode(file_get_contents("php://input"), true) ?? [];
        if (!isset($data['id_<pk>'])) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Falta id_<pk> en el cuerpo de la petición."]);
            break;
        }
        $response = $controller->delete($data['id_<pk>']);
        http_response_code($response['success'] ? 200 : 400);
        echo json_encode($response);
        break;

    default:
        http_response_code(405);
        echo json_encode(["success" => false, "message" => "Método no permitido."]);
        break;
}
```

> Nota: hoy `$db = null;` está hardcodeado en los api/*.php existentes. Como parte de esta tarea, **reemplazar eso por la conexión real vía `config/database.php`** (ver tarea 3 más abajo) en TODOS los archivos api/*.php, tanto los nuevos como los 4 ya existentes (`usuarios.php`, `contenedores.php`, `camiones.php`, `solicitud.php`).

---

## TAREA 1: Migrar el schema de base de datos al modelo entidad-relación oficial

El `schema.sql` actual sólo tiene 5 tablas simplificadas (`rol`, `usuario`, `contenedor`, `camion`, `incidencia`) y **no coincide** con el Modelo Entidad-Relación oficial del proyecto (documento `ZTX-DOC-ISW-001` y `ZTX-DOC-ISW-003`, adjuntos abajo). Reemplazar completamente `base-datos/database/sql/schema.sql` para reflejar fielmente las siguientes 17 tablas, sus tipos de dato, PKs, FKs y restricciones:

```
VECINO (ci CHAR(8) PK, nombre VARCHAR(50) NOT NULL, apellido VARCHAR(50) NOT NULL, telefono VARCHAR(50) NOT NULL)

USUARIO (id_usuario INT PK AUTO_INCREMENT, nombre VARCHAR(50) NOT NULL, apellido VARCHAR(50) NOT NULL,
  email VARCHAR(100) NOT NULL UNIQUE, contraseña VARCHAR(255) NOT NULL, telefono VARCHAR(20) NOT NULL,
  fecha_registro DATE NOT NULL, rol ENUM('Administrador','Operario') NOT NULL,
  id_centro INT FK NOT NULL -> CENTRO(id_centro))

RECLAMO (id_reclamo INT PK AUTO_INCREMENT, fecha DATETIME NOT NULL, descripcion TEXT NOT NULL,
  estado ENUM('Pendiente','En Proceso','Resuelto') NOT NULL,
  ci CHAR(8) FK NOT NULL -> VECINO(ci), id_incidencia INT FK NOT NULL -> INCIDENCIA(id_incidencia))

INCIDENCIA (id_incidencia INT PK AUTO_INCREMENT, descripcion TEXT NOT NULL, fecha_reporte DATETIME NOT NULL,
  estado ENUM('Pendiente','En Proceso','Resuelta') NOT NULL, prioridad ENUM('Baja','Media','Alta') NOT NULL,
  id_contenedor INT FK NOT NULL -> CONTENEDOR(id_contenedor), id_cuadrilla INT FK NOT NULL -> CUADRILLA(id_cuadrilla),
  id_usuario INT FK NOT NULL -> USUARIO(id_usuario), tipo_problema VARCHAR(50) NOT NULL)

FOTO (id_foto INT PK AUTO_INCREMENT, fecha DATETIME NOT NULL, url VARCHAR(255) NOT NULL,
  id_incidencia INT FK NOT NULL -> INCIDENCIA(id_incidencia))

CONTENEDOR (id_contenedor INT PK AUTO_INCREMENT, codigo VARCHAR(20) NOT NULL UNIQUE, capacidad INT NOT NULL,
  direccion VARCHAR(150) NOT NULL, latitud DECIMAL(10,7) NOT NULL, longitud DECIMAL(10,7) NOT NULL,
  estado ENUM('Disponible','Lleno','Dañado','Fuera de Servicio') NOT NULL,
  id_tipo_residuo INT FK NOT NULL -> TIPO_RESIDUO(id_tipo_residuo), id_ruta INT FK NOT NULL -> RUTA(id_ruta))

TIPO_RESIDUO (id_tipo_residuo INT PK AUTO_INCREMENT, nombre VARCHAR(50) NOT NULL, descripcion VARCHAR(150) NOT NULL)
  -- valores esperados: Orgánico, Papel y cartón, Plástico, Vidrio, Metal, Electrónicos, Pilas y baterías, Escombros, Residuos voluminosos

RUTA (id_ruta INT PK AUTO_INCREMENT, nombre VARCHAR(50) NOT NULL, zona VARCHAR(100) NOT NULL)

VEHICULO (id_vehiculo INT PK AUTO_INCREMENT, matricula VARCHAR(10) NOT NULL UNIQUE, marca VARCHAR(50) NOT NULL,
  modelo VARCHAR(50) NOT NULL, capacidad_carga DECIMAL(8,2) NOT NULL,
  estado ENUM('Disponible','En Servicio','En Mantenimiento') NOT NULL,
  id_tipo_residuo INT FK NOT NULL -> TIPO_RESIDUO(id_tipo_residuo))

CUADRILLA (id_cuadrilla INT PK AUTO_INCREMENT, nombre VARCHAR(50) NOT NULL,
  turno ENUM('Matutino','Vespertino','Nocturno') NOT NULL, id_centro INT FK NOT NULL -> CENTRO(id_centro))

CENTRO (id_centro INT PK AUTO_INCREMENT, nombre VARCHAR(100) NOT NULL, direccion VARCHAR(100) NOT NULL,
  telefono VARCHAR(20) NOT NULL)

ACOPIO (id_centro INT PK FK -> CENTRO(id_centro), horario_atencion VARCHAR(100) NOT NULL)
  -- especialización de CENTRO (herencia total/exclusiva junto con VERTEDERO)

VERTEDERO (id_centro INT PK FK -> CENTRO(id_centro), capacidad_maxima DECIMAL(10,2) NOT NULL)
  -- especialización de CENTRO (herencia total/exclusiva junto con ACOPIO)

MAQUINARIA (id_maquinaria INT PK AUTO_INCREMENT, nombre VARCHAR(50) NOT NULL, tipo VARCHAR(50) NOT NULL,
  estado ENUM('Disponible','En Uso','En Mantenimiento') NOT NULL, id_centro INT FK NOT NULL -> CENTRO(id_centro))

USA (id_cuadrilla INT PK FK -> CUADRILLA(id_cuadrilla), id_vehiculo INT PK FK -> VEHICULO(id_vehiculo))
  -- tabla de relación N:M entre CUADRILLA y VEHICULO

RECORRE (id_vehiculo INT PK FK -> VEHICULO(id_vehiculo), id_ruta INT PK FK -> RUTA(id_ruta))
  -- tabla de relación N:M entre VEHICULO y RUTA

SOLICITUD (id_solicitud INT PK AUTO_INCREMENT, fecha DATETIME NOT NULL, descripcion TEXT NOT NULL,
  direccion VARCHAR(150) NOT NULL, estado ENUM('Pendiente','Programada','Finalizada','Cancelada') NOT NULL,
  ci CHAR(8) FK NOT NULL -> VECINO(ci), id_tipo_residuo INT FK NOT NULL -> TIPO_RESIDUO(id_tipo_residuo),
  email VARCHAR(100) NOT NULL, telefono VARCHAR(20) NOT NULL, tipo_solicitud VARCHAR(50) NOT NULL)
```

Reglas de negocio (RNE) a respetar como `CHECK` constraints o comentarios donde MySQL no las soporte nativamente (por ejemplo, "un Operario debe pertenecer a una única cuadrilla" no es un CHECK de columna, dejarlo documentado como comentario SQL):
- RNE-01, RNE-02: `matricula` y `codigo` únicos (ya cubierto con UNIQUE arriba).
- RNE-21: todo CENTRO debe ser Acopio O Vertedero (especialización total y exclusiva) — documentar con comentario, ya que MySQL no soporta constraint de herencia nativamente.
- RNE-24 a RNE-27, RNE-40: usar ENUM en las columnas correspondientes (ya reflejado arriba).
- Actualizar también `base-datos/database/sql/init.sql` con datos de ejemplo (2-3 filas por tabla) coherentes con las FKs, y `dump_estructura.sql` agregando un `SHOW CREATE TABLE` por cada tabla nueva.

**Importante**: los datos de `usuario` y `contenedor` ya existentes deben migrarse al nuevo esquema (renombrar columnas: `capacidad_litros`→`capacidad`, `estado_llenado` se elimina y se absorbe en el nuevo `estado`, `municipio` se elimina, agregar `id_tipo_residuo`/`id_ruta`, etc.). Mantené backward-compat solo si es trivial; si no, priorizá que el schema quede fiel al DER oficial.

---

## TAREA 2: Generar Model + Controller + API para cada entidad faltante

Ya existen (dejar como referencia de patrón, pero ADAPTAR sus campos al nuevo schema del DER en la Tarea 1): `Usuario`, `Contenedor`, `Camion` (pasa a llamarse `Vehiculo` según el DER), `Solicitud`.

Generar el set completo (Model + Controller + api/*.php) siguiendo EXACTAMENTE el patrón descripto arriba, para cada una de estas entidades nuevas:

1. **Vecino** (PK no es autoincremental, es `ci` — ajustar el patrón de create/update/delete para usar `ci` como identificador en vez de un id numérico)
2. **Reclamo**
3. **Incidencia**
4. **Foto**
5. **TipoResiduo** (tabla `tipo_residuo`)
6. **Ruta**
7. **Cuadrilla**
8. **Centro** (clase base)
9. **Acopio** (extiende/relaciona con Centro vía `id_centro` como PK+FK)
10. **Vertedero** (extiende/relaciona con Centro vía `id_centro` como PK+FK)
11. **Maquinaria**

Para las entidades con **relación N:M pura** (`Usa` entre Cuadrilla-Vehiculo, `Recorre` entre Vehiculo-Ruta), generar Model + Controller + API con operaciones simplificadas: `create()` (asociar), `delete()` (desasociar) y `read()` filtrable por uno de los dos ids (ej. `GET /api/usa.php?id_cuadrilla=1` devuelve los vehículos de esa cuadrilla).

Para las entidades ya existentes que hay que ADAPTAR al nuevo schema:
- **Usuario**: agregar `apellido`, `telefono`, `fecha_registro`, `rol` (enum), `id_centro`.
- **Contenedor**: renombrar/ajustar campos según Tarea 1.
- **Vehiculo** (ex `Camion`): renombrar clase, archivo, tabla y todas las referencias de `Camion`/`camion` a `Vehiculo`/`vehiculo`; ajustar campos a `matricula`, `marca`, `modelo`, `capacidad_carga`, `estado`, `id_tipo_residuo`.
- **Solicitud**: ajustar para usar `id_solicitud` como PK (hoy usa `$id` genérico), agregar `id_tipo_residuo`, `ci` (FK a Vecino), `estado` (enum).

Actualizar `backend/README.md` agregando una fila a la tabla de endpoints por cada API nueva, mismo formato que las filas existentes.

---

## TAREA 3: Completar `config/database.php`

El archivo `backend/config/database.php` está vacío. Crear una clase `Database` con:
- Propiedades privadas `$host`, `$db_name`, `$username`, `$password` (usar `localhost`, `zemyna`, `root`, `""` como defaults, o leer de variables de entorno si el proyecto ya usa `.env`).
- Método público `getConnection()` que devuelve una instancia PDO con `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`, charset `utf8mb4`, envuelto en try/catch que devuelva `null` si falla la conexión (para no romper el modo mock que ya usan los controllers).

---

## REGLAS GENERALES PARA TODA LA GENERACIÓN

- Respetar el idioma español en nombres de tabla/columna (coincidir EXACTO con el DER) pero código (nombres de clases, métodos, variables) en el estilo que ya usa el proyecto (camelCase para métodos, PascalCase para clases).
- No usar ningún framework ni ORM (nada de Doctrine/Eloquent) — el proyecto es PHP plano con PDO.
- Todas las respuestas JSON deben mantener el formato `{"success": bool, "data": ..., "message": "...", "errors": [...]}` ya establecido.
- Todos los mensajes de usuario (`message`, `errors`) en español, con el mismo tono que ya usa el proyecto (mencionan "Zemyna" en mensajes de éxito de creación, ej. "Contenedor urbano registrado con éxito en Zemyna.").
- Generar los archivos de a una entidad por vez y mostrarme el resultado antes de pasar a la siguiente, para poder revisar cada una.
- Al terminar todas las entidades, generar también un archivo `base-datos/database/sql/migration_der_oficial.sql` con los `ALTER TABLE`/`CREATE TABLE` necesarios para pasar del schema viejo al nuevo, por si ya hay una base de datos con datos cargados en el entorno de desarrollo.
