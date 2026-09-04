<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../controllers/IncidenciaController.php';

class CrudValidationTest extends TestCase
{
    private IncidenciaController $incidenciaController;

    protected function setUp(): void
    {
        $this->incidenciaController = new IncidenciaController(null);
        $incidenciaProperty = new ReflectionProperty(IncidenciaController::class, 'incidencia');
        $incidenciaProperty->setAccessible(true);
        $incidenciaProperty->setValue($this->incidenciaController, new class {
            public $id_incidencia = 55;
            public function create() { return true; }
            public function update() { return true; }
        });
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

}
