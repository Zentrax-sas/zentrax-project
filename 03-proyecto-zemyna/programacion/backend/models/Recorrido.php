<?php
require_once __DIR__ . '/AsignacionVehiculo.php';

class Recorrido {
    private $conn;
    private string $table_name = "recorrido";

    public $id_recorrido;
    public $fecha_inicio;
    public $fecha_fin;
    public $estado;
    public $id_ruta;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function read($id = null, $idRuta = null) {
        if (!$this->conn) return null;

        $where = [];
        $params = [];

        if ($id !== null && $id !== '') {
            $where[] = "r.id_recorrido = :id_recorrido";
            $params[':id_recorrido'] = (int)$id;
        }

        if ($idRuta !== null && $idRuta !== '') {
            $where[] = "r.id_ruta = :id_ruta";
            $params[':id_ruta'] = (int)$idRuta;
        }

        $query = "SELECT r.*, ru.nombre AS ruta_nombre, ru.zona
                  FROM " . $this->table_name . " r
                  INNER JOIN ruta ru ON ru.id_ruta = r.id_ruta";

        if ($where) {
            $query .= " WHERE " . implode(" AND ", $where);
        }

        $query .= " ORDER BY r.fecha_inicio DESC";

        $stmt = $this->conn->prepare($query);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt;
    }

    public function create() {
        if (!$this->conn) return false;

        if (empty($this->fecha_inicio) || empty($this->estado) || empty($this->id_ruta)) {
            return false;
        }

        $query = "INSERT INTO " . $this->table_name . "
                  (fecha_inicio, fecha_fin, estado, id_ruta)
                  VALUES (:fecha_inicio, :fecha_fin, :estado, :id_ruta)";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':fecha_inicio', $this->fecha_inicio);
        $stmt->bindParam(':fecha_fin', $this->fecha_fin);
        $stmt->bindParam(':estado', $this->estado);
        $stmt->bindParam(':id_ruta', $this->id_ruta);

        if ($stmt->execute()) {
            $this->id_recorrido = (int)$this->conn->lastInsertId();
            return true;
        }

        return false;
    }

    private function updateRow() {
        if (!$this->conn) return false;

        $query = "UPDATE " . $this->table_name . "
                  SET fecha_inicio = :fecha_inicio,
                      fecha_fin = :fecha_fin,
                      estado = :estado,
                      id_ruta = :id_ruta
                  WHERE id_recorrido = :id_recorrido
                  AND id_usuario_inicio IS NULL AND id_usuario_fin IS NULL
                  AND NOT EXISTS (SELECT 1 FROM atencion_contenedor a WHERE a.id_recorrido = recorrido.id_recorrido)";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_recorrido', $this->id_recorrido);
        $stmt->bindParam(':fecha_inicio', $this->fecha_inicio);
        $stmt->bindParam(':fecha_fin', $this->fecha_fin);
        $stmt->bindParam(':estado', $this->estado);
        $stmt->bindParam(':id_ruta', $this->id_ruta);

        $ok = $stmt->execute();
        if ($ok && $stmt->rowCount() === 0) {
            $guard = $this->conn->prepare('SELECT id_recorrido FROM recorrido WHERE id_recorrido = ? AND
                (id_usuario_inicio IS NOT NULL OR id_usuario_fin IS NOT NULL OR EXISTS (SELECT 1 FROM atencion_contenedor a WHERE a.id_recorrido = recorrido.id_recorrido))');
            $guard->execute([$this->id_recorrido]);
            if ($guard->fetchColumn() !== false) throw new DomainException('El recorrido tiene actividad registrada. No se puede alterar su historial desde el CRUD general.', 409);
        }
        return $ok;
    }

    public function update() {
        if (!$this->conn) return false;
        $this->conn->beginTransaction();
        try {
            $lock=$this->conn->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $s=$this->conn->prepare('SELECT id_recorrido FROM recorrido WHERE id_recorrido=?'.$lock);
            $s->execute([$this->id_recorrido]); $s->fetchAll();
            if (in_array($this->estado,['Pendiente','En Proceso'],true)) {
                $s=$this->conn->prepare('SELECT u.id_cuadrilla,u.id_vehiculo FROM participa p JOIN usa u ON u.id_usa=p.id_usa WHERE p.id_recorrido=? ORDER BY u.id_vehiculo'.$lock);
                $s->execute([$this->id_recorrido]);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $pair) {
                    $v=$this->conn->prepare('SELECT id_vehiculo FROM vehiculo WHERE id_vehiculo=?'.$lock);
                    $v->execute([$pair['id_vehiculo']]); $v->fetchAll();
                    (new AsignacionVehiculo($this->conn))->assertPair((int)$pair['id_cuadrilla'],(int)$pair['id_vehiculo']);
                }
            }
            $result=$this->updateRow(); $this->conn->commit(); return $result;
        } catch (Throwable $e) { if ($this->conn->inTransaction()) $this->conn->rollBack(); throw $e; }
    }

    public function delete() {
        if (!$this->conn) return false;

        $query = "DELETE FROM " . $this->table_name . "
                  WHERE id_recorrido = :id_recorrido
                  AND id_usuario_inicio IS NULL AND id_usuario_fin IS NULL
                  AND NOT EXISTS (SELECT 1 FROM atencion_contenedor a WHERE a.id_recorrido = recorrido.id_recorrido)";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_recorrido', $this->id_recorrido);

        $ok = $stmt->execute();
        if ($ok && $stmt->rowCount() === 0) {
            $guard = $this->conn->prepare('SELECT id_recorrido FROM recorrido WHERE id_recorrido = ? AND
                (id_usuario_inicio IS NOT NULL OR id_usuario_fin IS NOT NULL OR EXISTS (SELECT 1 FROM atencion_contenedor a WHERE a.id_recorrido = recorrido.id_recorrido))');
            $guard->execute([$this->id_recorrido]);
            if ($guard->fetchColumn() !== false) throw new DomainException('El recorrido tiene actividad registrada. No se puede alterar su historial desde el CRUD general.', 409);
        }
        return $ok;
    }
}
