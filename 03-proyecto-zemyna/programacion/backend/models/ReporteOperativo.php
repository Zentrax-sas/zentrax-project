<?php

/** Agregados F7: sin identidades, sin joins que multipliquen trabajos. */
final class ReporteOperativo
{
    public function __construct(private PDO $db) {}
    private function count(string $table, string $condition = '1=1', array $args = []): int {
        $s = $this->db->prepare("SELECT COUNT(*) FROM $table WHERE $condition");
        $s->execute($args); return (int)$s->fetchColumn();
    }
    private function range(string $column, ?string $from, ?string $until): array {
        $conditions = ["$column IS NOT NULL"]; $args = [];
        if ($from !== null) { $conditions[] = "$column >= ?"; $args[] = "$from 00:00:00"; }
        if ($until !== null) { $conditions[] = "$column < ?"; $args[] = "$until 00:00:00"; }
        return [implode(' AND ', $conditions), $args];
    }
    private function event(string $table, string $column, ?string $from, ?string $until, string $extra = '1=1'): int {
        [$where,$args] = $this->range($column,$from,$until);
        return $this->count($table,"($where) AND ($extra)",$args);
    }
    private function states(string $table, array $states): array {
        $result = array_fill_keys($states,0);
        foreach ($this->db->query("SELECT estado,COUNT(*) cantidad FROM $table GROUP BY estado")->fetchAll(PDO::FETCH_ASSOC) as $r) $result[$r['estado']] = (int)$r['cantidad'];
        return $result;
    }
    private function attention(string $table, ?string $from, ?string $until): array {
        $events = [];
        foreach (['aceptados'=>'fecha_aceptacion','iniciados'=>'fecha_inicio'] as $name=>$date) $events[$name] = $this->event($table,$date,$from,$until);
        $durations = [];
        foreach (['finalizados'=>'Finalizada','rechazados'=>'Rechazada','interrumpidos'=>'Interrumpida'] as $name=>$state) {
            $events[$name] = $this->event($table,'fecha_cierre',$from,$until,"estado='$state'");
            if ($state === 'Rechazada') continue;
            [$where,$args] = $this->range('fecha_cierre',$from,$until);
            $s = $this->db->prepare("SELECT COUNT(*) muestras,AVG(TIMESTAMPDIFF(SECOND,fecha_inicio,fecha_cierre)) promedio_segundos FROM $table WHERE $where AND estado=? AND fecha_inicio IS NOT NULL AND fecha_cierre>=fecha_inicio");
            $s->execute([...$args,$state]); $r = $s->fetch(PDO::FETCH_ASSOC);
            $durations[$name] = ['muestras'=>(int)$r['muestras'],'promedio_segundos'=>$r['promedio_segundos']===null?null:round((float)$r['promedio_segundos'],2)];
        }
        return ['estados_actuales'=>$this->states($table,['Asignada','Aceptada','En atención','Finalizada','Rechazada','Interrumpida']), 'hitos_periodo'=>$events,'duracion_atencion'=>$durations];
    }
    /** El controlador abre una instantánea de lectura que incluye autorización. */
    public function consultar(string $report, ?string $from, ?string $until): array {
        $data = [];
        if (in_array($report,['solicitudes','todos'],true)) {
            $data['solicitudes'] = ['estados_actuales'=>$this->states('solicitud',['Pendiente','Programada','En atención','Finalizada','Cancelada']),
                'pendientes_clasificacion_historica_actual'=>$this->count('solicitud',"fecha_confirmacion_residuo IS NULL AND estado IN ('Pendiente','Programada')"),
                'finalizadas_sin_fecha_actual'=>$this->count('solicitud',"estado='Finalizada' AND fecha_finalizacion IS NULL"),
                'periodo'=>['creadas'=>$this->event('solicitud','fecha',$from,$until),'finalizadas'=>$this->event('solicitud','fecha_finalizacion',$from,$until,"estado='Finalizada'")]];
        }
        if (in_array($report,['atencion','todos'],true)) $data['atencion'] = ['incidencias'=>$this->attention('atencion_incidencia',$from,$until),'solicitudes'=>$this->attention('atencion_solicitud',$from,$until)];
        if (in_array($report,['recorridos','todos'],true)) $data['recorridos'] = [
            'iniciados'=>$this->event('recorrido','fecha_inicio',$from,$until,'id_usuario_inicio IS NOT NULL'),
            'finalizados'=>$this->event('recorrido','fecha_fin',$from,$until,"estado='Finalizado'"),
            'atenciones_contenedores'=>$this->event('atencion_contenedor','fecha_atencion',$from,$until),
            'finalizados_sin_fecha_actual'=>$this->count('recorrido',"estado='Finalizado' AND fecha_fin IS NULL"),
            'sin_autor_inicio_actual'=>$this->count('recorrido',"estado IN ('En Proceso','Finalizado') AND id_usuario_inicio IS NULL")];
        return $data;
    }
}
