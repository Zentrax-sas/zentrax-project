<?php
require_once __DIR__ . '/../models/Usuario.php';

class UsuarioController {
    private $usuario;

    public function __construct($db) {
        $this->usuario = new Usuario($db);
    }

    private function normalizeString($value) {
        return is_string($value) ? trim($value) : $value;
    }

    private function getPasswordValue(array $data): ?string {
        $value = $data['contrasena'] ?? null;
        return is_string($value) ? trim($value) : $value;
    }

    private function normalizePositiveId($value): ?int {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }

        $id = (int)$value;
        return $id > 0 ? $id : null;
    }

    private function findUsuarioById(int $id) {
        try {
            $stmt = $this->usuario->read($id, 1, 1);
            if (!$stmt) {
                return false;
            }

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows[0] ?? null;
        } catch (PDOException | RuntimeException $exception) {
            return false;
        }
    }

    private function validateUsuarioPayload($data, $isUpdate = false) {
        $errors = [];

        $nombre = $this->normalizeString($data['nombre'] ?? null);
        $apellido = $this->normalizeString($data['apellido'] ?? null);
        $email = $this->normalizeString($data['email'] ?? null);
        $telefono = $this->normalizeString($data['telefono'] ?? null);
     $idCentro = $data['id_centro'] ?? null;
        $activo = $this->normalizeString($data['activo'] ?? 'Activo');

        if ($nombre === null || $nombre === '') $errors[] = 'El nombre es obligatorio.';
        elseif (mb_strlen($nombre) > 50) $errors[] = 'El nombre no puede superar los 50 caracteres.';

        if ($apellido === null || $apellido === '') $errors[] = 'El apellido es obligatorio.';
        elseif (mb_strlen($apellido) > 50) $errors[] = 'El apellido no puede superar los 50 caracteres.';

        if ($email === null || $email === '') $errors[] = 'El email es obligatorio.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'El email no tiene un formato válido.';
        elseif (mb_strlen($email) > 100) $errors[] = 'El email no puede superar los 100 caracteres.';

        if (!$isUpdate || (isset($data['contrasena']) && $data['contrasena'] !== '')) {
            $rawPassword = $this->getPasswordValue($data);
            if ($rawPassword === null || $rawPassword === '') $errors[] = 'La contraseña es obligatoria.';
            elseif (!is_string($rawPassword) || mb_strlen($rawPassword) < 6 || mb_strlen($rawPassword) > 72) {
                $errors[] = 'La contraseña debe tener entre 6 y 72 caracteres.';
            }
        }

        if ($telefono === null || $telefono === '') $errors[] = 'El teléfono es obligatorio.';
        elseif (!preg_match('/^[0-9+()\-\s]{6,20}$/', $telefono)) $errors[] = 'El teléfono tiene un formato inválido.';

        if ($idCentro === null || $idCentro === '' || !ctype_digit((string)$idCentro)) {
            $errors[] = 'El id_centro debe ser un número entero válido.';
        }

        if (!in_array($activo, ['Activo', 'Inactivo'], true)) {
            $errors[] = 'El estado del usuario debe ser Activo o Inactivo.';
        }

        return $errors;
    }

    private function validDate($value): bool {
        if (!is_string($value)) return false;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function validateRolePayload(array $data): array {
        $errors = [];
        if ($this->normalizePositiveId($data['id_rol'] ?? null) === null) $errors[] = 'El rol es obligatorio.';
        $sector = $this->normalizeString($data['sector'] ?? null);
        if (!is_string($sector) || $sector === '') $errors[] = 'El sector es obligatorio.';
        elseif (mb_strlen($sector) > 100) $errors[] = 'El sector no puede superar los 100 caracteres.';
        $desde = $data['fecha_desde'] ?? date('Y-m-d');
        $hasta = $data['fecha_hasta'] ?? null;
        if (!$this->validDate($desde)) $errors[] = 'La fecha_desde no es válida.';
        if ($hasta !== null && $hasta !== '' && !$this->validDate($hasta)) $errors[] = 'La fecha_hasta no es válida.';
        if ($this->validDate($desde) && $hasta !== null && $hasta !== '' && $this->validDate($hasta) && $hasta < $desde) {
            $errors[] = 'La fecha_hasta no puede ser anterior a fecha_desde.';
        }
        return $errors;
    }

    public function getRoleOptions() {
        try {
            return [
                'success' => true,
                'data' => ['roles' => $this->usuario->getRolesDisponibles(), 'sectores' => $this->usuario->getSectoresDisponibles()],
                'message' => 'Roles y sectores cargados correctamente.',
                'errors' => [],
                'statusCode' => 200,
            ];
        } catch (PDOException | RuntimeException $exception) {
            return ['success' => false, 'data' => ['roles' => [], 'sectores' => []], 'message' => 'No se pudieron cargar roles y sectores.', 'errors' => [], 'statusCode' => 500];
        }
    }

    public function getAll($filters = []) {
        $id = isset($filters['id']) ? (int)$filters['id'] : null;
        $page = isset($filters['page']) ? max(1, (int)$filters['page']) : 1;
        $limit = isset($filters['limit']) ? max(1, min(100, (int)$filters['limit'])) : 20;

        $stmt = $this->usuario->read($id, $page, $limit);

        if (!$stmt) {
            return [
                "success" => false,
                "data" => [],
                "message" => "No se pudieron cargar los usuarios.",
                "statusCode" => 500
            ];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($id !== null && empty($rows)) {
            return [
                "success" => false,
                "data" => [],
                "message" => "Usuario no encontrado.",
                "statusCode" => 404
            ];
        }

        foreach ($rows as &$row) {
            unset($row['contrasena']);
            $row['roles'] = $this->usuario->getRolesVigentes($row['id_usuario']);
        }
        unset($row);

        return [
            "success" => true,
            "data" => $rows,
            "message" => "Usuarios cargados correctamente.",
            "statusCode" => 200
        ];
    }

    public function create($data) {
        $data = $data ?? [];
        $errors = array_merge($this->validateUsuarioPayload($data, false), $this->validateRolePayload($data));

        if ($errors) {
            return [
                "success" => false,
                "data" => null,
                "message" => "No se pudo registrar el usuario.",
                "errors" => $errors,
                "statusCode" => 400
            ];
        }

           $email = $this->normalizeString($data['email'] ?? null);
           $rawPassword = $this->getPasswordValue($data);

        if ($email && $this->usuario->findByEmail($email)) {
            return [
                "success" => false,
                "data" => null,
                "message" => "El email ya está registrado.",
                "errors" => ["El email ya existe."],
                "statusCode" => 409
            ];
        }

        $idRol = $this->normalizePositiveId($data['id_rol'] ?? null);
        $sector = strtoupper($this->normalizeString($data['sector'] ?? ''));
        $fechaDesde = $data['fecha_desde'] ?? date('Y-m-d');
        $fechaHasta = ($data['fecha_hasta'] ?? null) ?: null;

        try {
            if (!$this->usuario->findRoleById($idRol)) {
                return ['success' => false, 'data' => null, 'message' => 'El rol seleccionado no existe.', 'errors' => ['Seleccioná un rol válido.'], 'statusCode' => 404];
            }
            if (!$this->usuario->findSectorByName($sector)) {
                return ['success' => false, 'data' => null, 'message' => 'El sector seleccionado no es válido.', 'errors' => ['Seleccioná un sector admitido.'], 'statusCode' => 400];
            }
        } catch (PDOException | RuntimeException $exception) {
            return ['success' => false, 'data' => null, 'message' => 'No se pudo validar el rol del usuario.', 'errors' => [], 'statusCode' => 500];
        }

        $this->usuario->nombre = $this->normalizeString($data['nombre'] ?? null);
        $this->usuario->apellido = $this->normalizeString($data['apellido'] ?? null);
        $this->usuario->email = $email;
        $this->usuario->contrasena = password_hash($rawPassword, PASSWORD_BCRYPT);
        $this->usuario->telefono = $this->normalizeString($data['telefono'] ?? null);
        $this->usuario->fecha_registro = $data['fecha_registro'] ?? date('Y-m-d');
        $this->usuario->id_centro = (int)($data['id_centro'] ?? 0);
        $this->usuario->activo = $this->normalizeString($data['activo'] ?? 'Activo');

        try {
            if (!$this->usuario->beginTransaction()) throw new RuntimeException('No se pudo iniciar la transacción.');
            if (!$this->usuario->create()) throw new RuntimeException('No se pudo crear el usuario.');
            if (!$this->usuario->assignRole((int)$this->usuario->id_usuario, $idRol, $sector, $fechaDesde, $fechaHasta)) {
                throw new RuntimeException('No se pudo asignar el rol.');
            }
            if (!$this->usuario->commit()) throw new RuntimeException('No se pudo confirmar la transacción.');
            return ['success' => true, 'data' => ['id_usuario' => (int)$this->usuario->id_usuario], 'message' => 'Usuario y rol registrados con éxito.', 'errors' => [], 'statusCode' => 201];
        } catch (PDOException | RuntimeException $exception) {
            try { $this->usuario->rollBack(); } catch (Throwable $ignored) {}
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "Error al registrar el usuario.",
            "errors" => [],
            "statusCode" => 500
        ];
    }

    public function update($data) {
        $data = $data ?? [];
        $errors = $this->validateUsuarioPayload($data, true);

        $id = $this->normalizePositiveId($data['id_usuario'] ?? null);
        if ($id === null) $errors[] = 'El id_usuario debe ser un número entero positivo.';

        if ($errors) {
            return [
                "success" => false,
                "data" => null,
                "message" => "No se pudo actualizar el usuario.",
                "errors" => $errors,
                "statusCode" => 400
            ];
        }

        $existing = $this->findUsuarioById($id);
        if ($existing === false) {
            return [
                "success" => false,
                "data" => null,
                "message" => "No se pudo actualizar el usuario.",
                "errors" => ["La verificación del usuario falló."],
                "statusCode" => 500
            ];
        }
        if ($existing === null) {
            return [
                "success" => false,
                "data" => null,
                "message" => "No se pudo actualizar el usuario.",
                "errors" => ["El usuario no existe o la actualización falló."],
                "statusCode" => 404
            ];
        }

        $email = $this->normalizeString($data['email'] ?? null);
        try {
            $emailOwner = $this->usuario->findByEmail($email);
        } catch (PDOException | RuntimeException $exception) {
            return [
                "success" => false,
                "data" => null,
                "message" => "No se pudo actualizar el usuario.",
                "errors" => ["La verificación del email falló."],
                "statusCode" => 500
            ];
        }
        if ($emailOwner && (int)$emailOwner['id_usuario'] !== $id) {
            return [
                "success" => false,
                "data" => null,
                "message" => "El email ya está registrado.",
                "errors" => ["El email ya existe."],
                "statusCode" => 409
            ];
        }

        $this->usuario->id_usuario = $id;
        $this->usuario->nombre = $this->normalizeString($data['nombre'] ?? null);
        $this->usuario->apellido = $this->normalizeString($data['apellido'] ?? null);
        $this->usuario->email = $email;
        $this->usuario->telefono = $this->normalizeString($data['telefono'] ?? null);
        $this->usuario->id_centro = (int)($data['id_centro'] ?? 0);
        $this->usuario->activo = $this->normalizeString($data['activo'] ?? 'Activo');

        $rawPassword = $this->getPasswordValue($data);
        $this->usuario->contrasena = ($rawPassword !== null && $rawPassword !== '')
            ? password_hash($rawPassword, PASSWORD_BCRYPT)
            : null;

        try {
            $updated = $this->usuario->update();
        } catch (PDOException | RuntimeException $exception) {
            $updated = false;
        }

        if ($updated) {
            return [
                "success" => true,
                "data" => null,
                "message" => "Usuario actualizado con éxito.",
                "errors" => [],
                "statusCode" => 200
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "No se pudo actualizar el usuario.",
            "errors" => ["La actualización del usuario falló."],
            "statusCode" => 500
        ];
    }

    public function delete($id) {
        $normalizedId = $this->normalizePositiveId($id);
        if ($normalizedId === null) {
            return [
                "success" => false, "data" => null,
                "message" => "El id_usuario no es válido.",
                "errors" => ["El id_usuario debe ser un número entero positivo."],
                "statusCode" => 400
            ];
        }

        $existing = $this->findUsuarioById($normalizedId);
        if ($existing === false) {
            return [
                "success" => false, "data" => null,
                "message" => "No se pudo desactivar el usuario.",
                "errors" => ["La verificación del usuario falló."],
                "statusCode" => 500
            ];
        }
        if ($existing === null) {
            return [
                "success" => false, "data" => null,
                "message" => "Usuario no encontrado.",
                "errors" => ["No existe el usuario solicitado."],
                "statusCode" => 404
            ];
        }

        $this->usuario->id_usuario = $normalizedId;

        try {
            $deleted = $this->usuario->delete();
        } catch (PDOException | RuntimeException $exception) {
            $deleted = false;
        }

        if ($deleted) {
            return [
                "success" => true,
                "data" => null,
                "message" => "Usuario desactivado con éxito.",
                "errors" => [],
                "statusCode" => 200
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "No se pudo desactivar el usuario.",
            "errors" => ["La desactivación del usuario falló."],
            "statusCode" => 500
        ];
    }

    public function activar($id) {
        $normalizedId = $this->normalizePositiveId($id);
        if ($normalizedId === null) {
            return [
                "success" => false, "data" => null,
                "message" => "El id_usuario no es válido.",
                "errors" => ["El id_usuario debe ser un número entero positivo."],
                "statusCode" => 400
            ];
        }

        $existing = $this->findUsuarioById($normalizedId);
        if ($existing === false) {
            return [
                "success" => false, "data" => null,
                "message" => "No se pudo activar el usuario.",
                "errors" => ["La verificación del usuario falló."],
                "statusCode" => 500
            ];
        }
        if ($existing === null) {
            return [
                "success" => false, "data" => null,
                "message" => "No se pudo activar el usuario.",
                "errors" => ["No existe el usuario solicitado."],
                "statusCode" => 404
            ];
        }

        $this->usuario->id_usuario = $normalizedId;

        try {
            $activated = $this->usuario->activar();
        } catch (PDOException | RuntimeException $exception) {
            $activated = false;
        }

        if ($activated) {
            return [
                "success" => true,
                "data" => null,
                "message" => "Usuario activado con éxito.",
                "errors" => [],
                "statusCode" => 200
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "No se pudo activar el usuario.",
            "errors" => ["La activación del usuario falló."],
            "statusCode" => 500
        ];
    }

    public function historialRoles($id) {
        return [
            "success" => true,
            "data" => $this->usuario->getHistorialRoles((int)$id),
            "message" => "Historial de roles cargado correctamente.",
            "statusCode" => 200
        ];
    }
}
