<?php

final class ConsentimientoWhatsApp
{
    // JSON booleano y multipart 0/1. Nunca convertir cadenas arbitrarias a true.
    public static function leer(mixed $valor): ?bool
    {
        if (in_array($valor, [true, 1, '1'], true)) return true;
        if (in_array($valor, [false, 0, '0'], true)) return false;
        return null;
    }
}
