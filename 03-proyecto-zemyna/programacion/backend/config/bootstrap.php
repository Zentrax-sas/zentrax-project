<?php
date_default_timezone_set('America/Montevideo');

require_once __DIR__ . '/http.php';
zemynaApplyCors();
zemynaStartSession();

spl_autoload_register(function ($class): void {
    $directories = [
        __DIR__ . '/../controllers/',
        __DIR__ . '/../models/',
        __DIR__ . '/../helpers/',
        __DIR__ . '/',
    ];

    foreach ($directories as $directory) {
        $file = $directory . $class . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../helpers/auth.php';
