<?php
require_once __DIR__ . '/Usuarios.php';

class Operario extends Usuario {
    protected string $sector;
    protected string $turno;

    public function __construct(int $id, string $nombre, string $apellido, string $password, string $sector, string $turno) {
        parent::__construct($id, $nombre, $apellido, $password);
        $this->sector = $sector;
        $this->turno = $turno;
    }

    public function getSector() {
        return $this->sector;
    }

    public function setSector(string $sector) {
        $this->sector = $sector;
    }

    public function getTurno() {
        return $this->turno;
    }

    public function setTurno(string $turno) {
        $this->turno = $turno;
    }
}
?>