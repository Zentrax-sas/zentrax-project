<?php
require_once __DIR__ . '/../models/UsuarioRol.php';

class UsuarioRolController {
    private $usuarioRol;

    public function __construct($db) {
        $this->usuarioRol = new UsuarioRol($db);
    }

    public function getByUsuario($idUsuario) {
        if (!$idUsuario || !ctype_digit((string)$idUsuario)) {
            return [
                "success" => false,
                "data" => [],
                "message" => "El id_usuario es inválido.",
                "statusCode" => 400
            ];
        }

        $stmt = $this->usuarioRol->readByUsuario((int)$idUsuario);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return [
            "success" => true,
            "data" => $rows,
            "message" => "Roles del usuario cargados correctamente.",
            "statusCode" => 200
        ];
    }

    public function create($data) {
        $data = $data ?? [];
        $errors = [];

        $idUsuario = $data['id_usuario'] ?? null;
        $idRol = $data['id_rol'] ?? null;
        $sector = trim($data['sector'] ?? '');
        $fechaDesde = $data['fecha_desde'] ?? date('Y-m-d');
        $fechaHasta = $data['fecha_hasta'] ?? null;

        if (!$idUsuario || !ctype_digit((string)$idUsuario)) {
            $errors[] = 'El id_usuario es obligatorio y debe ser válido.';
        }

        if (!$idRol || !ctype_digit((string)$idRol)) {
            $errors[] = 'El id_rol es obligatorio y debe ser válido.';
        }

        if ($sector === '') {
            $errors[] = 'El sector es obligatorio.';
        }

        if ($fechaHasta !== null && $fechaHasta !== '' && $fechaHasta < $fechaDesde) {
            $errors[] = 'La fecha_hasta no puede ser anterior a fecha_desde.';
        }

        if ($errors) {
            return [
                "success" => false,
                "data" => null,
                "message" => "No se pudo asignar el rol.",
                "errors" => $errors,
                "statusCode" => 400
            ];
        }

        if ($this->usuarioRol->existeRolVigente((int)$idUsuario, (int)$idRol, $sector)) {
            return [
                "success" => false,
                "data" => null,
                "message" => "El usuario ya tiene ese rol vigente en el sector indicado.",
                "statusCode" => 409
            ];
        }

        $this->usuarioRol->id_usuario = (int)$idUsuario;
        $this->usuarioRol->id_rol = (int)$idRol;
        $this->usuarioRol->sector = $sector;
        $this->usuarioRol->fecha_desde = $fechaDesde;
        $this->usuarioRol->fecha_hasta = ($fechaHasta === '') ? null : $fechaHasta;

        if ($this->usuarioRol->create()) {
            return [
                "success" => true,
                "data" => ["id_usuario_rol" => $this->usuarioRol->id_usuario_rol],
                "message" => "Rol asignado correctamente.",
                "statusCode" => 201
            ];
        }

        return [
            "success" => false,
            "data" => null,
            "message" => "No se pudo asignar el rol.",
            "statusCode" => 500
        ];
    }

    public function finalizar($data) {
        $id = $data['id_usuario_rol'] ?? null;
        $fechaHasta = $data['fecha_hasta'] ?? date('Y-m-d');

        if (!$id || !ctype_digit((string)$id)) {
            return [
                "success" => false,
                "message" => "El id_usuario_rol es obligatorio.",
                "statusCode" => 400
            ];
        }

        $asignacion = $this->usuarioRol->findById((int)$id);

        if (!$asignacion) {
            return [
                "success" => false,
                "message" => "La asignación de rol no existe.",
                "statusCode" => 404
            ];
        }

        if ($fechaHasta < $asignacion['fecha_desde']) {
            return [
                "success" => false,
                "message" => "La fecha de finalización no puede ser anterior a fecha_desde.",
                "statusCode" => 400
            ];
        }

        $this->usuarioRol->id_usuario_rol = (int)$id;
        $this->usuarioRol->fecha_hasta = $fechaHasta;

        if ($this->usuarioRol->finalizar()) {
            return [
                "success" => true,
                "data" => null,
                "message" => "Rol finalizado correctamente.",
                "statusCode" => 200
            ];
        }

        return [
            "success" => false,
            "message" => "No se pudo finalizar el rol.",
            "statusCode" => 500
        ];
    }
}