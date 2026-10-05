<?php

/** Concesión temporal. El secreto sólo se devuelve al crear la incidencia. */
class IncidenciaUploadToken {
    public function __construct(private PDO $db) {}

    public static function now(): DateTimeImmutable {
        return new DateTimeImmutable('now', new DateTimeZone('America/Montevideo'));
    }

    /** Debe ejecutarse dentro de la misma transacción que el alta. */
    public function issue(int $incident, ?int $issuer): array {
        if (!$this->db->inTransaction()) throw new LogicException('El alta requiere una transacción.');
        $secret = bin2hex(random_bytes(32));
        $now = self::now();
        $expires = $now->modify('+10 minutes')->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare('INSERT INTO incidencia_upload_token
            (token_hash,id_incidencia,id_usuario_emisor,fecha_creacion,fecha_expiracion)
            VALUES (?,?,?,?,?)');
        $stmt->bindValue(1, hash('sha256', $secret, true), PDO::PARAM_LOB);
        $stmt->bindValue(2, $incident, PDO::PARAM_INT);
        $stmt->bindValue(3, $issuer, $issuer === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(4, $now->format('Y-m-d H:i:s'));
        $stmt->bindValue(5, $expires);
        if (!$stmt->execute()) throw new PDOException('No se pudo guardar la concesión.');
        return ['upload_token' => $secret, 'upload_expires_at' => $expires];
    }

    private function find(string $hash, bool $locking): ?array {
        $lock = $locking && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $this->db->prepare('SELECT * FROM incidencia_upload_token WHERE token_hash=?' . $lock);
        $stmt->bindValue(1, $hash, PDO::PARAM_LOB);
        if (!$stmt->execute()) throw new PDOException('No se pudo consultar la concesión.');
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Revalida bajo bloqueo; nunca confía en los roles cacheados de sesión. */
    public function authorize(string $secret, int $requestedIncident, ?int $sessionUser): array {
        if (!$this->db->inTransaction()) throw new LogicException('La subida requiere una transacción.');
        if (!preg_match('/^[a-f0-9]{64}$/D', $secret)) throw new DomainException('Autorización de fotografía inválida.', 403);
        $hash = hash('sha256', $secret, true);
        $first = $this->find($hash, false);
        if (!$first || (int)$first['id_incidencia'] !== $requestedIncident) throw new DomainException('Autorización de fotografía inválida.', 403);
        // Orden compatible con las operaciones de incidencia: incidencia antes de concesión.
        $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $this->db->prepare('SELECT id_incidencia FROM incidencia WHERE id_incidencia=?' . $lock);
        $stmt->execute([$requestedIncident]);
        if ($stmt->fetchColumn() === false) throw new DomainException('Autorización de fotografía inválida.', 403);
        $grant = $this->find($hash, true);
        if (!$grant || !hash_equals($grant['token_hash'], $hash) || (int)$grant['id_incidencia'] !== $requestedIncident) {
            throw new DomainException('Autorización de fotografía inválida.', 403);
        }
        if ($grant['id_usuario_emisor'] !== null) {
            if ($sessionUser !== (int)$grant['id_usuario_emisor']) throw new DomainException('La fotografía requiere la sesión que creó el reporte.', 403);
            $today = self::now()->format('Y-m-d');
            $stmt = $this->db->prepare("SELECT u.id_usuario FROM usuario u
                JOIN usuario_rol ur ON ur.id_usuario=u.id_usuario
                JOIN rol_permiso rp ON rp.id_rol=ur.id_rol JOIN permiso p ON p.id_permiso=rp.id_permiso
                WHERE u.id_usuario=? AND u.activo='Activo' AND ur.fecha_desde<=?
                AND (ur.fecha_hasta IS NULL OR ur.fecha_hasta>=?)
                AND ur.sector IN ('OPERACIONES','INSPECCION','PUNTOS_Y_DESTINOS')
                AND p.nombre='incidencia.adjuntar_evidencia'" . $lock);
            $stmt->execute([$sessionUser, $today, $today]);
            if ($stmt->fetchColumn() === false) throw new DomainException('No tenés permiso vigente para adjuntar evidencia.', 403);
        }
        if ($grant['fecha_consumo'] !== null) throw new DomainException('La autorización de fotografía ya fue utilizada.', 409);
        if ($grant['fecha_expiracion'] <= self::now()->format('Y-m-d H:i:s')) throw new DomainException('La autorización de fotografía expiró.', 403);
        return $grant;
    }

    public function consume(array $grant, int $photo): void {
        $stmt = $this->db->prepare('UPDATE incidencia_upload_token SET fecha_consumo=?,id_foto=?
            WHERE token_hash=? AND fecha_consumo IS NULL AND fecha_expiracion>?');
        $now = self::now()->format('Y-m-d H:i:s');
        $stmt->bindValue(1, $now);
        $stmt->bindValue(2, $photo, PDO::PARAM_INT);
        $stmt->bindValue(3, $grant['token_hash'], PDO::PARAM_LOB);
        $stmt->bindValue(4, $now);
        if (!$stmt->execute()) throw new PDOException('No se pudo consumir la concesión.');
        if ($stmt->rowCount() !== 1) throw new DomainException('La autorización de fotografía expiró o fue utilizada.', 409);
    }
}
