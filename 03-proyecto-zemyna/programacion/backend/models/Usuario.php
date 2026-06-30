<?php
class Usuario {
    // Use php 8 que pidio el profe 
    public function __construct(
        protected int $id,
        protected string $nombre,
        protected string $apellido,
        protected string $email,
        protected string $password
    ) {}

    public function getId(){
        return $this->id;
    }

    public function getNombre(){
        return $this->nombre;
    }

    public function setNombre(string $nombre){
        $this->nombre = $nombre;
    }

    public function getApellido(){
        return $this->apellido;
    }

    public function setApellido(string $apellido){
        $this->apellido = $apellido;
    }

    public function getEmail(){
        return $this->email;
    }

    public function setEmail(string $email){
        $this->email = $email;
    }

    public function getPassword(){
        return $this->password;
    }

    public function setPassword(string $password){
        $this->password = $password;
    }
}
?>