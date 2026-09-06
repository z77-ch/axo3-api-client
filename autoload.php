<?php

/**
 * Standalone autoloader for platforms without Composer (WordPress, Joomla,
 * plain PHP): `require __DIR__ . '/autoload.php';` and every Axo3\ApiClient
 * class is available. With Composer, use vendor/autoload.php instead —
 * both may be loaded, the second registration is a harmless no-op.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Axo3\\ApiClient\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
