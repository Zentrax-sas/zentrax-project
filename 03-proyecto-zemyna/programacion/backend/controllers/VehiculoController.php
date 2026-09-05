<?php
require_once __DIR__ . '/../models/Vehiculo.php';

class VehiculoController {
    private $vehiculo;

    public function __construct($db) {
        $this->vehiculo = new Vehiculo($db);
    }

    private function response($success, $data, $message, $errors, $statusCode) {
        return compact('success', 'data', 'message', 'errors', 'statusCode');
    }

    private function normalizeString($value) {
        return is_string($value) ? trim($value) : $value;
    }

    private function isDuplicateKey(PDOException $exception): bool {
        $errorInfo = $exception->errorInfo ?? null;
        return is_array($errorInfo)
            && (string) ($errorInfo[0] ?? '') === '23000'
            && (int) ($errorInfo[1] ?? 0) === 1062;
    }

    private function isForeignKeyViolation(PDOException $exception): bool {
        $errorInfo = $exception->errorInfo ?? null;
        return is_array($errorInfo)
            && (string) ($errorInfo[0] ?? '') === '23000'
            && (int) ($errorInfo[1] ?? 0) === 1452;
    }

    private function writeExceptionResponse(PDOException $exception, string $fallbackMessage) {
        if ($this->isDuplicateKey($exception)) {
            return $this->response(false, null, 'La matrícula ya está registrada.', ['La matrícula ya existe.'], 409);
        }
        if ($this->isForeignKeyViolation($exception)) {
            return $this->response(
                false,
                null,
                'El tipo de residuo indicado no es válido.',
                ['El id_tipo_residuo no corresponde a un registro existente.'],
                400
            );
        }
        return $this->response(false, null, $fallbackMessage, [], 500);
    }

    private function normalizePositiveId($value): ?int {
        if (is_int($value)) return $value > 0 ? $value : null;
        if (!is_string($value) || !ctype_digit($value)) return null;
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function validatePayload($data) {
        $errors = [];
        $matricula = $this->normalizeString($data['matricula'] ?? null);
        $marca = $this->normalizeString($data['marca'] ?? null);
        $modelo = $this->normalizeString($data['modelo'] ?? null);
        $capacidad = $data['capacidad_carga'] ?? null;
        $estado = $this->normalizeString($data['estado'] ?? null);

        if (!is_string($matricula) || $matricula === '') $errors[] = 'La matrícula es obligatoria.';
        elseif (mb_strlen($matricula) > 10) $errors[] = 'La matrícula no puede superar los 10 caracteres.';

        if (!is_string($marca) || $marca === '') $errors[] = 'La marca es obligatoria.';
        elseif (mb_strlen($marca) > 50) $errors[] = 'La marca no puede superar los 50 caracteres.';

        if (!is_string($modelo) || $modelo === '') $errors[] = 'El modelo es obligatorio.';
        elseif (mb_strlen($modelo) > 50) $errors[] = 'El modelo no puede superar los 50 caracteres.';

        if (!is_numeric($capacidad) || (float) $capacidad <= 0) {
            $errors[] = 'La capacidad de carga debe ser un número positivo.';
        }
        if (!in_array($estado, ['Disponible', 'En Servicio', 'En Mantenimiento'], true)) {
            $errors[] = 'El estado debe ser Disponible, En Servicio o En Mantenimiento.';
        }
        if ($this->normalizePositiveId($data['id_tipo_residuo'] ?? null) === null) {
            $errors[] = 'El id_tipo_residuo debe ser un entero positivo.';
        }
        return $errors;
    }

    private function assignPayload($data) {
        $this->vehiculo->matricula = $this->normalizeString($data['matricula']);
        $this->vehiculo->marca = $this->normalizeString($data['marca']);
        $this->vehiculo->modelo = $this->normalizeString($data['modelo']);
        $this->vehiculo->capacidad_carga = (float) $data['capacidad_carga'];
        $this->vehiculo->estado = $this->normalizeString($data['estado']);
        $this->vehiculo->id_tipo_residuo = $this->normalizePositiveId($data['id_tipo_residuo']);
    }

    public function getAll() {
        try {
            $stmt = $this->vehiculo->read();
            if (!$stmt) return $this->response(false, [], 'No se pudieron cargar los vehículos.', [], 500);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException | PersistenceException $exception) {
            return $this->response(false, [], 'No se pudieron cargar los vehículos.', [], 500);
        }
        return $this->response(true, $rows, 'Vehículos cargados correctamente.', [], 200);
    }

    public function create($data) {
        $data = $data ?? [];
        $errors = $this->validatePayload($data);
        if ($errors) return $this->response(false, null, 'No se pudo registrar el vehículo.', $errors, 400);

        $matricula = $this->normalizeString($data['matricula']);
        try {
            $existing = $this->vehiculo->findByMatricula($matricula);
        } catch (PDOException | PersistenceException $exception) {
            return $this->response(false, null, 'No se pudo registrar el vehículo.', [], 500);
        }
        if ($existing) {
            return $this->response(false, null, 'La matrícula ya está registrada.', ['La matrícula ya existe.'], 409);
        }

        $this->assignPayload($data);
        try { $created = $this->vehiculo->create(); }
        catch (PDOException $exception) {
            return $this->writeExceptionResponse($exception, 'Error al registrar el vehículo.');
        }
        catch (PersistenceException $exception) { $created = false; }

        if (!$created) return $this->response(false, null, 'Error al registrar el vehículo.', [], 500);
        return $this->response(true, null, 'Vehículo registrado con éxito en Zemyna.', [], 201);
    }

    public function update($data) {
        $data = $data ?? [];
        $errors = $this->validatePayload($data);
        $id = $this->normalizePositiveId($data['id_vehiculo'] ?? null);
        if ($id === null) $errors[] = 'El id_vehiculo debe ser un entero positivo.';
        if ($errors) return $this->response(false, null, 'No se pudo actualizar el vehículo.', $errors, 400);

        try { $existing = $this->vehiculo->findById($id); }
        catch (PDOException | PersistenceException $exception) {
            return $this->response(false, null, 'No se pudo actualizar el vehículo.', [], 500);
        }
        if ($existing === false) return $this->response(false, null, 'No se pudo actualizar el vehículo.', [], 500);
        if ($existing === null) {
            return $this->response(false, null, 'Vehículo no encontrado.', ['No existe el vehículo solicitado.'], 404);
        }

        $matricula = $this->normalizeString($data['matricula']);
        try { $owner = $this->vehiculo->findByMatricula($matricula); }
        catch (PDOException | PersistenceException $exception) {
            return $this->response(false, null, 'No se pudo actualizar el vehículo.', [], 500);
        }
        if ($owner && (int) ($owner['id_vehiculo'] ?? 0) !== $id) {
            return $this->response(false, null, 'La matrícula pertenece a otro vehículo.', ['La matrícula ya existe.'], 409);
        }

        $this->vehiculo->id_vehiculo = $id;
        $this->assignPayload($data);
        try { $updated = $this->vehiculo->update(); }
        catch (PDOException $exception) {
            return $this->writeExceptionResponse($exception, 'Error al actualizar el vehículo.');
        }
        catch (PersistenceException $exception) { $updated = false; }

        if (!$updated) return $this->response(false, null, 'Error al actualizar el vehículo.', [], 500);
        return $this->response(true, null, 'Vehículo actualizado con éxito.', [], 200);
    }

    public function delete($id) {
        $id = $this->normalizePositiveId($id);
        if ($id === null) {
            return $this->response(false, null, 'El id_vehiculo no es válido.', ['El id_vehiculo debe ser un entero positivo.'], 400);
        }

        try { $existing = $this->vehiculo->findById($id); }
        catch (PDOException | PersistenceException $exception) {
            return $this->response(false, null, 'No se pudo dar de baja el vehículo.', [], 500);
        }
        if ($existing === false) return $this->response(false, null, 'No se pudo dar de baja el vehículo.', [], 500);
        if ($existing === null) {
            return $this->response(false, null, 'Vehículo no encontrado.', ['No existe el vehículo solicitado.'], 404);
        }
        if ((int) ($existing['activo'] ?? 1) === 0) {
            return $this->response(true, null, 'El vehículo ya se encontraba dado de baja.', [], 200);
        }

        $this->vehiculo->id_vehiculo = $id;
        try { $deleted = $this->vehiculo->delete(); }
        catch (PDOException | PersistenceException $exception) { $deleted = false; }

        if (!$deleted) return $this->response(false, null, 'No se pudo dar de baja el vehículo.', [], 500);
        return $this->response(true, null, 'Vehículo dado de baja lógicamente.', [], 200);
    }
}
