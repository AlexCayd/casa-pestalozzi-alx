<?php

namespace Controllers;

class HomeController
{
    public static function index($router)
    {
        // La home tiene su propio shell HTML (no usa views/layout.php de auth)
        include_once __DIR__ . '/../views/home/index.php';
    }
}
