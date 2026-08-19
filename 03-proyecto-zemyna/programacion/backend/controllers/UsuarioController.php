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
            $row['roles'] = $this->usuario->getRolesVigentes($row['id_usuario']);
        }

        return [
            "success" => true,
            "data" => $rows,
            "message" => "Usuarios cargados correctamente.",
            "statusCode" => 200
        ];
    }

    public function create($data) {
        $data = $data ?? [];
        $errors = $this->validateUsuarioPayload($data, false);

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

        $this->usuario->nombre = $this->normalizeString($data['nombre'] ?? null);
        $this->usuario->apellido = $this->normalizeString($data['apellido'] ?? null);
        $this->usuario->email = $email;
        $this->usuario->contrasena = password_hash($rawPassword, PASSWORD_BCRYPT);
        $this->usuario->telefono = $this->normalizeString($data['telefono'] ?? null);
        $this->usuario->fecha_registro = $data['fecha_registro'] ?? date('Y-m-d');
        $this->usuario->id_centro = (int)($data['id_centro'] ?? 0);
        $this->usuario->activo = $this->normalizeString($data['activo'] ?? 'Activo');

        if ($this->usuario->create()) {
            return [
                "success" => true,
                "data" => ["id_usuario" => $this->usuario->id_usuario],
                "message" => "Usuario registrado con éxito.",
                "errors" => [],
                "statusCode" => 201
            ];
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

        if (($data['id_usuario'] ?? null) === null || ($data['id_usuario'] ?? null) === '') {
            $errors[] = 'El id_usuario es obligatorio para actualizar.';
        }

        if ($errors) {
            return [
                "success" => false,
                "data" => null,
                "message" => "No se pudo actualizar el usuario.",
                "errors" => $errors,
                "statusCode" => 400
            ];
        }

        $this->usuario->id_usuario = (int)($data['id_usuario'] ?? 0);
        $this->usuario->nombre = $this->normalizeString($data['nombre'] ?? null);
        $this->usuario->apellido = $this->normalizeString($data['apellido'] ?? null);
        $this->usuario->email = $this->normalizeString($data['email'] ?? null);
        $this->usuario->telefono = $this->normalizeString($data['telefono'] ?? null);
        $this->usuario->id_centro = (int)($data['id_centro'] ?? 0);
        $this->usuario->activo = $this->normalizeString($data['activo'] ?? 'Activo');

        $rawPassword = $this->getPasswordValue($data);
        $this->usuario->contrasena = ($rawPassword !== null && $rawPassword !== '')
            ? password_hash($rawPassword, PASSWORD_BCRYPT)
            : null;

        if ($this->usuario->update()) {
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
            "errors" => ["El usuario no existe o la actualización falló."],
            "statusCode" => 404
        ];
    }

    public function delete($id) {
        $this->usuario->id_usuario = (int)$id;

        if ($this->usuario->delete()) {
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
            "message" => "Usuario no encontrado.",
            "errors" => ["No existe el usuario solicitado."],
            "statusCode" => 404
        ];
    }

    public function activar($id) {
        $this->usuario->id_usuario = (int)$id;

        if ($this->usuario->activar()) {
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
            "errors" => ["No existe el usuario solicitado."],
            "statusCode" => 404
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