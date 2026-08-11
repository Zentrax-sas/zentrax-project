<?php
require_once __DIR__ . '/../models/Foto.php';

class FotoController {
    private $foto;

    public function __construct($db) {
        $this->foto = new Foto($db);
    }

    public function getAll() {
        $stmt = $this->foto->read();
        if ($stmt) {
            return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC), "message" => "Fotos cargadas correctamente."];
        }
        return [
            "success" => true,
            "data" => [
                ["id_foto" => 1, "fecha" => "2025-06-01 09:05:00", "url" => "/uploads/incidencias/inc1_foto1.jpg", "id_incidencia" => 1],
                ["id_foto" => 2, "fecha" => "2025-06-02 11:35:00", "url" => "/uploads/incidencias/inc2_foto1.jpg", "id_incidencia" => 2],
            ],
            "message" => "Fotos cargadas en modo demo."
        ];
    }

    public function create($data) {
        $this->foto->fecha         = $data['fecha']         ?? date('Y-m-d H:i:s');
        $this->foto->url           = $data['url']           ?? null;
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
        $this->foto->url           = $data['url']           ?? null;
        $this->foto->id_incidencia = $data['id_incidencia'] ?? null;

        $errors = [];
        if (empty($this->foto->id_foto)) $errors[] = "El id_foto es obligatorio para actualizar.";
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
