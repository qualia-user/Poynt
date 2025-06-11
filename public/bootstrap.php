<?php

require_once __DIR__ . '/../vendor/autoload.php';
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/../' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

require __DIR__ . '/../config/ConfigApp.php';
require __DIR__ . '/../config/ConfigDatabase.php';
require __DIR__ . '/../config/ConfigClover.php';
require __DIR__ . '/../config/config.php';