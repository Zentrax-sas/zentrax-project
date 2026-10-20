<?php
require_once __DIR__ . '/../models/Novedad.php';

class NovedadController {
    private Novedad $model;
    public function __construct($db) { $this->model = new Novedad($db); }

    public function listar(): array {
        try {
            $rows = $this->model->readPublic();
        } catch (PDOException | PersistenceException $exception) {
            return ['success' => false, 'data' => [], 'message' => 'No se pudieron cargar las novedades.', 'statusCode' => 500];
        }
        $titulos = [
            'Contenedor Desbordado' => 'Reporte de contenedor desbordado',
            'Contenedor Roto/Dañado' => 'Reporte de contenedor roto o dañado',
            'Obstruido por Vehículo' => 'Reporte de contenedor obstruido por vehículo',
            'Incendio/Vandalismo' => 'Reporte de incendio o vandalismo en contenedor',
        ];
        $data = [];
        foreach ($rows as $row) {
            // No publicar texto libre de tipo_problema ni estados desconocidos.
            if (!in_array($row['estado'], ['Pendiente', 'En Proceso', 'Resuelta'], true)) continue;
            $data[] = [
                'id' => (int)$row['id'],
                'tipo' => 'Incidencia',
                'titulo' => $titulos[$row['tipo_problema']] ?? 'Incidencia reportada en contenedor',
                'fecha' => $row['fecha'],
                'direccion' => $row['direccion'],
                'estado' => $row['estado'],
            ];
        }
        return ['success' => true, 'data' => $data, 'message' => 'Novedades consultadas correctamente.', 'statusCode' => 200];
    }
}
