<?php
require_once __DIR__ . '/../models/RecoleccionOperativa.php';
require_once __DIR__ . '/../helpers/auth.php';

class RecoleccionController
{
    public function __construct(private ?PDO $db) {}

    private function failure(int $status, string $message, string $code = ''): array
    {
        return ['success' => false, 'statusCode' => $status, 'message' => $message, 'code' => $code];
    }

    public function consultar(array $query, string $method = 'GET'): array
    {
        $user = $_SESSION['usuario'] ?? null;
        if (!$user || !filter_var($user['id_usuario'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
            return $this->failure(401, 'No autenticado.');
        }
        if (!hasEffectivePermission('recorrido.consultar', ['LOGISTICA', 'OPERACIONES'])) return $this->failure(403, 'No tenés permiso para consultar recolección.');
        $admin = hasEffectivePermission('cuadrilla.consultar', ['OPERACIONES']) && hasEffectivePermission('cuadrilla.modificar', ['OPERACIONES']);
        if (!in_array($query['view'] ?? 'propia', ['propia', 'administracion', 'integrantes', 'permisos', 'asignables'], true)) return $this->failure(400, 'Vista inválida.');
        if (($query['view'] ?? '') === 'administracion' && !$admin) return $this->failure(403, 'La consulta de otras cuadrillas requiere permiso de gestión de cuadrillas.');
        if (($query['view'] ?? '') === 'asignables' && !$this->canAssignTrips()) return $this->failure(403, 'No tenés permiso para asignar recorridos.');
        if ($method !== 'GET') return $this->failure(405, 'Método no permitido.');
        if (array_diff(array_keys($query), ['view', 'id_cuadrilla', 'id_ruta', 'page', 'estado', 'id_recorrido'])) return $this->failure(400, 'Parámetros no admitidos. La identidad se obtiene de la sesión.');
        foreach (['id_cuadrilla', 'id_ruta', 'page', 'id_recorrido'] as $key) {
            if (isset($query[$key]) && (!is_scalar($query[$key]) || !preg_match('/^[1-9][0-9]*$/D', (string) $query[$key]) || (float) $query[$key] > 2147483647)) return $this->failure(400, 'IDs y página deben ser enteros positivos válidos.');
        }
        $estado = $query['estado'] ?? null;
        if ($estado !== null && !in_array($estado, ['Pendiente', 'En Proceso', 'Finalizado', 'Cancelado'], true)) return $this->failure(400, 'Estado de recorrido inválido.');
        if (isset($query['id_ruta']) && !isset($query['id_cuadrilla'])) return $this->failure(400, 'El detalle requiere una cuadrilla.');
        if ($estado !== null && (!isset($query['id_cuadrilla']) || isset($query['id_ruta']))) return $this->failure(400, 'El estado filtra únicamente recorridos de una cuadrilla.');
        if (!$this->db) return $this->failure(503, 'No se pudo consultar la base de datos.');
        try {
            $model = new RecoleccionOperativa($this->db);
            if (!$model->usuarioActivo((int) $user['id_usuario'])) return $this->failure(401, 'La sesión no corresponde a un usuario activo.');
            if (($query['view'] ?? '') === 'permisos') return ['success' => true, 'statusCode' => 200, 'data' => [
                'administracion' => $admin, 'integrantes' => $this->canMembers(false), 'modificar_integrantes' => $this->canMembers(true),
                'asignar_recorridos' => $this->canAssignTrips(),
                'crear_recorridos' => hasEffectivePermission('recorrido.crear', ['OPERACIONES', 'LOGISTICA']) && hasEffectivePermission('ruta.consultar', ['OPERACIONES', 'LOGISTICA']),
                'usuarios' => hasEffectivePermission('usuario.consultar', ['TI', 'OPERACIONES', 'LOGISTICA'])]];
            if (($query['view'] ?? '') === 'asignables') {
                if (!isset($query['id_cuadrilla'])) return $this->failure(400, 'Indicá la cuadrilla.');
                return ['success' => true, 'statusCode' => 200, 'data' => $model->asignables((int) $query['id_cuadrilla'], (int) ($query['page'] ?? 1))];
            }
            if (($query['view'] ?? 'propia') === 'propia') {
                if (isset($query['id_cuadrilla'])) return $this->failure(400, 'La cuadrilla propia se obtiene de la pertenencia vigente, no de parámetros del cliente.');
                if (isset($query['id_ruta'])) return $this->failure(404, 'Asignación no accesible.');
                $data = $model->propia((int) $user['id_usuario'], isset($query['id_recorrido']) ? (int) $query['id_recorrido'] : null);
                if ($data['recorrido'] !== null && $model->compartido((int) $data['recorrido']['id_recorrido'])) return $this->failure(409, 'El recorrido está compartido entre varias cuadrillas. El administrador debe resolver la asignación.', 'recorrido_ambiguo');
                if ($data['recorrido'] === null) return $this->failure(409, 'Tu cuadrilla no tiene un recorrido Pendiente o En Proceso relacionado.', 'sin_recorrido') + ['data' => $data];
                return ['success' => true, 'statusCode' => 200, 'data' => $data, 'puede_operar' => $this->canOperate()];
            }
            if (($query['view'] ?? '') === 'integrantes') {
                if (!$this->canMembers(false)) return $this->failure(403, 'No tenés permiso para consultar integrantes.');
                if (!isset($query['id_cuadrilla'])) return $this->failure(400, 'Indicá la cuadrilla.');
                return ['success' => true, 'statusCode' => 200, 'data' => $model->integrantes((int) $query['id_cuadrilla']), 'puede_gestionar' => $this->canMembers(true)];
            }
            if (isset($query['id_recorrido'])) {
                if (!isset($query['id_cuadrilla'])) return $this->failure(400, 'Indicá la cuadrilla.');
                return ['success' => true, 'statusCode' => 200, 'data' => ['recorrido' => $model->detalle((int) $query['id_cuadrilla'], (int) $query['id_recorrido'])]];
            }
            $data = $model->consultar(isset($query['id_cuadrilla']) ? (int) $query['id_cuadrilla'] : null,
                isset($query['id_ruta']) ? (int) $query['id_ruta'] : null, (int) ($query['page'] ?? 1), $estado);
            if ($data === null) return $this->failure(404, 'Recurso no encontrado en la cuadrilla consultada.');
            if ($data['tipo'] === 'cuadrillas') {
                foreach ($data['lista']['items'] as &$row) {
                    $summary = $model->resumen((int) $row['id_cuadrilla']);
                    if ($summary['recorrido_actual']) unset($summary['recorrido_actual']['contenedores'], $summary['recorrido_actual']['autores']);
                    $row += $summary;
                }
            } elseif ($data['tipo'] === 'recorridos') {
                $data += $model->resumen((int) $data['cuadrilla']['id_cuadrilla']);
                foreach ($data['lista']['items'] as &$row) {
                    $detail = $model->detalle((int) $data['cuadrilla']['id_cuadrilla'], (int) $row['id_recorrido']);
                    $row += array_intersect_key($detail, array_flip(['progreso', 'autores', 'id_usuario_inicio', 'id_usuario_fin']));
                }
            }
            return ['success' => true, 'statusCode' => 200, 'data' => $data];
        } catch (DomainException $e) {
            return $this->failure($e->getCode(), $e->getMessage(), $e->getCode() === 409 ? 'sin_pertenencia' : '');
        } catch (PDOException $e) {
            return $this->failure(503, 'No se pudo consultar la base de datos.');
        }
    }
    private function canAssignTrips(): bool
    {
        return hasEffectivePermission('cuadrilla.consultar', ['OPERACIONES']) && hasEffectivePermission('cuadrilla.modificar', ['OPERACIONES'])
            && hasEffectivePermission('recorrido.consultar', ['OPERACIONES', 'LOGISTICA']) && hasEffectivePermission('recorrido.modificar', ['OPERACIONES']);
    }
    private function canOperate(): bool
    {
        return hasEffectivePermission('recorrido.operar', ['OPERACIONES']) || hasEffectivePermission('recorrido.modificar', ['OPERACIONES']);
    }
    private function canMembers(bool $write): bool
    {
        return hasEffectivePermission('usuario.consultar', ['TI', 'OPERACIONES', 'LOGISTICA'])
            && hasEffectivePermission($write ? 'cuadrilla.modificar' : 'cuadrilla.consultar', ['OPERACIONES']);
    }
    public function modificar(array $body): array
    {
        $user = $_SESSION['usuario']['id_usuario'] ?? null;
        if (!filter_var($user, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) return $this->failure(401, 'No autenticado.');
        $action = $body['accion'] ?? '';
        $assignment = $action === 'asignar_recorrido';
        $membership = in_array($action, ['asignar', 'trasladar', 'finalizar_pertenencia'], true);
        if ($assignment ? !$this->canAssignTrips() : ($membership ? !$this->canMembers(true) : !$this->canOperate())) return $this->failure(403, 'No tenés permiso para esta operación.');
        $allowed = $assignment ? ['accion', 'destino', 'id_recorrido', 'id_usa'] : ($membership ? ['accion', 'integrante', 'destino', 'pertenencia'] : ['accion', 'id_recorrido', 'id_contenedor']);
        if (array_diff(array_keys($body), $allowed)) return $this->failure(400, 'No se admiten identidades operativas ni fechas del cliente.');
        if (!$assignment && !$membership && !in_array($action, ['iniciar', 'atender', 'finalizar'], true)) return $this->failure(400, 'Acción inválida.');
        $required = $assignment ? ['destino', 'id_recorrido', 'id_usa'] : ($membership ? ['integrante'] : ['id_recorrido']);
        if (in_array($action, ['asignar', 'trasladar'], true)) $required[] = 'destino';
        if (in_array($action, ['trasladar', 'finalizar_pertenencia'], true)) $required[] = 'pertenencia';
        if ($action === 'atender') $required[] = 'id_contenedor';
        foreach (array_unique(array_merge($required, array_diff(array_keys($body), ['accion']))) as $field) {
            if (!isset($body[$field]) || !is_scalar($body[$field]) || !preg_match('/^[1-9][0-9]*$/D', (string) $body[$field]) || (float) $body[$field] > 2147483647) return $this->failure(400, 'IDs positivos obligatorios.');
        }
        if (!$this->db) return $this->failure(503, 'No se pudo consultar la base de datos.');
        try {
            $model = new RecoleccionOperativa($this->db);
            if (!$model->usuarioActivo((int) $user)) return $this->failure(401, 'Usuario de sesión inactivo.');
            $data = $assignment ? $model->asignarRecorrido((int) $user, (int) $body['destino'], (int) $body['id_recorrido'], (int) $body['id_usa']) : ($membership
                ? $model->asignar((int) $user, (int) $body['integrante'], isset($body['destino']) ? (int) $body['destino'] : null, $action, isset($body['pertenencia']) ? (int) $body['pertenencia'] : null)
                : $model->operar((int) $user, (int) $body['id_recorrido'], $action, isset($body['id_contenedor']) ? (int) $body['id_contenedor'] : null));
            return ['success' => true, 'statusCode' => 200, 'data' => $data, 'puede_operar' => $this->canOperate()];
        } catch (DomainException $e) {
            return $this->failure($e->getCode(), $e->getMessage());
        } catch (PDOException $e) {
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1062, 1205, 1213], true)) return $this->failure(409, 'La operación entró en conflicto. Volvé a consultar.');
            return $this->failure(503, 'No se pudo guardar la operación. No se aplicaron cambios parciales.');
        }
    }
}
