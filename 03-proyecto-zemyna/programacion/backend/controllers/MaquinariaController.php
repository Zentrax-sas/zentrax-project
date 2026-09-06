<?php
require_once __DIR__ . '/../models/Maquinaria.php';

class MaquinariaController {
    private $maquinaria;
    private const ESTADOS = ['Disponible', 'En Uso', 'En Mantenimiento'];

    public function __construct($db) { $this->maquinaria = new Maquinaria($db); }

    private function response(bool $success, $data, string $message, array $errors, int $statusCode): array {
        return compact('success', 'data', 'message', 'errors', 'statusCode');
    }

    private function normalizeString($value) { return is_string($value) ? trim($value) : $value; }

    private function normalizePositiveId($value): ?int {
        if (is_int($value)) return $value > 0 ? $value : null;
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/', $value)) return null;
        return (int) $value;
    }

    private function validatePayload(array $data): array {
        $errors = [];
        $nombre = $this->normalizeString($data['nombre'] ?? null);
        $tipo = $this->normalizeString($data['tipo'] ?? null);
        $estado = $this->normalizeString($data['estado'] ?? null);
        if (!is_string($nombre) || $nombre === '') $errors[] = 'El nombre es obligatorio.';
        elseif (mb_strlen($nombre) > 50) $errors[] = 'El nombre no puede superar los 50 caracteres.';
        if (!is_string($tipo) || $tipo === '') $errors[] = 'El tipo es obligatorio.';
        elseif (mb_strlen($tipo) > 50) $errors[] = 'El tipo no puede superar los 50 caracteres.';
        if (!is_string($estado) || !in_array($estado, self::ESTADOS, true)) $errors[] = 'El estado debe ser Disponible, En Uso o En Mantenimiento.';
        if ($this->normalizePositiveId($data['id_centro'] ?? null) === null) $errors[] = 'El id_centro debe ser un entero positivo.';
        return $errors;
    }

    private function assignPayload(array $data): void {
        $this->maquinaria->nombre = $this->normalizeString($data['nombre']);
        $this->maquinaria->tipo = $this->normalizeString($data['tipo']);
        $this->maquinaria->estado = $this->normalizeString($data['estado']);
        $this->maquinaria->id_centro = $this->normalizePositiveId($data['id_centro']);
    }

    private function duplicateResponse(): array {
        return $this->response(false, null, 'Ya existe maquinaria con el mismo nombre en ese centro.', ['La maquinaria ya está registrada en el centro.'], 409);
    }

    private function centroNotFoundResponse(): array {
        return $this->response(false, null, 'Centro no encontrado.', ['No existe un centro activo con el ID indicado.'], 404);
    }

    public function getAll(): array {
        try {
            $stmt = $this->maquinaria->read();
            if (!$stmt) return $this->response(false, [], 'No se pudo cargar la maquinaria.', [], 500);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException | PersistenceException $exception) {
            return $this->response(false, [], 'No se pudo cargar la maquinaria.', [], 500);
        }
        return $this->response(true, $rows, 'Maquinaria cargada correctamente.', [], 200);
    }

    public function create($data): array {
        $data = is_array($data) ? $data : [];
        $errors = $this->validatePayload($data);
        if ($errors) return $this->response(false, null, 'No se pudo registrar la maquinaria.', $errors, 400);
        $nombre = $this->normalizeString($data['nombre']);
        $idCentro = $this->normalizePositiveId($data['id_centro']);
        try {
            $centro = $this->maquinaria->findCentroById($idCentro);
            if ($centro === false) return $this->response(false, null, 'No se pudo registrar la maquinaria.', [], 500);
            if ($centro === null) return $this->centroNotFoundResponse();
            $duplicate = $this->maquinaria->findDuplicate($nombre, $idCentro);
            if ($duplicate === false) return $this->response(false, null, 'No se pudo registrar la maquinaria.', [], 500);
        } catch (PDOException | PersistenceException $exception) {
            return $this->response(false, null, 'No se pudo registrar la maquinaria.', [], 500);
        }
        if ($duplicate) return $this->duplicateResponse();
        $this->assignPayload($data);
        try { $created = $this->maquinaria->create(); }
        catch (PDOException | PersistenceException $exception) { return $this->response(false, null, 'Error al registrar la maquinaria.', [], 500); }
        if (!$created) return $this->response(false, null, 'Error al registrar la maquinaria.', [], 500);
        return $this->response(true, ['id_maquinaria' => (int) $this->maquinaria->id_maquinaria], 'Maquinaria registrada con éxito en Zemyna.', [], 201);
    }

    public function update($data): array {
        $data = is_array($data) ? $data : [];
        $errors = $this->validatePayload($data);
        $id = $this->normalizePositiveId($data['id_maquinaria'] ?? null);
        if ($id === null) $errors[] = 'El id_maquinaria debe ser un entero positivo.';
        if ($errors) return $this->response(false, null, 'No se pudo actualizar la maquinaria.', $errors, 400);
        $idCentro = $this->normalizePositiveId($data['id_centro']);
        $nombre = $this->normalizeString($data['nombre']);
        try {
            $existing = $this->maquinaria->findById($id);
            if ($existing === false) return $this->response(false, null, 'No se pudo actualizar la maquinaria.', [], 500);
            if ($existing === null) return $this->response(false, null, 'Maquinaria no encontrada.', ['No existe la maquinaria solicitada.'], 404);
            $centro = $this->maquinaria->findCentroById($idCentro);
            if ($centro === false) return $this->response(false, null, 'No se pudo actualizar la maquinaria.', [], 500);
            if ($centro === null) return $this->centroNotFoundResponse();
            $duplicate = $this->maquinaria->findDuplicate($nombre, $idCentro);
            if ($duplicate === false) return $this->response(false, null, 'No se pudo actualizar la maquinaria.', [], 500);
        } catch (PDOException | PersistenceException $exception) {
            return $this->response(false, null, 'No se pudo actualizar la maquinaria.', [], 500);
        }
        if ($duplicate && (int) ($duplicate['id_maquinaria'] ?? 0) !== $id) return $this->duplicateResponse();
        $this->maquinaria->id_maquinaria = $id;
        $this->assignPayload($data);
        try { $updated = $this->maquinaria->update(); }
        catch (PDOException | PersistenceException $exception) { return $this->response(false, null, 'Error al actualizar la maquinaria.', [], 500); }
        if (!$updated) return $this->response(false, null, 'Error al actualizar la maquinaria.', [], 500);
        return $this->response(true, null, 'Maquinaria actualizada con éxito.', [], 200);
    }

    public function delete($id): array {
        $id = $this->normalizePositiveId($id);
        if ($id === null) return $this->response(false, null, 'El id_maquinaria no es válido.', ['El id_maquinaria debe ser un entero positivo.'], 400);
        try { $existing = $this->maquinaria->findById($id); }
        catch (PDOException | PersistenceException $exception) { return $this->response(false, null, 'No se pudo dar de baja la maquinaria.', [], 500); }
        if ($existing === false) return $this->response(false, null, 'No se pudo dar de baja la maquinaria.', [], 500);
        if ($existing === null) return $this->response(false, null, 'Maquinaria no encontrada.', ['No existe la maquinaria solicitada.'], 404);
        if ((int) ($existing['activo'] ?? 1) === 0) return $this->response(true, null, 'La maquinaria ya se encontraba dada de baja.', [], 200);
        $this->maquinaria->id_maquinaria = $id;
        try { $deleted = $this->maquinaria->delete(); }
        catch (PDOException | PersistenceException $exception) { return $this->response(false, null, 'No se pudo dar de baja la maquinaria.', [], 500); }
        if (!$deleted) return $this->response(false, null, 'No se pudo dar de baja la maquinaria.', [], 500);
        return $this->response(true, null, 'Maquinaria dada de baja lógicamente.', [], 200);
    }
}
