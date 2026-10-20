<?php
require_once __DIR__ . '/../exceptions/PersistenceException.php';
require_once __DIR__ . '/../helpers/ReglaCancelacion.php';

// Lectura pública limitada a los datos del trámite, sin contactos ni datos internos.
class Seguimiento {
    public function __construct(private $conn) {}

    public function findByTracking(string $tracking): ?array {
        if (!$this->conn) throw new PersistenceException('No hay conexión disponible.');
        if (str_starts_with($tracking, 'INC-')) {
            $sql = "SELECT i.tracking_number, 'Incidencia' AS tipo_tramite,
                           i.estado, i.fecha_reporte AS fecha, c.direccion, i.descripcion, i.tipo_problema,
                           i.updated_at, i.revision, i.fecha_cancelacion,
                           (i.id_cuadrilla IS NOT NULL) AS asignada
                    FROM incidencia i LEFT JOIN contenedor c ON c.id_contenedor = i.id_contenedor
                    WHERE i.tracking_number = :tracking LIMIT 1";
        } else {
            $sql = "SELECT s.tracking_number, s.tipo_solicitud AS tipo_tramite,
                           s.estado, s.fecha, s.direccion, s.descripcion, s.updated_at, s.revision, s.fecha_cancelacion, 0 AS asignada,
                           COALESCE(s.tipo_residuo_solicitado,t.nombre) AS tipo_residuo
                    FROM solicitud s LEFT JOIN tipo_residuo t ON t.id_tipo_residuo=s.id_tipo_residuo
                    WHERE s.tracking_number = :tracking LIMIT 1";
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tracking', $tracking);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if(array_key_exists('asignada',$row)){
            $row['asignada']=(bool)$row['asignada'];
            $row['revision']=(int)$row['revision'];
            $row['cancelable']=preg_match('/^(INC|RET)-/',$tracking)===1&&ReglaCancelacion::permitida($row);
        }
        if (str_starts_with($tracking, 'RET-')) $row['tipo_tramite'] = 'Solicitud de retiro';
        return $row;
    }
}
