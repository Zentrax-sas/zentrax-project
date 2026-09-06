<?php
require_once __DIR__ . '/../models/Centro.php';

class CentroController {
    private $centro;

    public function __construct($db) { $this->centro = new Centro($db); }

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
        $direccion = $this->normalizeString($data['direccion'] ?? null);
        $telefono = $this->normalizeString($data['telefono'] ?? null);

        if (!is_string($nombre) || $nombre === '') $errors[] = 'El nombre es obligatorio.';
        elseif (mb_strlen($nombre) > 100) $errors[] = 'El nombre no puede superar los 100 caracteres.';
        if (!is_string($direccion) || $direccion === '') $errors[] = 'La dirección es obligatoria.';
        elseif (mb_strlen($direccion) > 150) $errors[] = 'La dirección no puede superar los 150 caracteres.';
        if ($telefono !== null && $telefono !== '' && !is_string($telefono)) {
            $errors[] = 'El teléfono debe ser texto.';
        } elseif (is_string($telefono) && mb_strlen($telefono) > 20) {
            $errors[] = 'El teléfono no puede superar los 20 caracteres.';
        }
        return $errors;
    }

    private function assignPayload(array $data): void {
        $this->centro->nombre = $this->normalizeString($data['nombre']);
        $this->centro->direccion = $this->normalizeString($data['direccion']);
        $telefono = $this->normalizeString($data['telefono'] ?? null);
        $this->centro->telefono = $telefono === '' ? null : $telefono;
    }

    private function duplicateResponse(): array {
        return $this->response(false, null, 'Ya existe un centro con el mismo nombre y dirección.', ['El centro ya está registrado.'], 409);
    }

    public function getAll(): array {
        try {
            $stmt = $this->centro->read();
            if (!$stmt) return $this->response(false, [], 'No se pudieron cargar los centros.', [], 500);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException | PersistenceException $exception) {
            return $this->response(false, [], 'No se pudieron cargar los centros.', [], 500);
        }
        return $this->response(true, $rows, 'Centros cargados correctamente.', [], 200);
    }

    public function create($data): array {
        $data = is_array($data) ? $data : [];
        $errors = $this->validatePayload($data);
        if ($errors) return $this->response(false, null, 'No se pudo registrar el centro.', $errors, 400);

        $nombre = $this->normalizeString($data['nombre']);
        $direccion = $this->normalizeString($data['direccion']);
        try { $duplicate = $this->centro->findDuplicate($nombre, $direccion); }
        catch (PDOException | PersistenceException $exception) {
            return $this->response(false, null, 'No se pudo registrar el centro.', [], 500);
        }
        if ($duplicate) return $this->duplicateResponse();

        $this->assignPayload($data);
        try { $created = $this->centro->create(); }
        catch (PDOException | PersistenceException $exception) {
            return $this->response(false, null, 'Error al registrar el centro.', [], 500);
        }
        if (!$created) return $this->response(false, null, 'Error al registrar el centro.', [], 500);
        return $this->response(true, ['id_centro' => (int) $this->centro->id_centro], 'Centro registrado con éxito en Zemyna.', [], 201);
    }

    public function update($data): array {
        $data = is_array($data) ? $data : [];
        $errors = $this->validatePayload($data);
        $id = $this->normalizePositiveId($data['id_centro'] ?? null);
        if ($id === null) $errors[] = 'El id_centro debe ser un entero positivo.';
        if ($errors) return $this->response(false, null, 'No se pudo actualizar el centro.', $errors, 400);

        try { $existing = $this->centro->findById($id); }
        catch (PDOException | PersistenceException $exception) {
            return $this->response(false, null, 'No se pudo actualizar el centro.', [], 500);
        }
        if ($existing === null) return $this->response(false, null, 'Centro no encontrado.', ['No existe el centro solicitado.'], 404);

        $nombre = $this->normalizeString($data['nombre']);
        $direccion = $this->normalizeString($data['direccion']);
        try { $duplicate = $this->centro->findDuplicate($nombre, $direccion); }
        catch (PDOException | PersistenceException $exception) {
            return $this->response(false, null, 'No se pudo actualizar el centro.', [], 500);
        }
        if ($duplicate && (int) ($duplicate['id_centro'] ?? 0) !== $id) return $this->duplicateResponse();

        $this->centro->id_centro = $id;
        $this->assignPayload($data);
        try { $updated = $this->centro->update(); }
        catch (PDOException | PersistenceException $exception) {
            return $this->response(false, null, 'Error al actualizar el centro.', [], 500);
        }
        if (!$updated) return $this->response(false, null, 'Error al actualizar el centro.', [], 500);
        return $this->response(true, null, 'Centro actualizado con éxito.', [], 200);
    }

    public function delete($id): array {
        $id = $this->normalizePositiveId($id);
        if ($id === null) return $this->response(false, null, 'El id_centro no es válido.', ['El id_centro debe ser un entero positivo.'], 400);

        try { $existing = $this->centro->findById($id); }
        catch (PDOException | PersistenceException $exception) {
            return $this->response(false, null, 'No se pudo dar de baja el centro.', [], 500);
        }
        if ($existing === null) return $this->response(false, null, 'Centro no encontrado.', ['No existe el centro solicitado.'], 404);
        if ((int) ($existing['activo'] ?? 1) === 0) return $this->response(true, null, 'El centro ya se encontraba dado de baja.', [], 200);

        $this->centro->id_centro = $id;
        try { $deleted = $this->centro->delete(); }
        catch (PDOException | PersistenceException $exception) {
            return $this->response(false, null, 'No se pudo dar de baja el centro.', [], 500);
        }
        if (!$deleted) return $this->response(false, null, 'No se pudo dar de baja el centro.', [], 500);
        return $this->response(true, null, 'Centro dado de baja lógicamente.', [], 200);
    }
}
