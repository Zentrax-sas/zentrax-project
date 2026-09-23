<?php

/** Consulta las relaciones registradas; no infiere pertenencia ni asignaciones vigentes. */
class Recoleccion
{
    public function __construct(protected PDO $db) {}

    public function usuarioActivo(int $id): bool
    {
        $stmt = $this->db->prepare("SELECT id_usuario FROM usuario WHERE id_usuario = ? AND activo = 'Activo'");
        $stmt->execute([$id]);
        return $stmt->fetchColumn() !== false;
    }

    protected function rows(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function page(string $select, string $from, array $params, string $order, int $page): array
    {
        $count = $this->rows('SELECT COUNT(*) AS total ' . $from, $params)[0]['total'];
        $stmt = $this->db->prepare($select . ' ' . $from . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?');
        foreach ($params as $i => $value) $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $stmt->bindValue(count($params) + 1, 25, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, ($page - 1) * 25, PDO::PARAM_INT);
        $stmt->execute();
        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $count, 'page' => $page, 'page_size' => 25];
    }

    protected function vehiculosCuadrilla(int $cuadrilla): array
    {
        return $this->rows('SELECT DISTINCT v.id_vehiculo, v.matricula, v.estado, v.activo FROM vehiculo v
                JOIN usa u ON u.id_vehiculo = v.id_vehiculo WHERE u.id_cuadrilla = ? ORDER BY v.id_vehiculo', [$cuadrilla]);
    }
    public function consultar(?int $cuadrilla, ?int $ruta, int $page, ?string $estado): ?array
    {
        if ($cuadrilla === null) {
            return ['tipo' => 'cuadrillas', 'lista' => $this->page('SELECT id_cuadrilla, nombre, turno', 'FROM cuadrilla', [], 'id_cuadrilla', $page)];
        }
        $squad = $this->rows('SELECT id_cuadrilla, nombre, turno FROM cuadrilla WHERE id_cuadrilla = ?', [$cuadrilla])[0] ?? null;
        if (!$squad) return null;
        if ($ruta !== null) {
            $route = $this->rows('SELECT r.id_ruta, r.nombre, r.zona FROM ruta r WHERE r.id_ruta = ? AND EXISTS (
                SELECT 1 FROM recorrido re JOIN participa p ON p.id_recorrido = re.id_recorrido
                JOIN usa u ON u.id_usa = p.id_usa WHERE re.id_ruta = r.id_ruta AND u.id_cuadrilla = ?)', [$ruta, $cuadrilla])[0] ?? null;
            if (!$route) return null;
            return ['tipo' => 'contenedores', 'cuadrilla' => $squad, 'ruta' => $route,
                'lista' => $this->page('SELECT id_contenedor, codigo, direccion, latitud, longitud, estado, activo',
                    'FROM contenedor WHERE id_ruta = ?', [$ruta], 'id_contenedor', $page)];
        }
        $from = 'FROM recorrido re JOIN ruta r ON r.id_ruta = re.id_ruta WHERE EXISTS (
            SELECT 1 FROM participa p JOIN usa u ON u.id_usa = p.id_usa
            WHERE p.id_recorrido = re.id_recorrido AND u.id_cuadrilla = ?)';
        $params = [$cuadrilla];
        if ($estado !== null) { $from .= ' AND re.estado = ?'; $params[] = $estado; }
        $result = $this->page('SELECT re.id_recorrido, re.fecha_inicio, re.fecha_fin, re.estado, r.id_ruta, r.nombre AS ruta_nombre, r.zona',
            $from, $params, 're.fecha_inicio DESC, re.id_recorrido DESC', $page);
        foreach ($result['items'] as &$row) {
            $row['vehiculos'] = $this->rows('SELECT DISTINCT v.id_vehiculo, v.matricula, v.estado, v.activo
                FROM vehiculo v JOIN usa u ON u.id_vehiculo = v.id_vehiculo JOIN participa p ON p.id_usa = u.id_usa
                WHERE u.id_cuadrilla = ? AND p.id_recorrido = ? ORDER BY v.id_vehiculo', [$cuadrilla, $row['id_recorrido']]);
        }
        return ['tipo' => 'recorridos', 'cuadrilla' => $squad, 'lista' => $result,
            'vehiculos' => $this->vehiculosCuadrilla($cuadrilla)];
    }
}
