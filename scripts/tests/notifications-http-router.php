<?php
require __DIR__ . '/notifications-test-bootstrap.php';
$_ENV['APP_ENV'] = 'development';
$_ENV['RESERVATION_PUBLIC_BASE_URL'] = 'http://127.0.0.1:8087';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$public = realpath(dirname(__DIR__, 2) . '/public');
$file = realpath($public . $path);
if ($file && str_starts_with($file, $public . DIRECTORY_SEPARATOR) && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== 'php') return false;
require $public . '/index.php';
