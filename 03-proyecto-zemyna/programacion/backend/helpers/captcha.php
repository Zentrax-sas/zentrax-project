<?php

function generarCaptcha(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $a = random_int(1, 10);
    $b = random_int(1, 10);
    $_SESSION['captcha_resultado'] = $a + $b;

    return "¿Cuánto es {$a} + {$b}?";
}

function validarCaptcha($respuestaUsuario): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

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
