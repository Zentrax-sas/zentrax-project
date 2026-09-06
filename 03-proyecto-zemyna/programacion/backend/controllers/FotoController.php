<?php
require_once __DIR__ . '/../models/Foto.php';
require_once __DIR__ . '/../helpers/FotoStorage.php';

class FotoController {
    private $foto;

    public function __construct($db) {
        $this->foto = new Foto($db);
    }

    public function getAll() {
        $stmt = $this->foto->read();
        if (!$stmt) {
            return [
                "success" => false,
                "data" => [],
                "message" => "No se pudo conectar con la base de datos de fotos.",
                "statusCode" => 500
            ];
        }

        return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC), "message" => "Fotos cargadas correctamente.", "statusCode" => 200];
    }

    public function create($data) {
        $this->foto->fecha         = $data['fecha']         ?? date('Y-m-d H:i:s');
        $this->foto->url           = FotoStorage::extractSafeFileName((string)($data['url'] ?? ''));
        $this->foto->id_incidencia = $data['id_incidencia'] ?? null;

        $errors = [];
        if (empty($this->foto->url))           $errors[] = "La URL de la foto es obligatoria.";
        if (empty($this->foto->id_incidencia)) $errors[] = "El id_incidencia es obligatorio.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo registrar la foto.", "errors" => $errors];
        }

        if ($this->foto->create()) {
            return ["success" => true, "data" => null, "message" => "Foto registrada con éxito en Zemyna.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al registrar la foto.", "errors" => []];
    }

    public function update($data) {
        $this->foto->id_foto       = $data['id_foto']       ?? null;
        $this->foto->fecha         = $data['fecha']         ?? null;
        $this->foto->url           = FotoStorage::extractSafeFileName((string)($data['url'] ?? ''));
        $this->foto->id_incidencia = $data['id_incidencia'] ?? null;

        $errors = [];
        if (empty($this->foto->id_foto)) $errors[] = "El id_foto es obligatorio para actualizar.";
        if (empty($this->foto->url)) $errors[] = "El nombre de archivo no es válido.";
        if ($errors) {
            return ["success" => false, "data" => null, "message" => "No se pudo actualizar la foto.", "errors" => $errors];
        }

        if ($this->foto->update()) {
            return ["success" => true, "data" => null, "message" => "Foto actualizada con éxito.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al actualizar la foto.", "errors" => []];
    }

    public function delete($id) {
        $this->foto->id_foto = $id;
        if ($this->foto->delete()) {
            return ["success" => true, "data" => null, "message" => "Foto eliminada correctamente.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al eliminar la foto.", "errors" => []];
    }
}
