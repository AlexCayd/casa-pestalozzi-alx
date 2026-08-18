<?php

/**
 * Semilla controlada de usuarios de piso para desarrollo/QA.
 *
 * Requiere NIP_LOOKUP_SECRET en el entorno. Los NIP sólo se muestran en la
 * salida de esta ejecución; no se escriben en archivos ni en la base de datos.
 */
if (PHP_SAPI !== 'cli') {
    exit("Este script sólo puede ejecutarse desde CLI.\n");
}

require __DIR__ . '/../includes/app.php';

use Services\NipService;

if (!NipService::secretoConfigurado()) {
    fwrite(STDERR, "Falta NIP_LOOKUP_SECRET.\n");
    exit(1);
}

$usuarios = [
    ['mesero1', 'Carlos Hernández', '2345', 'waiter', 1],
    ['mesero2', 'Valeria Ríos', '1702', 'waiter', 1],
    ['cocinero1', 'Mariana López', '3456', 'cook', 1],
    ['mesero3', 'Emilio Cárdenas', '3007', 'waiter', 1],
    ['mesero_inactivo', 'Daniel Torres', '7788', 'waiter', 0],
];

$db->begin_transaction();
try {
    foreach ($usuarios as [$username, $nombre, $nip, $rol, $activo]) {
        $credential = NipService::credencial($nip);
        $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $nipHash = $credential['hash'];
        $nipLookup = $credential['lookup'];
        $stmt = $db->prepare(
            'INSERT INTO usuarios (username, nombre, password_hash, nip_hash, nip_lookup, rol, activo)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               nombre = VALUES(nombre), password_hash = VALUES(password_hash),
               nip_hash = VALUES(nip_hash), nip_lookup = VALUES(nip_lookup),
               rol = VALUES(rol), activo = VALUES(activo)'
        );
        if (!$stmt) {
            throw new RuntimeException($db->error);
        }
        $stmt->bind_param(
            'ssssssi',
            $username,
            $nombre,
            $passwordHash,
            $nipHash,
            $nipLookup,
            $rol,
            $activo
        );
        if (!$stmt->execute()) {
            throw new RuntimeException($stmt->error, $stmt->errno);
        }
        $stmt->close();
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, "No fue posible preparar las credenciales de prueba: {$e->getMessage()}\n");
    exit(1);
}

echo "Usuarios de piso de desarrollo preparados. Entrega temporal:\n";
foreach ($usuarios as [$username, , $nip]) {
    echo "- {$username}: {$nip}\n";
}
