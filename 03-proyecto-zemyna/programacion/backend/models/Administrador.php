<?php
require_once 'models/Usuario.php'; 

class Administrador extends Usuario {
    public function __construct(int $id, string $nombre, string $apellido, string $email, string $password) {
        // Solo invocamos al padre para que configure todo lo heredado
        parent::__construct($id, $nombre, $apellido, $email, $password);
    }
}
?>