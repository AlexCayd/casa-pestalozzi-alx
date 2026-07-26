<?php

/**
 * Router para `php -S` durante verificaciones locales.
 * Deja que el servidor integrado entregue los assets existentes y envía el
 * resto de las rutas al front controller.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicFile = dirname(__DIR__) . '/public' . $path;

if ($path !== '/' && is_file($publicFile)) {
    return false;
}

require dirname(__DIR__) . '/public/index.php';
