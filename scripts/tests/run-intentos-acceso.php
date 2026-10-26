<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Services\Security\IntentosAcceso;

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cp-intentos-test-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) throw new RuntimeException('No se pudo preparar la prueba.');
$total = 0;
$assert = static function (bool $ok) use (&$total): void {
    if (!$ok) throw new RuntimeException('Falló el límite compartido de acceso.');
    $total++;
};
try {
    for ($i = 0; $i < 20; $i++) $assert(IntentosAcceso::admitir('nip', 'ip-sintetica-a', $dir, 1000));
    // No depende de sesión ni de una instancia de clase conservada.
    $_SESSION = [];
    $assert(!IntentosAcceso::admitir('nip', 'ip-sintetica-a', $dir, 1059));
    $assert(IntentosAcceso::admitir('nip', 'ip-sintetica-b', $dir, 1059));
    $assert(IntentosAcceso::admitir('contrasena', 'ip-sintetica-a', $dir, 1059));
    $assert(IntentosAcceso::admitir('nip', 'ip-sintetica-a', $dir, 1060));
    for ($i = 1; $i < 30; $i++) $assert(IntentosAcceso::admitir('contrasena', 'ip-sintetica-a', $dir, 1059));
    $assert(!IntentosAcceso::admitir('contrasena', 'ip-sintetica-a', $dir, 1059));
    $assert(!IntentosAcceso::admitir('otro', 'ip-sintetica-a', $dir, 1059));
    $assert(!IntentosAcceso::admitir('nip', 'ip', $dir . '/inexistente', 1059));
    echo "PASS: intentos compartidos ($total comprobaciones).\n";
} finally {
    // Directorio aleatorio creado aquí: solo archivos propios con nombre validado.
    foreach (scandir($dir) as $entry) {
        if (preg_match('/^cp-acceso-[a-f0-9]{64}\.json$/D', $entry)) unlink($dir . DIRECTORY_SEPARATOR . $entry);
    }
    rmdir($dir);
}
