<?php
    require_once 'models/Usuarios.php';

    class Adminitrador extends Usuario {

        public function __construct($id, $nombre, $apellido, $password) {
            parent::__construct($id, $nombre, $apellido, $password);
        }

        
        public function getNombre() {
            return $this->nombre;
        }

        public function getApellido() {
            return $this->apellido;
        }

        public function setNombre($nombre) {
            $this->nombre = $nombre;
        }

        public function setApellido($apellido) {
            $this->apellido = $apellido;
        }

        public function getPassword() {
            return $this->password;
        }

        public function setPassword($password) {
            $this->password = $password;
        }

    }


?>