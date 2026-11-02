<?php
/** La aplicación definitiva solo admite el esquema multinegocio completo. */
try {
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $db=new mysqli((string)($_ENV['DB_HOST']??''),(string)($_ENV['DB_USER']??''),(string)($_ENV['DB_PASS']??''),(string)($_ENV['DB_NAME']??''),(int)($_ENV['DB_PORT']??3306));
    $db->set_charset('utf8mb4'); $db->query("SET time_zone='-06:00'");
    $versiones=(int)$db->query("SELECT COUNT(*) FROM esquema_versiones WHERE version IN ('multinegocio-3a-v1','multinegocio-3b-v1','multinegocio-3c-v1')")->fetch_row()[0];
    if ($versiones!==3) throw new RuntimeException('Esquema incompleto.');
} catch (Throwable $e) {
    error_log('No se pudo verificar la instalación multinegocio de Casa Pestalozzi.');
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('La instalación no está disponible. Verifica la configuración y el procedimiento de instalación.');
}
