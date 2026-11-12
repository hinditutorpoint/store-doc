<?php

/**
 * Bootstrap for the package test suite.
 *
 * When the package is installed on its own, run `composer install` first so the
 * Composer autoloader in `vendor/` is used. When the package is developed as a
 * path repository inside a Laravel application, that application already
 * provides the autoloader and only the PSR-4 mapping has to be re-pointed at
 * this checkout.
 */

$localAutoloader = __DIR__.'/../vendor/autoload.php';
if (is_file($localAutoloader)) {
    require_once $localAutoloader;
}

if (! class_exists(\GuzzleHttp\Client::class) || ! class_exists(\Illuminate\Support\Str::class)) {
    fwrite(
        STDERR,
        "The package dependencies are missing. Run `composer install` in ".dirname(__DIR__)." first.\n"
    );
    exit(1);
}

// Always resolve the package namespaces against this checkout, even when an
// outer autoloader already maps them to a different (vendored) copy.
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'StoreDoc\\Laravel\\Storage\\Tests\\' => __DIR__.'/',
        'StoreDoc\\Laravel\\Storage\\' => __DIR__.'/../src/',
    ];

    foreach ($prefixes as $prefix => $directory) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = $directory.$relative.'.php';

        if (is_file($file)) {
            require_once $file;

            return;
        }
    }
}, true, true);
