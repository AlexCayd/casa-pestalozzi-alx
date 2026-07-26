<?php

/**
 * Router exclusivo del servidor integrado de PHP.
 * Entrega assets existentes y delega únicamente rutas MVC a public/index.php.
 */

declare(strict_types=1);

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$publicPath = realpath(__DIR__ . '/../public');
$candidate = $publicPath
    ? realpath($publicPath . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR))
    : false;

if (
    $publicPath !== false
    && $candidate !== false
    && str_starts_with($candidate, $publicPath . DIRECTORY_SEPARATOR)
    && is_file($candidate)
) {
    return false;
}

$_SERVER['PATH_INFO'] = $path;
require __DIR__ . '/../public/index.php';
