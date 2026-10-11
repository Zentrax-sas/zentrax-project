<?php
require_once __DIR__ . '/ProteccionSolicitudOperativa.php';

class Usa {
    private $conn;
    private string $table_name = "usa";

    public $id_usa;
    public $id_cuadrilla;
    public $id_vehiculo;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function read($id = null, $idCuadrilla = null, $idVehiculo = null) {
        if (!$this->conn) return null;

        $where = [];
        $params = [];

        if ($id !== null && $id !== '') {
            $where[] = "u.id_usa = :id_usa";
            $params[':id_usa'] = (int)$id;
        }

        if ($idCuadrilla !== null && $idCuadrilla !== '') {
            $where[] = "u.id_cuadrilla = :id_cuadrilla";
            $params[':id_cuadrilla'] = (int)$idCuadrilla;
        }

        if ($idVehiculo !== null && $idVehiculo !== '') {
            $where[] = "u.id_vehiculo = :id_vehiculo";
            $params[':id_vehiculo'] = (int)$idVehiculo;
        }

        $query = "SELECT u.*,
                         c.nombre AS cuadrilla_nombre,
                         c.turno,
                         v.matricula,
                         v.marca,
                         v.modelo,
                         v.estado AS vehiculo_estado
                  FROM " . $this->table_name . " u
                  INNER JOIN cuadrilla c ON c.id_cuadrilla = u.id_cuadrilla
                  INNER JOIN vehiculo v ON v.id_vehiculo = u.id_vehiculo";

        if ($where) {
            $query .= " WHERE " . implode(" AND ", $where);
        }

        $query .= " ORDER BY u.id_usa ASC";

        $stmt = $this->conn->prepare($query);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt;
    }

    private function createRow() {
        if (!$this->conn) return false;

        if (empty($this->id_cuadrilla) || empty($this->id_vehiculo)) {
            return false;
        }

        $query = "INSERT INTO " . $this->table_name . "
                  (id_cuadrilla, id_vehiculo)
                  VALUES (:id_cuadrilla, :id_vehiculo)";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_cuadrilla', $this->id_cuadrilla);
        $stmt->bindParam(':id_vehiculo', $this->id_vehiculo);

        if ($stmt->execute()) {
            $this->id_usa = (int)$this->conn->lastInsertId();
            return true;
        }

        return false;
    }

    private function updateRow() {
        if (!$this->conn) return false;

        $query = "UPDATE " . $this->table_name . "
                  SET id_cuadrilla = :id_cuadrilla,
                      id_vehiculo = :id_vehiculo
                  WHERE id_usa = :id_usa";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_usa', $this->id_usa);
        $stmt->bindParam(':id_cuadrilla', $this->id_cuadrilla);
        $stmt->bindParam(':id_vehiculo', $this->id_vehiculo);

        return $stmt->execute();
    }

    private function deleteRow() {
        if (!$this->conn) return false;

        $query = "DELETE FROM " . $this->table_name . "
                  WHERE id_usa = :id_usa";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_usa', $this->id_usa);

        return $stmt->execute();
    }

    private function protectedWrite(string $action) {
        if (!$this->conn) return false;
        if ($action === 'create' && (empty($this->id_cuadrilla) || empty($this->id_vehiculo))) return false;
        $this->conn->beginTransaction();
        try {
            $old = null;
            if ($action !== 'create') {
                $stmt = $this->conn->prepare('SELECT id_cuadrilla,id_vehiculo FROM usa WHERE id_usa=?');
                $stmt->execute([$this->id_usa]); $old = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            $new = $action === 'delete' ? null : ['id_cuadrilla'=>$this->id_cuadrilla,'id_vehiculo'=>$this->id_vehiculo];
            $guard = new ProteccionSolicitudOperativa($this->conn);
            $guard->lockVehicles(array_column(array_filter([$old,$new]), 'id_vehiculo'));
            if ($action !== 'create') {
                $lock = $this->conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
                $stmt = $this->conn->prepare('SELECT id_cuadrilla,id_vehiculo FROM usa WHERE id_usa=?'.$lock);
                $stmt->execute([$this->id_usa]); $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($old != $current) throw new DomainException('La relación cambió. Volvé a consultar.',409);
            }
            $guard->assertUseChange($old,$new);
            $ok = match($action) { 'create'=>$this->createRow(), 'update'=>$this->updateRow(), 'delete'=>$this->deleteRow() };
            $this->conn->commit(); return $ok;
        } catch(Throwable $e) { if ($this->conn->inTransaction()) $this->conn->rollBack(); throw $e; }
    }
    public function create() { return $this->protectedWrite('create'); }
    public function update() { return $this->protectedWrite('update'); }
    public function delete() { return $this->protectedWrite('delete'); }

    public function existeRelacion($idCuadrilla, $idVehiculo) {
        if (!$this->conn) return false;

        $query = "SELECT COUNT(*)
                  FROM " . $this->table_name . "
                  WHERE id_cuadrilla = :id_cuadrilla
                  AND id_vehiculo = :id_vehiculo";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':id_cuadrilla' => $idCuadrilla,
            ':id_vehiculo' => $idVehiculo
        ]);

        return (int)$stmt->fetchColumn() > 0;
    }
}
