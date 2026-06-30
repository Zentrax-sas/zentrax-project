<?php
require_once 'models/Operario.php';

class Cuadrilla {
    protected string $nombre;
    protected array $integrantes = [];

    public function __construct(protected int $id, string $nombre) {
        $this->nombre = $nombre;
    }

    public function getId(): int {
        return $this->id;
    }

    public function getNombre(): string {
        return $this->nombre;
    }

    public function setNombre(string $nombre): void {
        $this->nombre = $nombre;
    }

    public function getIntegrantes(): array {
        return $this->integrantes;
    }

    public function agregarOperario(Operario $operario): void {
        $this->integrantes[] = $operario;
    }
    public function removerOperario(Operario $operario): void {
        foreach ($this->integrantes as $indice => $integrante) {
            if ($integrante->getId() === $operario->getId()) {
                unset($this->integrantes[$indice]);
                break; 
            }
        }
    }
}
?>