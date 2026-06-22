<?php
require_once 'models/Usuarios.php';

class Vecino extends Usuario {
    protected string $direccion;
    protected string $telefono;

    public function __construct(int $id, string $nombre, string $apellido, string $email, string $password, string $direccion, string $telefono) {
        parent::__construct($id, $nombre, $apellido, $email, $password);
        $this->direccion = $direccion;
        $this->telefono = $telefono;
    }

    public function getDireccion() {
        return $this->direccion;
    }

    public function setDireccion(string $direccion) {
        $this->direccion = $direccion;
    }

    public function getTelefono() {
        return $this->telefono;
    }

    public function setTelefono(string $telefono) {
        $this->telefono = $telefono;
    }
}
?>