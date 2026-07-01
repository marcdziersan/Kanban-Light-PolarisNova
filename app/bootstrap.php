<?php
/**
 * Gemeinsamer Bootstrap für PolarisNova.
 *
 * Lädt Autoloader, Legacy-kompatible Storage-Funktionen und startet die Session.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/../lib/storage.php';
require_once __DIR__ . '/../lib/customer_storage.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
