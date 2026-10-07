<?php
/** Historial F6.1. Las transiciones y asignación se implementarán en bloques posteriores. */
class AtencionSolicitud
{
    public function __construct(private PDO $db) {}

    public function history(int $idSolicitud): array
    {
        $stmt = $this->db->prepare('SELECT * FROM atencion_solicitud WHERE id_solicitud=? ORDER BY id_atencion_solicitud DESC');
        $stmt->execute([$idSolicitud]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
