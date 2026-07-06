<?php

class Contenedor {
    private int $id_contenedor;
    private string $tipo;
    private int $capacidad;
    private string $ubicacion;
    private float $latitud;
    private float $longitud;    
    private string $estado;
    private string $qr;

    public function __construct(int $id_contenedor, string $tipo, int $capacidad, string $ubicacion, float $latitud, float $longitud, string $estado, string $qr) {
        $this->id_contenedor = $id_contenedor;
        $this->tipo = $tipo;
        $this->capacidad = $capacidad;
        $this->ubicacion = $ubicacion;
        $this->latitud = $latitud;
        $this->longitud = $longitud;
        $this->estado = $estado;
        $this->qr = $qr;
    }

    // ==========================================
    //                  GETTERS
    // ==========================================

    public function getIdContenedor(): int {
        return $this->id_contenedor;
    }

    public function getTipo(): string {
        return $this->tipo;
    }

    public function getCapacidad(): int {
        return $this->capacidad;
    }

    public function getUbicacion(): string {
        return $this->ubicacion;
    }

    public function getLatitud(): float {
        return $this->latitud;
    }

    public function getLongitud(): float {
        return $this->longitud;
    }

    public function getEstado(): string {
        return $this->estado;
    }

    public function getQr(): string {
        return $this->qr;
    }


    public function setTipo(string $tipo): void {
        $this->tipo = $tipo;
    }

    public function setCapacidad(int $capacidad): void {
        $this->capacidad = $capacidad;
    }

    public function setUbicacion(string $ubicacion): void {
        $this->ubicacion = $ubicacion;
    }

    public function setLatitud(float $latitud): void {
        $this->latitud = $latitud;
    }

    public function setLongitud(float $longitud): void {
        $this->longitud = $longitud;
    }

    public function setEstado(string $estado): void {
        $this->estado = $estado;
    }
}