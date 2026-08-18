<?php
/**
 * Minimal PSR-4 autoloader for the bundled iyzico SDK.
 * We don't rely on Composer here on purpose — many shared-hosting WP
 * installs don't have composer available, and this SDK only needs
 * one namespace mapped, so a full Composer autoload.php is overkill.
 */

if (!defined('ABSPATH')) {
    exit; // no direct access
}

spl_autoload_register(function ($class) {
    $prefix = 'Iyzipay\\';
    $base_dir = __DIR__ . '/src/Iyzipay/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return; // not our namespace, let other autoloaders handle it
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
