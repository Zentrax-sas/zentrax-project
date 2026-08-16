# PROMPT PARA IA DE VSCODE — Número de seguimiento real para Incidencias (reporte desde el mapa)

Pegar dentro del proyecto abierto en VSCode. Hacer en orden, mostrando cada archivo antes de pasar al siguiente.

---

## CONTEXTO

`frontend/public/index.html` incluye `mapa.js`, que hace `POST` a `backend/api/incidencias.php` para reportar problemas de contenedores (vecino anónimo o, opcionalmente, operario logueado — ya resuelto en un prompt anterior). El frontend **ya espera** un `tracking_number` en la respuesta:

```js
const tracking = json?.tracking_number || json?.data?.tracking_number || null;
```

Pero `IncidenciaController::create()` nunca lo genera ni lo persiste — hoy ese `tracking` siempre llega `null`. Vamos a replicar exactamente el mismo mecanismo que ya existe en `solicitud` (que si funciona), pero adaptado a `incidencia`. **No se agrega teléfono ni ningún otro dato de contacto** — el número de seguimiento solo es suficiente por sí mismo para consultar el estado después, sin necesidad de loguearse ni dejar datos de contacto.

---

## TAREA 1: Agregar columna `tracking_number` a la tabla `incidencia`

En `base-datos/database/sql/schema.sql`, agregar a `CREATE TABLE incidencia`:
```sql
tracking_number VARCHAR(20) NOT NULL UNIQUE,
```
(ubicarla después de `id_incidencia`, antes de `descripcion`).

Actualizar también:
- `base-datos/database/sql/init.sql`: agregar `tracking_number` a los INSERT de `incidencia` que ya existan, formato `INC-{año}-{5 caracteres alfanuméricos}` (usar el prefijo `INC-` en vez de `REF-` para distinguirlo de los de `solicitud` a simple vista).
- Crear `base-datos/database/sql/migration_tracking_incidencia.sql`: `ALTER TABLE` incremental para un entorno con datos ya cargados, mismo criterio que la migración de `solicitud` (primero un `UPDATE` que rellene `tracking_number` únicos para filas existentes con `CONCAT('INC-MIG-', id_incidencia)`, después aplicar `NOT NULL UNIQUE`). Envolver en `START TRANSACTION` / `COMMIT`.

---

## TAREA 2: Generar y persistir el `tracking_number`

### `backend/models/Incidencia.php`
- Agregar la propiedad pública `$tracking_number`.
- Agregar `tracking_number` al `INSERT` de `create()` (columna + bind param).
- Agregar un método `findByTrackingNumber($trackingNumber)`, mismo estilo que el resto de los `find*` del proyecto:
  ```php
  public function findByTrackingNumber($trackingNumber) {
      if (!$this->conn) return null;
      $query = "SELECT * FROM " . $this->table_name . " WHERE tracking_number = :tracking_number LIMIT 1";
      $stmt = $this->conn->prepare($query);
      $stmt->bindParam(':tracking_number', $trackingNumber);
      $stmt->execute();
      return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  }
  ```

### `backend/controllers/IncidenciaController.php` — `create($data)`
Antes de llamar a `$this->incidencia->create()`, generar el código igual que hace `SolicitudController`:
```php
$year = date('Y');
$randomCode = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
$this->incidencia->tracking_number = "INC-{$year}-{$randomCode}";
```
Y en la respuesta de éxito, devolverlo tanto en `data` como en el nivel superior (para que `mapa.js`, que ya prueba ambas rutas `json?.tracking_number || json?.data?.tracking_number`, lo encuentre sin tener que tocar el frontend):
```php
if ($this->incidencia->create()) {
    return [
        "success" => true,
        "data" => ["tracking_number" => $this->incidencia->tracking_number],
        "message" => "Incidencia registrada con éxito en Zemyna.",
        "tracking_number" => $this->incidencia->tracking_number,
        "errors" => [],
        "statusCode" => 201
    ];
}
```

Agregar también un método `getByTrackingNumber($trackingNumber)`, mismo criterio que el ya creado para `solicitud` en el prompt anterior (exponer solo lo relevante para el vecino, sin datos internos como `id_usuario`/`id_cuadrilla`):
```php
public function getByTrackingNumber($trackingNumber) {
    $row = $this->incidencia->findByTrackingNumber($trackingNumber);
    if (!$row) {
        return ["success" => false, "data" => null, "message" => "No se encontró ninguna incidencia con ese número de seguimiento.", "statusCode" => 404];
    }
    $publico = [
        "tracking_number" => $row['tracking_number'],
        "estado" => $row['estado'],
        "fecha_reporte" => $row['fecha_reporte'],
        "tipo_problema" => $row['tipo_problema'],
    ];
    return ["success" => true, "data" => $publico, "message" => "Incidencia encontrada.", "statusCode" => 200];
}
```

### `backend/api/incidencias.php`
En el `case "GET"`, agregar el mismo patrón de bifurcación que en `solicitud.php` (consulta pública por `tracking_number` vs. listado interno con filtros existentes):
```php
case "GET":
    if (isset($_GET['tracking_number']) && $_GET['tracking_number'] !== '') {
        $response = $controller->getByTrackingNumber($_GET['tracking_number']);
        http_response_code($response['statusCode'] ?? ($response['success'] ? 200 : 404));
        echo json_encode($response);
        break;
    }
    $filters = [
        'id' => isset($_GET['id']) ? $_GET['id'] : null,
        'page' => isset($_GET['page']) ? $_GET['page'] : 1,
        'limit' => isset($_GET['limit']) ? $_GET['limit'] : 20,
    ];
    $response = $controller->getAll($filters);
    http_response_code($response['statusCode'] ?? ($response['success'] ? 200 : 400));
    echo json_encode($response);
    break;
```

---

## TAREA 3: Frontend — mostrar consulta de seguimiento en `index.html`

Agregar una sección chica (puede ser un modal, un acordeón, o una pestaña aparte, lo que mejor encaje con el diseño actual de `index.html`/`mapa.js`) con un campo `tracking_number` y botón "Consultar estado", que haga `GET /api/incidencias.php?tracking_number=...` (usando `APP_BASE_URL` de `config.js`, mismo patrón que el resto de las llamadas de `mapa.js`) y muestre `estado`, `fecha_reporte`, `tipo_problema`. Mostrar mensaje claro si no se encuentra (404). Mismo estilo visual que la consulta de seguimiento que se haya agregado en `solicitud.html` en el prompt anterior, para mantener consistencia entre ambas pantallas públicas.

---

## REGLAS GENERALES

- No agregar ningún campo de contacto (`telefono`, `email`) a `incidencia` — el número de seguimiento alcanza por sí solo.
- Mantené el formato de respuesta JSON (`success`, `data`, `message`, `errors`, `statusCode`) ya usado en el proyecto.
- Nota aparte (no es parte de esta tarea, no lo apliques todavía): el listado completo (`GET` sin `tracking_number`) de `incidencias.php` sigue siendo público hoy — a diferencia de `contenedores.php`/`vehiculos.php`/`vecinos.php`/`solicitud.php` que ya se protegieron en un prompt anterior. Si querés, en otra sesión lo revisamos para decidir si ese listado completo (que expone `id_usuario`/`id_cuadrilla` internos) debería requerir `requireAuth()` igual que los demás.
- Al terminar, probá: reportar una incidencia desde `index.html`/mapa, confirmar que el mensaje de éxito ahora muestra un código real (no `null`), copiarlo, y consultarlo con `GET /api/incidencias.php?tracking_number=ese_codigo` sin sesión iniciada.
