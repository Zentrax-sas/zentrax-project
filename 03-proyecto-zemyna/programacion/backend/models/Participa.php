<?php
require_once __DIR__ . '/IntegridadRelacionesOperativas.php';
require_once __DIR__ . '/AsignacionVehiculo.php';

class Participa {
    private $conn;
    private string $table_name = "participa";

    public $id_participa;
    public $id_usa;
    public $id_recorrido;
    public $hora_inicio;
    public $hora_fin;
    public $motivo_fin;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function read($id = null, $idRecorrido = null) {
        if (!$this->conn) return null;

        $where = [];
        $params = [];

        if ($id !== null && $id !== '') {
            $where[] = "p.id_participa = :id_participa";
            $params[':id_participa'] = (int)$id;
        }

        if ($idRecorrido !== null && $idRecorrido !== '') {
            $where[] = "p.id_recorrido = :id_recorrido";
            $params[':id_recorrido'] = (int)$idRecorrido;
        }

        $query = "SELECT p.*,
                         u.id_cuadrilla,
                         u.id_vehiculo,
                         c.nombre AS cuadrilla_nombre,
                         v.matricula,
                         v.marca,
                         v.modelo
                  FROM " . $this->table_name . " p
                  INNER JOIN usa u ON u.id_usa = p.id_usa
                  INNER JOIN cuadrilla c ON c.id_cuadrilla = u.id_cuadrilla
                  INNER JOIN vehiculo v ON v.id_vehiculo = u.id_vehiculo";

        if ($where) {
            $query .= " WHERE " . implode(" AND ", $where);
        }

        $query .= " ORDER BY p.hora_inicio ASC";

        $stmt = $this->conn->prepare($query);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt;
    }

    private function createRow() {
        if (!$this->conn) return false;

        if (empty($this->id_usa) || empty($this->id_recorrido) || empty($this->hora_inicio)) {
            return false;
        }

        $query = "INSERT INTO " . $this->table_name . "
                  (id_usa, id_recorrido, hora_inicio, hora_fin, motivo_fin)
                  VALUES (:id_usa, :id_recorrido, :hora_inicio, :hora_fin, :motivo_fin)";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_usa', $this->id_usa);
        $stmt->bindParam(':id_recorrido', $this->id_recorrido);
        $stmt->bindParam(':hora_inicio', $this->hora_inicio);
        $stmt->bindParam(':hora_fin', $this->hora_fin);
        $stmt->bindParam(':motivo_fin', $this->motivo_fin);

        if ($stmt->execute()) {
            $this->id_participa = (int)$this->conn->lastInsertId();
            return true;
        }

        return false;
    }

    private function updateRow() {
        if (!$this->conn) return false;

        $query = "UPDATE " . $this->table_name . "
                  SET id_usa = :id_usa,
                      id_recorrido = :id_recorrido,
                      hora_inicio = :hora_inicio,
                      hora_fin = :hora_fin,
                      motivo_fin = :motivo_fin
                  WHERE id_participa = :id_participa";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_participa', $this->id_participa);
        $stmt->bindParam(':id_usa', $this->id_usa);
        $stmt->bindParam(':id_recorrido', $this->id_recorrido);
        $stmt->bindParam(':hora_inicio', $this->hora_inicio);
        $stmt->bindParam(':hora_fin', $this->hora_fin);
        $stmt->bindParam(':motivo_fin', $this->motivo_fin);

        return $stmt->execute();
    }

    private function deleteRow() {
        if (!$this->conn) return false;

        $query = "DELETE FROM " . $this->table_name . "
                  WHERE id_participa = :id_participa";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_participa', $this->id_participa);

        return $stmt->execute();
    }

    public function create() { return $this->protectedWrite('create'); }
    public function update() { return $this->protectedWrite('update'); }
    public function delete() { return $this->protectedWrite('delete'); }
    private function protectedWrite(string $action) {
        if (!$this->conn) throw new DomainException('Persistencia no disponible.',503);
        $this->conn->beginTransaction();
        try {
            if ($action !== 'delete' && (!empty($this->hora_fin) || !empty($this->motivo_fin))) {
                throw new DomainException('El cierre de una participación debe conservar el flujo operativo.',409);
            }
            $read = function(string $sql, array $args) { $s=$this->conn->prepare($sql); $s->execute($args); return $s->fetch(PDO::FETCH_ASSOC) ?: null; };
            $old = $action === 'create' ? null : $read('SELECT * FROM participa WHERE id_participa=?',[$this->id_participa]);
            if ($action !== 'create' && !$old) throw new DomainException('Participación no encontrada.',404);
            $ids = array_unique(array_filter([$old['id_usa'] ?? null,$action !== 'delete' ? $this->id_usa : null]));
            $pairs=[]; foreach ($ids as $id) { $p=$read('SELECT * FROM usa WHERE id_usa=?',[$id]); if (!$p) throw new DomainException('Autorización no encontrada.',404); $pairs[(int)$id]=$p; }
            $guard=new IntegridadRelacionesOperativas($this->conn);
            $guard->lock(array_values($pairs),array_filter([$old['id_recorrido'] ?? null,$action !== 'delete' ? $this->id_recorrido : null]));
            foreach ($pairs as $id=>$pair) if (($guard->rows('SELECT * FROM usa WHERE id_usa=?',[$id])[0] ?? null) != $pair) throw new DomainException('La autorización cambió. Volvé a consultar.',409);
            if ($old) {
                if (($guard->rows('SELECT * FROM participa WHERE id_participa=?',[$this->id_participa])[0] ?? null) != $old) throw new DomainException('La participación cambió. Volvé a consultar.',409);
                $guard->pending((int)$old['id_recorrido']);
            }
            if ($action !== 'delete') $guard->participation((int)$this->id_recorrido,$pairs[(int)$this->id_usa],$old ? (int)$this->id_participa : null);
            $ok=match($action) { 'create'=>$this->createRow(),'update'=>$this->updateRow(),'delete'=>$this->deleteRow() };
            if (!$ok) throw new RuntimeException('No se pudo guardar la relación.');
            $this->conn->commit(); return true;
        } catch(Throwable $e) { if ($this->conn->inTransaction()) $this->conn->rollBack(); throw $e; }
    }

    public function participacionActiva($idRecorrido) {
        if (!$this->conn) return null;

        $query = "SELECT p.*,
                         u.id_cuadrilla,
                         u.id_vehiculo
                  FROM " . $this->table_name . " p
                  INNER JOIN usa u ON u.id_usa = p.id_usa
                  WHERE p.id_recorrido = :id_recorrido
                  AND p.hora_fin IS NULL
                  ORDER BY p.hora_inicio DESC
                  LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id_recorrido', (int)$idRecorrido, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
