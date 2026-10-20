<?php
require_once __DIR__ . '/../exceptions/PersistenceException.php';

class Novedad {
    public function __construct(private $conn) {}

    public function readPublic(): array {
        if (!$this->conn) throw new PersistenceException('No hay conexión disponible.');
        // Solo contenedores públicos activos. Nunca leer descripciones, tracking,
        // fotos, usuarios ni contactos para este listado ciudadano.
        $stmt = $this->conn->prepare(
            "SELECT i.id_incidencia AS id, i.tipo_problema, i.fecha_reporte AS fecha,
                    i.estado, c.direccion
             FROM incidencia i
             INNER JOIN contenedor c ON c.id_contenedor = i.id_contenedor
             WHERE c.activo = 1 AND i.fecha_reporte <= CURRENT_TIMESTAMP
             ORDER BY i.fecha_reporte DESC, i.id_incidencia DESC
             LIMIT 20"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
