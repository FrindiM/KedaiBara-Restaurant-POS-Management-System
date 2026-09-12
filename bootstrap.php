<?php
$app = require __DIR__ . '/config/app.php';
date_default_timezone_set($app['timezone']);

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) require $file;
});

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($app['session_name']);
    session_start();
}
