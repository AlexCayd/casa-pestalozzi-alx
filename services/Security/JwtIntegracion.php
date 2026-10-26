<?php

namespace Services\Security;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Firma y verificación de los JWT del tráfico con n8n (API.md §9).
 *
 * Sólo para máquina a máquina: a un navegador no se le entrega nunca un token
 * de éstos, porque quien abra las herramientas de desarrollo lo tendría igual
 * que la sesión que ya lo protege.
 *
 * Una llave por sentido, para que quien vea una no pueda escribir en el otro:
 *   - JWT_N8N_ENTRADA_SECRET: n8n firma y PHP verifica (/api/integraciones/*).
 *   - JWT_N8N_SALIDA_SECRET:  PHP firma y n8n verifica (webhooks de n8n).
 *
 * HS256 fijo: la Key se construye siempre con 'HS256', así que la librería
 * rechaza `alg: none` o cualquier otro valor del header antes de mirar la
 * firma. Sin modo heredado: o llega un JWT válido o no se entra. Una llave
 * ausente lanza RuntimeException, como NipService sin NIP_LOOKUP_SECRET: un
 * despliegue mal configurado tiene que fallar a la vista, no quedar abierto.
 */
final class JwtIntegracion
{
    public const ALGORITMO = 'HS256';
    public const EMISOR_N8N = 'n8n';
    public const EMISOR_PROPIO = 'casa-pestalozzi';

    /** Vida máxima aceptada (exp − iat): un token bien firmado pero más largo se rechaza. */
    public const VIDA_MAXIMA = 300;
    /** Desfase de reloj tolerado entre n8n y PHP. */
    public const DESFASE = 30;
    /** 256 bits: lo que HS256 necesita para no ser la parte débil. */
    public const LLAVE_MINIMA = 32;

    private const ENTRADA = 'JWT_N8N_ENTRADA_SECRET';
    private const SALIDA = 'JWT_N8N_SALIDA_SECRET';

    /**
     * Verifica un token entrante para una audiencia.
     *
     * El `motivo` es para el error_log, nunca para la respuesta: decirle a
     * quien llama si falló la firma o la audiencia le ahorra trabajo.
     *
     * @return array{ok: bool, motivo: string, claims: array<string, mixed>}
     */
    public static function verificar(string $token, string $aud, ?string $secreto = null): array
    {
        $secreto ??= self::llave(self::ENTRADA);

        $leewayAnterior = JWT::$leeway;
        JWT::$leeway = self::DESFASE;
        try {
            $claims = (array) JWT::decode($token, new Key($secreto, self::ALGORITMO));
        } catch (\Throwable $e) {
            return self::rechazo(get_class($e) . ': ' . $e->getMessage());
        } finally {
            JWT::$leeway = $leewayAnterior;
        }

        foreach (['iss', 'aud', 'iat', 'exp', 'jti'] as $claim) {
            if (!isset($claims[$claim]) || $claims[$claim] === '') {
                return self::rechazo("falta el claim {$claim}", $claims);
            }
        }
        if ($claims['iss'] !== self::EMISOR_N8N) {
            return self::rechazo('emisor inesperado', $claims);
        }
        // `aud` puede ser una lista según el RFC; aquí se exige exactamente
        // una audiencia, para que un token no valga en dos endpoints a la vez.
        if (!is_string($claims['aud']) || !hash_equals($aud, $claims['aud'])) {
            return self::rechazo('audiencia equivocada', $claims);
        }
        if ((float) $claims['exp'] - (float) $claims['iat'] > self::VIDA_MAXIMA) {
            return self::rechazo('vida mayor de ' . self::VIDA_MAXIMA . ' s', $claims);
        }

        return ['ok' => true, 'motivo' => '', 'claims' => $claims];
    }

    /** Firma un token saliente (PHP → n8n) para una audiencia. */
    public static function firmar(string $aud, int $ttl = 120, ?string $secreto = null): string
    {
        $secreto ??= self::llave(self::SALIDA);
        $ttl = max(1, min($ttl, self::VIDA_MAXIMA));
        $ahora = time();

        return JWT::encode([
            'iss' => self::EMISOR_PROPIO,
            'aud' => $aud,
            'iat' => $ahora,
            'exp' => $ahora + $ttl,
            'jti' => bin2hex(random_bytes(16)),
        ], $secreto, self::ALGORITMO);
    }

    /**
     * Headers que hay que añadir a una llamada a n8n.
     *
     * @return array<string, string>
     */
    public static function cabecerasSalida(string $aud): array
    {
        return ['Authorization' => 'Bearer ' . self::firmar($aud)];
    }

    /**
     * El token del header `Authorization: Bearer …`, o null si no hay uno
     * bien formado. Apache renombra el header a REDIRECT_HTTP_AUTHORIZATION
     * al reescribir la URL hacia index.php.
     */
    public static function tokenDeLaPeticion(?array $server = null): ?string
    {
        $server ??= $_SERVER;
        $cabecera = $server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if (!is_string($cabecera) || !preg_match('/^Bearer ([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/', trim($cabecera), $m)) {
            return null;
        }

        return $m[1];
    }

    private static function llave(string $variable): string
    {
        $llave = $_ENV[$variable] ?? '';
        if (!is_string($llave) || trim($llave) === '') {
            $llave = getenv($variable);
        }
        $llave = is_string($llave) ? trim($llave) : '';

        if ($llave === '') {
            throw new \RuntimeException("{$variable} no está configurado.");
        }
        if (strlen($llave) < self::LLAVE_MINIMA) {
            throw new \RuntimeException("{$variable} debe tener al menos " . self::LLAVE_MINIMA . ' bytes.');
        }

        return $llave;
    }

    /** @return array{ok: false, motivo: string, claims: array<string, mixed>} */
    private static function rechazo(string $motivo, array $claims = []): array
    {
        return ['ok' => false, 'motivo' => $motivo, 'claims' => $claims];
    }
}
