<?php 

use Dotenv\Dotenv;
use Model\ActiveRecord;
require __DIR__ . '/../vendor/autoload.php';

// Añadir Dotenv
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Hora local del restaurante: GMT-6 fijo (America/Mexico_City ya no aplica
// horario de verano desde 2022). Sin esto, date() usa el default UTC del
// php.ini y los tickets salen con 6 horas de adelanto.
date_default_timezone_set('America/Mexico_City');

require 'funciones.php';
require 'database.php';

// Conectarnos a la base de datos
ActiveRecord::setDB($db);