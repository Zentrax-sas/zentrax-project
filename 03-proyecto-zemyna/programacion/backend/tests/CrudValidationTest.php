<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../controllers/ContenedorController.php';
require_once __DIR__ . '/../controllers/IncidenciaController.php';

class CrudValidationTest extends TestCase
{
    private ContenedorController $contenedorController;
    private IncidenciaController $incidenciaController;

    protected function setUp(): void
    {
        $this->contenedorController = new ContenedorController(null);
        $contenedorProperty = new ReflectionProperty(ContenedorController::class, 'contenedor');
        $contenedorProperty->setAccessible(true);
        $contenedorProperty->setValue($this->contenedorController, new class {
            public function findByCodigo($codigo) { return ['id_contenedor' => 77, 'codigo' => $codigo]; }
            public function create() { return true; }
            public function update() { return true; }
            public function delete() { return true; }
            public function read() { return null; }
        });

        $this->incidenciaController = new IncidenciaController(null);
        $incidenciaProperty = new ReflectionProperty(IncidenciaController::class, 'incidencia');
        $incidenciaProperty->setAccessible(true);
        $incidenciaProperty->setValue($this->incidenciaController, new class {
            public $id_incidencia = 55;
            public function create() { return true; }
            public function update() { return true; }
        });
    }

    public function testContenedorDuplicadoRespondeConflict(): void
    {
        $result = $this->contenedorController->create([
            'codigo' => 'CTN-DUP', 'capacidad' => 2000,
            'direccion' => 'Av. Ejemplo 123', 'latitud' => -34.90,
            'longitud' => -56.15, 'estado' => 'Disponible',
            'id_tipo_residuo' => 1, 'id_ruta' => 1,
        ]);

        $this->assertTrue(($result['success'] ?? false) === false && ($result['statusCode'] ?? null) === 409);
    }

    public function testIncidenciaActualizaSinRelacionesOpcionales(): void
    {
        $result = $this->incidenciaController->update([
            'id_incidencia' => 10, 'descripcion' => 'Contenedor con tapa dañada',
            'fecha_reporte' => '2026-08-20 10:00:00', 'estado' => 'Pendiente',
            'prioridad' => 'Media', 'tipo_problema' => 'Contenedor Roto/Dañado',
            'id_contenedor' => 1,
        ]);

        $this->assertTrue(($result['success'] ?? false) === true && ($result['statusCode'] ?? null) === 200);
    }

    public function testIncidenciaNuevaDevuelveNumeroDeSeguimiento(): void
    {
        $result = $this->incidenciaController->create([
            'descripcion' => 'Contenedor desbordado',
            'tipo_problema' => 'Contenedor Desbordado',
            'id_contenedor' => 1,
        ]);

        $this->assertTrue(
            ($result['success'] ?? false) === true
            && preg_match('/^INC-\d{4}-[A-F0-9]{5}$/', $result['data']['tracking_number'] ?? '') === 1
        );
    }

    public function testIncidenciaRechazaFechaInvalida(): void
    {
        $result = $this->incidenciaController->create([
            'descripcion' => 'Fecha inválida', 'fecha_reporte' => 'fecha-invalida',
            'estado' => 'Pendiente', 'prioridad' => 'Media',
            'tipo_problema' => 'Contenedor Desbordado', 'id_contenedor' => 1,
        ]);

        $this->assertTrue(($result['success'] ?? false) === false && ($result['statusCode'] ?? null) === 400);
    }

    public function testContenedorSinConexionNoRetornaDatosDemo(): void
    {
        $result = $this->contenedorController->getAll();

        $this->assertTrue(($result['success'] ?? false) === false && ($result['statusCode'] ?? null) === 500);
    }
}
