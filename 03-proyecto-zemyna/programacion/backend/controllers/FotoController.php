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

    public function delete($id) {
        $this->foto->id_foto = $id;
        if ($this->foto->delete()) {
            return ["success" => true, "data" => null, "message" => "Foto eliminada correctamente.", "errors" => []];
        }
        return ["success" => false, "data" => null, "message" => "Error al eliminar la foto.", "errors" => []];
    }
}
