<?php

/**
 * Rota los NIP de usuarios existentes después de la migración de esquema.
 * Imprime la entrega una sola vez y no crea ningún listado persistente.
 */
if (PHP_SAPI !== 'cli') {
    exit("Este script sólo puede ejecutarse desde CLI.\n");
}

require __DIR__ . '/../includes/app.php';

use Services\UsuarioService;
use Services\NipService;

if (!NipService::secretoConfigurado()) {
    fwrite(STDERR, "Falta NIP_LOOKUP_SECRET.\n");
    exit(1);
}

$resultado = $db->query("SELECT id, username FROM usuarios WHERE rol IN ('waiter', 'cook') ORDER BY id");
if (!$resultado) {
    fwrite(STDERR, "No fue posible leer los usuarios de piso: {$db->error}\n");
    exit(1);
}

$usuarios = [];
while ($fila = $resultado->fetch_assoc()) {
    $usuarios[] = [(int) $fila['id'], (string) $fila['username']];
}
$resultado->free();

echo "Rotación controlada de credenciales de piso:\n";
foreach ($usuarios as [$id, $username]) {
    $rotacion = UsuarioService::regenerarNip($id);
    if (!($rotacion['ok'] ?? false)) {
        fwrite(STDERR, "No fue posible rotar {$username}. Detalle: {$rotacion['codigo']}\n");
        exit(1);
    }
    echo "- {$username}: {$rotacion['nip']}\n";
}

echo "Entrega terminada. No se guardó el listado.\n";
