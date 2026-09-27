<?php

/** Agregados globales de Operaciones. Ver DASHBOARD.md para semántica temporal y límites. */
class Dashboard
{
    public function __construct(private PDO $db) {}
    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    private function count(string $sql, array $params = []): int { return (int) $this->rows($sql, $params)[0]['total']; }
    private function range(string $column, ?string $from, ?string $to): array
    {
        // column es una constante interna; los valores del cliente son parámetros.
        $parts = []; $params = [];
        if ($from) { $parts[] = "$column >= ?"; $params[] = "$from 00:00:00"; }
        if ($to) { $parts[] = "$column < ?"; $params[] = (new DateTimeImmutable($to, new DateTimeZone('America/Montevideo')))->modify('+1 day')->format('Y-m-d 00:00:00'); }
        return [$parts ? implode(' AND ', $parts) : '1 = 1', $params];
    }
    public function consultar(?string $from, ?string $to): array
    {
        // Todas las métricas de una respuesta usan una instantánea de lectura coherente.
        $own = !$this->db->inTransaction(); if ($own) $this->db->beginTransaction();
        try { $data = $this->aggregate($from, $to); if ($own) $this->db->commit(); return $data; }
        catch (Throwable $e) { if ($own && $this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    private function aggregate(?string $from, ?string $to): array
    {
        [$reported, $rp] = $this->range('i.fecha_reporte', $from, $to);
        [$resolved, $sp] = $this->range('i.fecha_resolucion', $from, $to);
        [$started, $bp] = $this->range('re.fecha_inicio', $from, $to);
        [$finished, $fp] = $this->range('re.fecha_fin', $from, $to);
        [$attended, $ap] = $this->range('fecha_atencion', $from, $to);
        $active = "i.estado IN ('Pendiente','En Proceso')";
        $operative = "re.estado IN ('Pendiente','En Proceso')";
        $current = [
            'incidencias_activas' => $this->count("SELECT COUNT(*) AS total FROM incidencia i WHERE $active"),
            'recorridos_en_proceso' => $this->count("SELECT COUNT(*) AS total FROM recorrido WHERE estado = 'En Proceso'"),
            // Igual criterio de disponibilidad que proximo(): existencia de relación con una ejecución operativa.
            'cuadrillas_sin_recorrido' => $this->count("SELECT COUNT(*) AS total FROM cuadrilla c WHERE NOT EXISTS
                (SELECT 1 FROM usa u JOIN participa p ON p.id_usa = u.id_usa JOIN recorrido re ON re.id_recorrido = p.id_recorrido WHERE u.id_cuadrilla = c.id_cuadrilla AND $operative)"),
            'contenedores_prioridad_alta' => $this->count("SELECT COUNT(DISTINCT i.id_contenedor) AS total FROM incidencia i WHERE $active AND i.prioridad = 'Alta'")
        ];
        $occupied = "EXISTS (SELECT 1 FROM usa u JOIN participa p ON p.id_usa = u.id_usa JOIN recorrido re ON re.id_recorrido = p.id_recorrido WHERE u.id_vehiculo = v.id_vehiculo AND $operative)";
        $current['camiones_disponibles'] = $this->count("SELECT COUNT(*) AS total FROM vehiculo v WHERE v.activo = 1 AND v.estado = 'Disponible' AND NOT $occupied");
        $current['camiones_no_disponibles'] = $this->count("SELECT COUNT(*) AS total FROM vehiculo v WHERE v.activo = 1 AND (v.estado <> 'Disponible' OR $occupied)");
        $duration = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? '(julianday(i.fecha_resolucion) - julianday(i.fecha_reporte)) * 24.0'
            : 'TIMESTAMPDIFF(SECOND, i.fecha_reporte, i.fecha_resolucion) / 3600.0';
        $avg = $this->rows("SELECT AVG($duration) AS promedio, COUNT(*) AS muestras FROM incidencia i WHERE i.estado = 'Resuelta'
            AND i.fecha_resolucion >= i.fecha_reporte AND $resolved", $sp)[0];
        $results = [
            'incidencias_reportadas' => $this->count("SELECT COUNT(*) AS total FROM incidencia i WHERE $reported", $rp),
            'incidencias_resueltas' => $this->count("SELECT COUNT(*) AS total FROM incidencia i WHERE i.estado = 'Resuelta' AND $resolved", $sp),
            'tiempo_promedio_resolucion_horas' => $avg['promedio'] === null ? null : round((float) $avg['promedio'], 2),
            'resoluciones_con_duracion' => (int) $avg['muestras'],
            'recorridos_finalizados' => $this->count("SELECT COUNT(*) AS total FROM recorrido re WHERE re.estado = 'Finalizado' AND $finished", $fp),
            // Una atención por pareja recorrido/contenedor; un mismo contenedor puede atenderse en varias ejecuciones.
            'contenedores_atendidos' => $this->count("SELECT COUNT(*) AS total FROM (SELECT DISTINCT id_recorrido,id_contenedor FROM atencion_contenedor WHERE $attended) atenciones", $ap)
        ];
        $chart = $this->rows("SELECT i.estado, i.prioridad, COUNT(*) AS cantidad FROM incidencia i WHERE $reported GROUP BY i.estado,i.prioridad ORDER BY i.estado,i.prioridad", $rp);
        foreach ($chart as &$row) $row['cantidad'] = (int) $row['cantidad']; unset($row);
        $top = $this->rows("SELECT c.codigo, c.direccion, COUNT(*) AS cantidad FROM incidencia i JOIN contenedor c ON c.id_contenedor = i.id_contenedor
            WHERE $reported GROUP BY c.id_contenedor,c.codigo,c.direccion ORDER BY cantidad DESC,c.codigo ASC,c.id_contenedor ASC LIMIT 5", $rp);
        foreach ($top as &$row) $row['cantidad'] = (int) $row['cantidad']; unset($row);
        $trips = ['Pendiente' => 0, 'En Proceso' => 0, 'Finalizado' => 0, 'Cancelado' => 0];
        foreach ($this->rows("SELECT re.estado,COUNT(*) AS total FROM recorrido re WHERE $started GROUP BY re.estado", $bp) as $r) $trips[$r['estado']] = (int) $r['total'];
        $high = $this->count("SELECT COUNT(*) AS total FROM incidencia i WHERE $active AND i.prioridad = 'Alta'");
        $repeated = $this->count("SELECT COUNT(*) AS total FROM (SELECT i.id_contenedor FROM incidencia i WHERE $active AND i.id_contenedor IS NOT NULL GROUP BY i.id_contenedor HAVING COUNT(*) >= 3) repetidos");
        $withoutVehicle = $this->count("SELECT COUNT(*) AS total FROM recorrido re WHERE $operative AND NOT EXISTS
            (SELECT 1 FROM participa p JOIN usa u ON u.id_usa = p.id_usa JOIN vehiculo v ON v.id_vehiculo = u.id_vehiculo WHERE p.id_recorrido = re.id_recorrido AND v.activo = 1 AND v.estado <> 'En Mantenimiento')");
        $invalidVehicle = $this->count("SELECT COUNT(DISTINCT v.id_vehiculo) AS total FROM vehiculo v JOIN usa u ON u.id_vehiculo = v.id_vehiculo JOIN participa p ON p.id_usa = u.id_usa JOIN recorrido re ON re.id_recorrido = p.id_recorrido WHERE $operative AND (v.activo = 0 OR v.estado = 'En Mantenimiento')");
        $shared = $this->count("SELECT COUNT(*) AS total FROM (SELECT re.id_recorrido FROM recorrido re JOIN participa p ON p.id_recorrido = re.id_recorrido JOIN usa u ON u.id_usa = p.id_usa WHERE $operative GROUP BY re.id_recorrido HAVING COUNT(DISTINCT u.id_cuadrilla) > 1) compartidos");
        $attention = [];
        foreach ([
            ['alta', 'crítico', 'Incidencias activas de prioridad Alta.', $high, 'incidencias'],
            ['reiteradas', 'advertencia', 'Contenedores con tres o más incidencias activas.', $repeated, 'incidencias'],
            ['sin_recorrido', 'informativo', 'Cuadrillas sin recorrido Pendiente o En Proceso relacionado.', $current['cuadrillas_sin_recorrido'], 'cuadrillas'],
            ['sin_vehiculo', 'crítico', 'Recorridos operativos sin vehículo válido relacionado.', $withoutVehicle, 'cuadrillas'],
            ['vehiculo_invalido', 'advertencia', 'Vehículos dados de baja o en mantenimiento relacionados con recorridos operativos.', $invalidVehicle, 'camiones'],
            ['compartido', 'crítico', 'Recorridos operativos compartidos por varias cuadrillas: requieren una asignación inequívoca.', $shared, 'cuadrillas']
        ] as [$type, $level, $description, $count, $module]) {
            if ($count) $attention[] = ['tipo' => $type, 'nivel' => $level, 'descripcion' => $description, 'cantidad' => $count, 'modulo' => $module];
        }
        return [
            'periodo' => ['desde' => $from, 'hasta' => $to, 'descripcion' => !$from && !$to ? 'Todos los registros' : ($from ? 'Desde ' . $from : 'Sin límite inicial') . ($to ? ' hasta ' . $to : ' · Sin límite final')],
            'sector' => 'OPERACIONES', 'actual' => $current, 'periodo_resultados' => $results,
            'incidencias_por_estado_prioridad' => $chart, 'contenedores_problematicos' => $top,
            'recorridos' => ['por_estado' => $trips, 'contenedores_esperados' => null, 'contenedores_pendientes' => null, 'porcentaje_avance' => null,
                'motivo' => 'No existe una lista histórica de contenedores esperados por recorrido. Las rutas y sus contenedores pueden cambiar; no se estima un porcentaje histórico.'],
            'historicas_sin_fecha_resolucion' => $this->count("SELECT COUNT(*) AS total FROM incidencia WHERE estado = 'Resuelta' AND fecha_resolucion IS NULL"),
            'requiere_atencion' => $attention, 'generado_en' => (new DateTimeImmutable('now', new DateTimeZone('America/Montevideo')))->format('Y-m-d H:i:s')
        ];
    }
}
