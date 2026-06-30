<?php
require_once __DIR__ . '/Usuario.php';

class Operario extends Usuario {
   
    protected string $cuadrilla;
    protected string $turno;

    public function __construct(int $id, string $nombre, string $apellido, string $email, string $password, string $cuadrilla, string $turno) {
        // Al padre le pasamos ESTRICTAMENTE lo que el padre pide
        parent::__construct($id, $nombre, $apellido, $email, $password);
        
       
        $this->cuadrilla = $cuadrilla;
        $this->turno = $turno;
    }

    public function getCuadrilla(){
        return $this->cuadrilla;
    }

    public function setCuadrilla(string $cuadrilla){
        $this->cuadrilla = $cuadrilla;
    }

    public function getTurno(){
        return $this->turno;
    }

    public function setTurno(string $turno){
        $this->turno = $turno;
    }
}
?>