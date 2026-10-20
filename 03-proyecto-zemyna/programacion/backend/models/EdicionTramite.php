<?php
require_once __DIR__.'/../helpers/TramitePublico.php';
class EdicionTramite {
    public function __construct(private PDO $db) {}

    public function emitirClave(string $tracking): string {
        $token = bin2hex(random_bytes(32));
        $this->db->prepare('INSERT INTO tramite_edicion (tracking_number, token_hash) VALUES (?, ?) ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash)')
            ->execute([$tracking, hash('sha256', $token)]);
        return $token;
    }
    public function autorizado(string $tracking, string $token): bool {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
        $stmt = $this->db->prepare('SELECT token_hash FROM tramite_edicion WHERE tracking_number = ?');
        $stmt->execute([$tracking]);
        $hash = $stmt->fetchColumn();
        return is_string($hash) && hash_equals($hash, hash('sha256', $token));
    }
    public static function abierto(string $estado, bool $incidencia): bool {
        return in_array($estado, $incidencia ? ['Pendiente', 'En Proceso'] : ['Pendiente', 'Programada'], true);
    }
    public function detalle(string $tracking, bool $bloquear = false): ?array {
        $inc = str_starts_with($tracking, 'INC-');
        $sql = $inc
            ? "SELECT i.tracking_number, i.descripcion, i.tipo_problema, i.estado, i.fecha_reporte AS fecha,
                      i.updated_at, i.revision, i.telefono AS celular, c.direccion
               FROM incidencia i LEFT JOIN contenedor c ON c.id_contenedor=i.id_contenedor WHERE i.tracking_number=?"
            : "SELECT s.id_solicitud, s.id_cuadrilla, s.tracking_number, s.descripcion, s.id_tipo_residuo,
                      COALESCE(s.tipo_residuo_solicitado,t.nombre) AS tipo_residuo, s.estado, s.fecha,
                      s.updated_at, s.revision, s.telefono AS celular, s.direccion
               FROM solicitud s LEFT JOIN tipo_residuo t ON t.id_tipo_residuo=s.id_tipo_residuo WHERE s.tracking_number=?";
        $stmt = $this->db->prepare($sql . ($bloquear ? ' FOR UPDATE' : ''));
        $stmt->execute([$tracking]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['revision'] = (int)$row['revision'];
        if (!$inc) $row['id_tipo_residuo'] = (int)$row['id_tipo_residuo'];
        $row['editable'] = self::abierto($row['estado'], $inc) && ($inc || !TramitePublico::asignada($this->db, $row, $bloquear));
        if (!$inc) unset($row['id_solicitud'], $row['id_cuadrilla']);
        $row['tipo_tramite'] = $inc ? 'Incidencia' : 'Solicitud de retiro';
        return $row;
    }
    public function guardar(string $tracking, array $data): array {
        $this->db->beginTransaction();
        try {
            if (!str_starts_with($tracking, 'INC-')) TramitePublico::gate($this->db);
            $row = $this->detalle($tracking, true);
            if (!$row) throw new DomainException('No se encontró el trámite.', 404);
            if (!$row['editable']) throw new DomainException('El trámite está cerrado y no permite cambios.', 409);
            if ($row['revision'] !== $data['revision']) throw new DomainException('El trámite cambió. Volvé a consultarlo antes de guardar.', 409);
            $inc = str_starts_with($tracking, 'INC-');
            if ($inc) {
                $sql = 'UPDATE incidencia SET descripcion=?, tipo_problema=?, updated_at=NOW(6), revision=revision+1 WHERE tracking_number=?';
                $params = [$data['descripcion'], $data['tipo_problema'], $tracking];
            } else {
                $stmt = $this->db->prepare('SELECT nombre FROM tipo_residuo WHERE id_tipo_residuo=?');
                $stmt->execute([$data['id_tipo_residuo']]);
                $nombre = $stmt->fetchColumn();
                if (!is_string($nombre)) throw new DomainException('Seleccioná un tipo de residuo válido.', 400);
                $invalidate = $row['id_tipo_residuo'] !== $data['id_tipo_residuo'] ? 'fecha_confirmacion_residuo=NULL, id_usuario_confirma_residuo=NULL, ' : '';
                $sql = 'UPDATE solicitud SET ' . $invalidate . 'descripcion=?, id_tipo_residuo=?, tipo_residuo_solicitado=?, updated_at=NOW(6), revision=revision+1 WHERE tracking_number=?';
                $params = [$data['descripcion'], $data['id_tipo_residuo'], $nombre, $tracking];
            }
            $this->db->prepare($sql)->execute($params);
            $actual = $this->detalle($tracking);
            $this->db->commit();
            return $actual;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }
}
