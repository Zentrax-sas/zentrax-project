<?php
class Usuario {

public function __construct( protected int $id,protected string $rol,protected string $nombre,protected String $apellido,protected String $password){
    $this->id=$id;
    $this->rol=$rol;
    $this->nombre=$nombre;
    $this->apellido=$apellido;
    $this->password=$password;
}

//el id no lleva setter porque lo maneja la DB//
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

public function getPassword(){
    return $this->password;
}

public function setPassword(string $password){
    $this->password = $password;
}

}
?>