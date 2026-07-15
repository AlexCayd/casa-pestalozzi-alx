<?php
$db = mysqli_connect(
    $_ENV['DB_HOST'] ?? '',
    $_ENV['DB_USER'] ?? '', 
    $_ENV['DB_PASS'] ?? '', 
    $_ENV['DB_NAME'] ?? ''
);

if (!$db) {
    echo "Error: No se pudo conectar a MySQL.";
    echo "errno de depuración: " . mysqli_connect_errno();
    echo "error de depuración: " . mysqli_connect_error();
    exit;
}

// El MySQL corre en un contenedor Docker cuyo reloj es UTC. Alinear la sesión
// a GMT-6 para que NOW()/CURRENT_TIMESTAMP (hora_apertura de tickets,
// created_at de items) se guarden — y las columnas TIMESTAMP se lean — en la
// hora local del restaurante.
mysqli_query($db, "SET time_zone = '-06:00'");
