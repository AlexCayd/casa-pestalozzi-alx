<?php

use Services\Contact\ContactoProtegidoService as Protegido;

require __DIR__ . '/../../vendor/autoload.php';

function protegidoAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function protegidoFalla(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException $e) {
        protegidoAssert(!str_contains($e->getMessage(), 'fixture') && !str_contains($e->getMessage(), 'secret'), 'El error filtró datos sensibles.');
        return;
    }
    throw new RuntimeException($message);
}

$enc = random_bytes(32);
$lookup = random_bytes(32);
$setEnv = static function (?string $encryption, ?string $lookupKey): void {
    foreach (['CONTACT_ENCRYPTION_KEY', 'CONTACT_LOOKUP_KEY'] as $name) {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
    }
    if ($encryption !== null) putenv('CONTACT_ENCRYPTION_KEY=' . $encryption);
    if ($lookupKey !== null) putenv('CONTACT_LOOKUP_KEY=' . $lookupKey);
};
$setEnv(base64_encode($enc), base64_encode($lookup));

$email = 'Fixture.User@example.test';
$emailCipher1 = Protegido::cifrar('email', $email);
$emailCipher2 = Protegido::cifrar('email', $email);
protegidoAssert(Protegido::descifrar('email', $emailCipher1) === 'fixture.user@example.test', 'Falló roundtrip de email normalizado.');
protegidoAssert($emailCipher1 !== $emailCipher2, 'El nonce aleatorio no cambió el cifrado.');

$phone = '+52 (55) 1234-5678';
$phoneCipher = Protegido::cifrar('telefono', $phone);
protegidoAssert(Protegido::descifrar('telefono', $phoneCipher) === '+525512345678', 'Falló roundtrip de teléfono normalizado.');
protegidoAssert(Protegido::identificador('email', $email) === Protegido::identificador('email', ' fixture.user@EXAMPLE.test '), 'El HMAC no respeta normalización de email.');
protegidoAssert(Protegido::identificador('telefono', $phone) === Protegido::identificador('telefono', '+525512345678'), 'El HMAC no respeta normalización de teléfono.');
protegidoAssert(Protegido::identificador('email', $email) !== Protegido::identificador('telefono', '+525512345678'), 'El dominio HMAC no separa tipos.');
$email150 = str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 21);
protegidoAssert(strlen($email150) === 150 && Protegido::descifrar('email', Protegido::cifrar('email', $email150)) === $email150, 'No acepta email normalizado de 150 caracteres.');
$email151 = str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 22);
protegidoFalla(fn() => Protegido::cifrar('email', $email151), 'Aceptó email de 151 caracteres.');

$raw = base64_decode(substr($emailCipher1, 4), true);
foreach ([0, 12, 27, 28] as $offset) {
    $tampered = $raw;
    $tampered[$offset] = chr(ord($tampered[$offset]) ^ 1);
    protegidoFalla(fn() => Protegido::descifrar('email', 'cp1:' . base64_encode($tampered)), 'Aceptó un nonce, tag o ciphertext alterado.');
}
protegidoFalla(fn() => Protegido::descifrar('telefono', $emailCipher1), 'Aceptó otro tipo de contacto.');
protegidoFalla(fn() => Protegido::descifrar('email', $email), 'Aceptó plaintext como cifrado.');
protegidoAssert(Protegido::descifrarFila(['contacto_tipo' => 'email', 'contacto' => $emailCipher1, 'contacto_hash' => 'lookup'])['contacto'] === 'fixture.user@example.test', 'No transformó la fila protegida.');
protegidoAssert(!array_key_exists('contacto_hash', Protegido::descifrarFila(['id' => 1, 'contacto_hash' => 'lookup'])), 'Dejó escapar el hash de una fila sin contacto.');

$setEnv(null, null);
protegidoAssert(Protegido::cifrar('email', '') === '', 'El contacto vacío requiere claves.');
protegidoAssert(Protegido::identificador('email', '') === '', 'El identificador vacío requiere claves.');
protegidoAssert(Protegido::descifrarFila(['contacto_tipo' => 'email', 'contacto' => null])['contacto'] === null, 'La fila sin contacto requiere claves.');
protegidoFalla(fn() => Protegido::cifrar('email', 'fixture@example.test'), 'Aceptó claves ausentes.');

$setEnv('not-base64', base64_encode($lookup));
protegidoFalla(fn() => Protegido::cifrar('email', 'fixture@example.test'), 'Aceptó una clave inválida.');
$setEnv(base64_encode($enc), base64_encode($enc));
protegidoFalla(fn() => Protegido::cifrar('email', 'fixture@example.test'), 'Aceptó claves iguales.');
$setEnv(base64_encode(random_bytes(32)), base64_encode($lookup));
protegidoFalla(fn() => Protegido::descifrar('email', $emailCipher1), 'Descifró con una clave de cifrado distinta.');
$setEnv(base64_encode($enc), base64_encode(random_bytes(31)));
protegidoFalla(fn() => Protegido::cifrar('email', 'fixture@example.test'), 'Aceptó clave de longitud incorrecta.');

$setEnv(base64_encode($enc), base64_encode($lookup));
$_ENV['CONTACT_ENCRYPTION_KEY'] = base64_encode($enc);
$_ENV['CONTACT_LOOKUP_KEY'] = base64_encode($lookup);
putenv('CONTACT_ENCRYPTION_KEY');
putenv('CONTACT_LOOKUP_KEY');
protegidoAssert(Protegido::descifrar('email', $emailCipher1) === 'fixture.user@example.test', 'No leyó claves desde $_ENV.');

echo "OK: contacto protegido sin base de datos.\n";
