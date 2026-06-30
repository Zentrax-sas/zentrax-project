<?php
class Vecino {
    protected int $id;
    protected string $direccion;
    protected string $telefono; // Puse string por si ponen el "+" 

    public function __construct(int $id, string $direccion, string $telefono) {
        $this->id = $id;
        $this->direccion = $direccion;
        $this->telefono = $telefono;
    }

    public function getId(){
        return $this->id;
    }

    public function getDireccion(){
        return $this->direccion;
    }

    public function setDireccion(string $direccion){
        $this->direccion = $direccion;
    }

    public function getTelefono(){
        return $this->telefono;
    }

    public function setTelefono(string $telefono){
        $this->telefono = $telefono;
    }
}
?>