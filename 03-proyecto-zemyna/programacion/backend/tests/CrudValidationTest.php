<?php
require_once __DIR__ . '/../controllers/UsuarioController.php';
require_once __DIR__ . '/../controllers/ContenedorController.php';
require_once __DIR__ . '/../controllers/IncidenciaController.php';

function assertCrud(bool $condition, string $message): void {
    if ($condition) {
        echo "PASS: $message\n";
        return;
    }

    echo "FAIL: $message\n";
    exit(1);
}

$usuarioController = new UsuarioController(null);
$usuarioProp = new ReflectionProperty(UsuarioController::class, 'usuario');
$usuarioProp->setAccessible(true);
$usuarioProp->setValue($usuarioController, new class {
    public function findByEmail($email) {
        return ['id_usuario' => 999, 'email' => $email];
    }
    public function create() { return true; }
    public function update() { return true; }
    public function delete() { return true; }
    public function activar() { return true; }
    public function read() { return null; }
    public function getRolesVigentes($id) { return []; }
    public function getHistorialRoles($id) { return []; }
});

$result = $usuarioController->create([
    'nombre' => 'Ana',
    'apellido' => 'Pereyra',
    'email' => 'duplicado@zemyna.com',
    'contrasena' => 'secret123',
    'telefono' => '099123456',
    'id_centro' => 1,
    'activo' => 'Activo'
]);
assertCrud(($result['success'] ?? false) === false && ($result['statusCode'] ?? null) === 409, 'Usuario duplica email y responde 409');

$contenedorController = new ContenedorController(null);
$contenedorProp = new ReflectionProperty(ContenedorController::class, 'contenedor');
$contenedorProp->setAccessible(true);
$contenedorProp->setValue($contenedorController, new class {
    public function findByCodigo($codigo) {
        return ['id_contenedor' => 77, 'codigo' => $codigo];
    }
    public function create() { return true; }
    public function update() { return true; }
    public function delete() { return true; }
    public function read() { return null; }
});

$resultContenedor = $contenedorController->create([
    'codigo' => 'CTN-DUP',
    'capacidad' => 2000,
    'direccion' => 'Av. Ejemplo 123',
    'latitud' => -34.90,
    'longitud' => -56.15,
    'estado' => 'Disponible',
    'id_tipo_residuo' => 1,
    'id_ruta' => 1,
]);
assertCrud(($resultContenedor['success'] ?? false) === false && ($resultContenedor['statusCode'] ?? null) === 409, 'Contenedor duplica código y responde 409');

$incidenciaController = new IncidenciaController(null);
$incidenciaProp = new ReflectionProperty(IncidenciaController::class, 'incidencia');
$incidenciaProp->setAccessible(true);
$incidenciaProp->setValue($incidenciaController, new class {
    public function update() { return true; }
});

$resultIncidencia = $incidenciaController->update([
    'id_incidencia' => 10,
    'descripcion' => 'Contenedor con tapa dañada',
    'fecha_reporte' => '2026-08-20 10:00:00',
    'estado' => 'Pendiente',
    'prioridad' => 'Media',
    'tipo_problema' => 'Contenedor Roto/Dañado',
    'id_contenedor' => 1,
]);
assertCrud(($resultIncidencia['success'] ?? false) === true && ($resultIncidencia['statusCode'] ?? null) === 200, 'Incidencia actualiza sin exigir relaciones opcionales');

$resultFecha = $incidenciaController->create([
    'descripcion' => 'Fecha inválida',
    'fecha_reporte' => 'fecha-invalida',
    'estado' => 'Pendiente',
    'prioridad' => 'Media',
    'tipo_problema' => 'Contenedor Desbordado',
    'id_contenedor' => 1,
]);
assertCrud(($resultFecha['success'] ?? false) === false && ($resultFecha['statusCode'] ?? null) === 400, 'Incidencia rechaza fecha de reporte inválida');

echo "SUMMARY: CRUD validation checks passed\n";
