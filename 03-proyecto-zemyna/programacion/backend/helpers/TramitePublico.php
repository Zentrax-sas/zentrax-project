<?php
/** Bloqueo compartido con V21: cuadrillas -> solicitud -> intentos. */
final class TramitePublico {
    public static function gate(PDO $db): void {
        if (!$db->inTransaction()) throw new LogicException('Escritura pública fuera de transacción.');
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $db->query('SELECT id_cuadrilla FROM cuadrilla ORDER BY id_cuadrilla FOR UPDATE')->fetchAll();
        }
    }
    public static function asignada(PDO $db, array $row, bool $lock = false): bool {
        if (!empty($row['id_cuadrilla'])) return true;
        $sql = 'SELECT id_atencion_solicitud FROM atencion_solicitud WHERE id_solicitud=? AND (fecha_cierre IS NULL OR fecha_inicio IS NOT NULL)';
        if ($lock && $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $sql .= ' FOR UPDATE';
        $s = $db->prepare($sql); $s->execute([$row['id_solicitud']]);
        return $s->fetchColumn() !== false;
    }
}
