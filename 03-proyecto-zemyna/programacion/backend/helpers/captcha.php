<?php
require_once __DIR__ . '/../config/http.php';

function generarCaptcha(): string {
    zemynaStartSession();

    $a = random_int(1, 10);
    $b = random_int(1, 10);
    $_SESSION['captcha_resultado'] = $a + $b;

    return "¿Cuánto es {$a} + {$b}?";
}

function validarCaptcha($respuestaUsuario): bool {
    zemynaStartSession();

    $expected = $_SESSION['captcha_resultado'] ?? null;
    unset($_SESSION['captcha_resultado']);

    if ($expected === null) {
        return false;
    }

    if (!is_numeric($respuestaUsuario)) {
        return false;
    }

    return intval($respuestaUsuario) === intval($expected);
}
