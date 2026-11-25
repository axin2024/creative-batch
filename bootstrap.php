<?php

declare(strict_types=1);

spl_autoload_register(static function (string $name): void {
    $prefix = 'CreativeBatch\\';
    if (!str_starts_with($name, $prefix)) {
        return;
    }
    $class = substr($name, strlen($prefix));
    if (preg_match('/\A[A-Za-z]+\z/D', $class) === 1) {
        require __DIR__ . '/src/' . $class . '.php';
    }
});
