<?php
class Contenedor {
    private $conn;
    private string $table_name = "contenedor";

    public $id_contenedor;
    public $codigo;
    public $capacidad;
    public $direccion;
    public $latitud;
    public $longitud;
    public $estado;
    public $activo;
    public $id_tipo_residuo;
    public $id_ruta;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function read($id = null, $page = 1, $limit = 20, $bbox = []) {
        if (!$this->conn) return null;

        $conditions = ['activo = 1'];
        if ($id !== null && $id !== '') {
            $conditions[] = 'id_contenedor = :id_contenedor';
        }
        if (isset($bbox['min_lat'], $bbox['min_lon'], $bbox['max_lat'], $bbox['max_lon'])) {
            $conditions[] = 'latitud BETWEEN :min_lat AND :max_lat AND longitud BETWEEN :min_lon AND :max_lon';
        }

        $offset = ($page - 1) * $limit;
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $query = 'SELECT * FROM ' . $this->table_name . $where . ' ORDER BY id_contenedor ASC LIMIT :limit OFFSET :offset';
        $stmt = $this->conn->prepare($query);

        if ($id !== null && $id !== '') {
            $stmt->bindValue(':id_contenedor', (int) $id, PDO::PARAM_INT);
        }
        if (isset($bbox['min_lat'], $bbox['min_lon'], $bbox['max_lat'], $bbox['max_lon'])) {
            $stmt->bindValue(':min_lat', (float) $bbox['min_lat']);
            $stmt->bindValue(':min_lon', (float) $bbox['min_lon']);
            $stmt->bindValue(':max_lat', (float) $bbox['max_lat']);
            $stmt->bindValue(':max_lon', (float) $bbox['max_lon']);
        }
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt;
    }

    private function buildAdminWhere(array $filters): array {
        $conditions = ['c.activo = 1'];
        $params = [];

        if (isset($filters['id'])) {
            $conditions[] = 'c.id_contenedor = :id_contenedor';
            $params[':id_contenedor'] = [$filters['id'], PDO::PARAM_INT];
        }
        if (($filters['search'] ?? '') !== '') {
            $escaped = str_replace(['=', '%', '_'], ['==', '=%', '=_'], $filters['search']);
            $conditions[] = "(c.codigo LIKE :search_codigo ESCAPE '=' OR c.direccion LIKE :search_direccion ESCAPE '=' OR r.nombre LIKE :search_ruta ESCAPE '=')";
            foreach ([':search_codigo', ':search_direccion', ':search_ruta'] as $parameter) {
                $params[$parameter] = ['%' . $escaped . '%', PDO::PARAM_STR];
            }
        }
        if (isset($filters['estado'])) {
            $conditions[] = 'c.estado = :estado';
            $params[':estado'] = [$filters['estado'], PDO::PARAM_STR];
        }
        if (isset($filters['id_tipo_residuo'])) {
            $conditions[] = 'c.id_tipo_residuo = :id_tipo_residuo';
            $params[':id_tipo_residuo'] = [$filters['id_tipo_residuo'], PDO::PARAM_INT];
        }
        if (isset($filters['id_ruta'])) {
            $conditions[] = 'c.id_ruta = :id_ruta';
            $params[':id_ruta'] = [$filters['id_ruta'], PDO::PARAM_INT];
        }

        return [' WHERE ' . implode(' AND ', $conditions), $params];
    }

    public function readAdmin(array $filters, int $page, int $limit) {
        if (!$this->conn) return null;

        [$where, $params] = $this->buildAdminWhere($filters);
        $offset = ($page - 1) * $limit;
        $query = "SELECT c.id_contenedor, c.codigo, c.capacidad, c.direccion,
                         c.latitud, c.longitud, c.estado,
                         c.id_tipo_residuo, c.id_ruta, r.nombre AS ruta_nombre
                  FROM {$this->table_name} c
                  INNER JOIN ruta r ON r.id_ruta = c.id_ruta
                  {$where}
                  ORDER BY c.id_contenedor ASC
                  LIMIT :limit OFFSET :offset";
        $stmt = $this->conn->prepare($query);
        foreach ($params as $name => [$value, $type]) {
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt;
    }

    public function countAdmin(array $filters): ?int {
        if (!$this->conn) return null;

        [$where, $params] = $this->buildAdminWhere($filters);
        $query = "SELECT COUNT(*)
                  FROM {$this->table_name} c
                  INNER JOIN ruta r ON r.id_ruta = c.id_ruta
                  {$where}";
        $stmt = $this->conn->prepare($query);
        foreach ($params as $name => [$value, $type]) {
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();
        $count = $stmt->fetchColumn();
        return $count === false ? null : (int) $count;
    }

    public function readForMap(float $south, float $north, float $west, float $east, int $limit) {
        if (!$this->conn) return null;

        $query = "SELECT id_contenedor, codigo, direccion, latitud, longitud, estado
                  FROM " . $this->table_name . "
                  WHERE activo = 1
                    AND latitud BETWEEN :south AND :north
                    AND longitud BETWEEN :west AND :east
                  ORDER BY id_contenedor ASC
                  LIMIT :limit";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':south', $south);
        $stmt->bindValue(':north', $north);
        $stmt->bindValue(':west', $west);
        $stmt->bindValue(':east', $east);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt;
    }

    public function findByCodigo($codigo) {
        if (!$this->conn) return null;

        $query = 'SELECT * FROM ' . $this->table_name . ' WHERE codigo = :codigo LIMIT 1';
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':codigo', trim((string) $codigo));
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create() {
        if (!$this->conn) return false;
        if (empty($this->codigo) || empty($this->capacidad) || empty($this->direccion) ||
            empty($this->estado) || empty($this->id_tipo_residuo) || empty($this->id_ruta)) {
            return false;
        }
        $query = "INSERT INTO " . $this->table_name . "
                  (codigo, capacidad, direccion, latitud, longitud, estado, id_tipo_residuo, id_ruta)
                  VALUES (:codigo, :capacidad, :direccion, :latitud, :longitud, :estado, :id_tipo_residuo, :id_ruta)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":codigo",          $this->codigo);
        $stmt->bindParam(":capacidad",        $this->capacidad);
        $stmt->bindParam(":direccion",        $this->direccion);
        $stmt->bindParam(":latitud",          $this->latitud);
        $stmt->bindParam(":longitud",         $this->longitud);
        $stmt->bindParam(":estado",           $this->estado);
        $stmt->bindParam(":id_tipo_residuo",  $this->id_tipo_residuo);
        $stmt->bindParam(":id_ruta",          $this->id_ruta);
        return $stmt->execute();
    }

    public function update() {
        if (!$this->conn) return false;
        $query = "UPDATE " . $this->table_name . "
                  SET codigo=:codigo, capacidad=:capacidad, direccion=:direccion,
                      latitud=:latitud, longitud=:longitud, estado=:estado,
                      id_tipo_residuo=:id_tipo_residuo, id_ruta=:id_ruta
                  WHERE id_contenedor=:id_contenedor";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_contenedor",   $this->id_contenedor);
        $stmt->bindParam(":codigo",          $this->codigo);
        $stmt->bindParam(":capacidad",       $this->capacidad);
        $stmt->bindParam(":direccion",       $this->direccion);
        $stmt->bindParam(":latitud",         $this->latitud);
        $stmt->bindParam(":longitud",        $this->longitud);
        $stmt->bindParam(":estado",          $this->estado);
        $stmt->bindParam(":id_tipo_residuo", $this->id_tipo_residuo);
        $stmt->bindParam(":id_ruta",         $this->id_ruta);
        return $stmt->execute();
    }

    public function delete() {
        if (!$this->conn) return false;
        $query = "UPDATE " . $this->table_name . "
                  SET activo = 0
                  WHERE id_contenedor = :id_contenedor
                  AND activo = 1";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindParam(":id_contenedor", $this->id_contenedor);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }
}
