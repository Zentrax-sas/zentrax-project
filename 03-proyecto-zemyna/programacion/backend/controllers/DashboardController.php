<?php
require_once __DIR__ . '/../models/Dashboard.php';
require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../helpers/auth.php';

class DashboardController
{
    public function __construct(private ?PDO $db) {}

    private function failure(int $status, string $message): array
    {
        return ['success' => false, 'statusCode' => $status, 'message' => $message, 'data' => null];
    }

    public function consultar(array $query, string $method = 'GET'): array
    {
        $id = $_SESSION['usuario']['id_usuario'] ?? null;
        if (!filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) return $this->failure(401, 'No autenticado.');
        if (!$this->db) return $this->failure(503, 'No se pudo consultar el resumen operativo.');
        try {
            // Una sesión anterior no conserva permisos revocados ni una cuenta dada de baja.
            $stmt = $this->db->prepare("SELECT id_usuario FROM usuario WHERE id_usuario = ? AND activo = 'Activo'");
            $stmt->execute([(int) $id]);
            if (!$stmt->fetchColumn()) return $this->failure(401, 'La sesión no corresponde a un usuario activo.');
            $usuario = new Usuario($this->db);
            $_SESSION['usuario']['roles'] = array_column($usuario->getRolesVigentes((int) $id), 'nombre');
            $_SESSION['usuario']['autorizaciones'] = $usuario->getAutorizacionesVigentes((int) $id);
            // Ámbito global de Operaciones: las entidades no tienen propietario sectorial.
            foreach (['incidencia.consultar', 'recorrido.consultar', 'cuadrilla.consultar', 'vehiculo.consultar', 'contenedor.consultar'] as $permission) {
                if (!hasEffectivePermission($permission, ['OPERACIONES'])) return $this->failure(403, 'El resumen requiere permisos de consulta de incidencias, recorridos, cuadrillas, vehículos y contenedores en Operaciones.');
            }
            // Contenedores no aplica la excepción sectorial del administrador TI en su API.
            if (!hasEffectivePermission('contenedor.consultar')) return $this->failure(403, 'No tenés permiso para consultar contenedores.');
            if ($method !== 'GET') return $this->failure(405, 'Método no permitido.');
            if (array_diff(array_keys($query), ['fecha_desde', 'fecha_hasta', 'sector', 'view'])) return $this->failure(400, 'Parámetros no admitidos.');
            if (isset($query['sector']) && $query['sector'] !== 'OPERACIONES') return $this->failure(403, 'Sector no habilitado para este resumen.');
            if (isset($query['view']) && $query['view'] !== 'permisos') return $this->failure(400, 'Vista inválida.');
            $dates = [];
            foreach (['fecha_desde', 'fecha_hasta'] as $key) {
                $value = $query[$key] ?? null;
                if ($value === null || $value === '') { $dates[$key] = null; continue; }
                if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return $this->failure(400, 'Usá fechas válidas con formato AAAA-MM-DD.');
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('America/Montevideo'));
                if (!$date || $date->format('Y-m-d') !== $value || $value < '1000-01-01' || $value > '9999-12-30') return $this->failure(400, 'Fecha fuera del rango admitido.');
                $dates[$key] = $value;
            }
            if ($dates['fecha_desde'] && $dates['fecha_hasta'] && $dates['fecha_desde'] > $dates['fecha_hasta']) return $this->failure(400, 'La fecha desde no puede superar la fecha hasta.');
            $links = ['incidencias' => '#incidencias', 'camiones' => '#camiones', 'contenedores' => '#contenedores', 'informe' => '#informe-incidencias',
                'cuadrillas' => hasEffectivePermission('cuadrilla.modificar', ['OPERACIONES']) ? '#cuadrillas' : null];
            $data = ($query['view'] ?? null) === 'permisos' ? ['habilitado' => true] : (new Dashboard($this->db))->consultar($dates['fecha_desde'], $dates['fecha_hasta']);
            return ['success' => true, 'statusCode' => 200, 'data' => $data + ['enlaces' => $links]];
        } catch (PDOException $e) {
            return $this->failure(503, 'No se pudo consultar el resumen operativo.');
        }
    }
}
