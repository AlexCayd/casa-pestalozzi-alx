<?php

namespace Services\Contact;

use RuntimeException;
use Throwable;

/** Cifra contactos para persistencia y genera identificadores deterministas de búsqueda. */
class ContactoProtegidoService
{
    private const VERSION = 'cp1';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    /** Cifra el contacto normalizado con AES-256-GCM. El vacío se conserva. */
    public static function cifrar(string $tipo, string $contacto): string
    {
        if ($contacto === '') return '';

        try {
            $normalizado = ContactoService::normalizar($tipo, $contacto);
            $key = self::clave('CONTACT_ENCRYPTION_KEY');
            self::validarClavesDistintas($key, self::clave('CONTACT_LOOKUP_KEY'));
            $nonce = random_bytes(self::NONCE_BYTES);
            $tag = '';
            $ciphertext = openssl_encrypt(
                $normalizado,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag,
                self::aad($tipo),
                self::TAG_BYTES
            );
            if (!is_string($ciphertext) || strlen($tag) !== self::TAG_BYTES) {
                throw new RuntimeException();
            }
            return self::VERSION . ':' . base64_encode($nonce . $tag . $ciphertext);
        } catch (Throwable $e) {
            throw new RuntimeException('No fue posible proteger el contacto.');
        }
    }

    /** Descifra únicamente envolturas cp1 válidas y autentica tipo y versión. */
    public static function descifrar(string $tipo, string $cifrado): string
    {
        try {
            if (!in_array($tipo, ContactoService::TIPOS, true) || !str_starts_with($cifrado, self::VERSION . ':')) {
                throw new RuntimeException();
            }
            $raw = base64_decode(substr($cifrado, strlen(self::VERSION) + 1), true);
            if (!is_string($raw) || strlen($raw) <= self::NONCE_BYTES + self::TAG_BYTES) {
                throw new RuntimeException();
            }
            $key = self::clave('CONTACT_ENCRYPTION_KEY');
            self::validarClavesDistintas($key, self::clave('CONTACT_LOOKUP_KEY'));
            $nonce = substr($raw, 0, self::NONCE_BYTES);
            $tag = substr($raw, self::NONCE_BYTES, self::TAG_BYTES);
            $ciphertext = substr($raw, self::NONCE_BYTES + self::TAG_BYTES);
            $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, self::aad($tipo));
            if (!is_string($plain) || $plain === '' || ContactoService::normalizar($tipo, $plain) !== $plain) {
                throw new RuntimeException();
            }
            return $plain;
        } catch (Throwable $e) {
            throw new RuntimeException('No fue posible leer el contacto protegido.');
        }
    }

    /** HMAC-SHA256 con dominio separado por tipo y contacto normalizado. */
    public static function identificador(string $tipo, string $contacto): string
    {
        if ($contacto === '') return '';
        try {
            $normalizado = ContactoService::normalizar($tipo, $contacto);
            $encryptionKey = self::clave('CONTACT_ENCRYPTION_KEY');
            $lookupKey = self::clave('CONTACT_LOOKUP_KEY');
            self::validarClavesDistintas($encryptionKey, $lookupKey);
            return hash_hmac('sha256', "casa-pestalozzi/contact/{$tipo}/{$normalizado}", $lookupKey);
        } catch (Throwable $e) {
            throw new RuntimeException('No fue posible identificar el contacto protegido.');
        }
    }

    /** Devuelve la fila con el campo contacto en claro para su consumo en memoria. */
    public static function descifrarFila(array $fila): array
    {
        unset($fila['contacto_hash']);
        if (!array_key_exists('contacto', $fila) || $fila['contacto'] === null || $fila['contacto'] === '') return $fila;
        if (!is_string($fila['contacto']) || !isset($fila['contacto_tipo']) || !is_string($fila['contacto_tipo'])) {
            throw new RuntimeException('No fue posible leer el contacto protegido.');
        }
        $fila['contacto'] = self::descifrar($fila['contacto_tipo'], $fila['contacto']);
        return $fila;
    }

    private static function clave(string $nombre): string
    {
        $encoded = array_key_exists($nombre, $_ENV) ? $_ENV[$nombre] : getenv($nombre);
        if (!is_string($encoded) || $encoded === '') throw new RuntimeException();
        $decoded = base64_decode($encoded, true);
        if (!is_string($decoded) || strlen($decoded) !== 32 || base64_encode($decoded) !== $encoded) {
            throw new RuntimeException();
        }
        return $decoded;
    }

    private static function validarClavesDistintas(string $encryption, string $lookup): void
    {
        if (hash_equals($encryption, $lookup)) throw new RuntimeException();
    }

    private static function aad(string $tipo): string
    {
        if (!in_array($tipo, ContactoService::TIPOS, true)) throw new RuntimeException();
        return self::VERSION . "\0" . $tipo;
    }
}
