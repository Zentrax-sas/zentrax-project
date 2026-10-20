<?php
class ReglaCancelacion {
    public static function asignada(array $row): bool {
        // id_ruta identifica el ámbito de la incidencia; no acredita asignación operativa.
        return !empty($row['asignada']) || !empty($row['id_cuadrilla'])
            || !empty($row['id_vehiculo']) || !empty($row['id_camion']);
    }
    public static function permitida(array $row): bool {
        return ($row['estado'] ?? '') === 'Pendiente' && !self::asignada($row);
    }
    public static function mensaje(string $tracking, array $row): string {
        $sujeto = str_starts_with($tracking,'INC-') ? 'Esta incidencia' : 'Esta solicitud';
        if (in_array($row['estado'] ?? '', ['Resuelta','Resuelto','Finalizada','Finalizado','Cancelada','Cancelado','Cerrada','Cerrado'],true)) {
            return $sujeto . ' está cerrada o cancelada y no permite cancelación.';
        }
        return $sujeto . ' ya fue asignada. Para solicitar la cancelación, comunicate con Atención al Cliente al 0800 0800.';
    }
}
