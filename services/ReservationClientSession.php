<?php

/**
 * Sesión temporal e independiente para clientes de reservaciones.
 *
 * Comparte la cookie PHP con el sitio para poder coexistir con una sesión de
 * personal, pero usa exclusivamente el namespace reservation_client.
 */

namespace Services;

class ReservationClientSession
{
    private const SESSION_KEY = 'reservation_client';
    private const CSRF_KEY = 'reservation_client_csrf';
    private const TARGET_RESERVATION_KEY = 'reservation_client_target_reservation_id';

    /**
     * Inicia la sesión con una cookie endurecida cuando todavía es posible
     * configurarla. Secure se activa automáticamente bajo HTTPS.
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            $environment = ReservacionConfig::appEnvironment();
            $configuredPath = trim((string)ini_get('session.save_path'));
            $usingDedicatedPath = false;
            if (in_array($environment, ['development', 'testing'], true)) {
                $sessionPath = trim((string)(getenv('SESSION_SAVE_PATH') ?: ($_ENV['SESSION_SAVE_PATH'] ?? '')));
                if ($sessionPath === '') {
                    $sessionPath = str_contains($configuredPath, ';')
                        ? substr($configuredPath, strrpos($configuredPath, ';') + 1)
                        : $configuredPath;
                }
                if ($sessionPath !== '' && is_dir($sessionPath) && is_writable($sessionPath)) {
                    ini_set('session.save_path', $sessionPath);
                    $usingDedicatedPath = true;
                } elseif (is_dir(sys_get_temp_dir()) && is_writable(sys_get_temp_dir())) {
                    ini_set('session.save_path', sys_get_temp_dir());
                    $usingDedicatedPath = true;
                }
            }
            if (!$usingDedicatedPath) {
                // XAMPP puede apuntar a C:\xampp\tmp, una carpeta que existe
                // pero no siempre es escribible por el usuario que ejecuta
                // Apache/PHP. Sin persistencia aquí, el CSRF y el OTP quedan
                // aislados entre peticiones aunque el navegador conserve la
                // cookie.
                $pathToCheck = str_contains($configuredPath, ';')
                    ? substr($configuredPath, strrpos($configuredPath, ';') + 1)
                    : $configuredPath;
                $localPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';
                $localRequest = in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)
                    || in_array(strtolower((string)($_SERVER['SERVER_NAME'] ?? '')), ['localhost', '127.0.0.1'], true);
                if ($localRequest || $configuredPath === '' || !is_dir($pathToCheck) || !is_writable($pathToCheck)) {
                    if (!is_dir($localPath)) {
                        @mkdir($localPath, 0770, true);
                    }
                    if (!is_dir($localPath) || !is_writable($localPath)) {
                        $localPath = sys_get_temp_dir();
                    }
                }
                if (is_dir($localPath) && is_writable($localPath)) {
                    ini_set('session.save_path', $localPath);
                }
            }
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function csrfToken(): string
    {
        self::start();
        $token = $_SESSION[self::CSRF_KEY] ?? null;
        if (!is_string($token) || strlen($token) < 64) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::CSRF_KEY] = $token;
        }

        return $token;
    }

    public static function validarCsrf(string $token): bool
    {
        self::start();
        $guardado = $_SESSION[self::CSRF_KEY] ?? '';

        return is_string($guardado)
            && $guardado !== ''
            && $token !== ''
            && hash_equals($guardado, $token);
    }

    /**
     * Regenera el ID para impedir fijación y conserva cualquier sesión
     * administrativa ya existente sin copiar privilegios al cliente.
     */
    public static function crear(string $tipo, string $contacto): void
    {
        self::start();
        session_regenerate_id(true);
        $ahora = time();

        $_SESSION[self::SESSION_KEY] = [
            'contacto_tipo' => $tipo,
            'contacto' => $contacto,
            'verified_at' => $ahora,
            'expires_at' => $ahora + (ReservacionConfig::CLIENT_SESSION_IDLE_MINUTES * 60),
        ];
    }

    /** Guarda un destino de una sola lectura para el puente de cambio de horario. */
    public static function setTargetReservationId(int $reservationId): void
    {
        self::start();
        if ($reservationId > 0) {
            $_SESSION[self::TARGET_RESERVATION_KEY] = $reservationId;
        }
    }

    public static function consumeTargetReservationId(): ?int
    {
        self::start();
        $id = filter_var($_SESSION[self::TARGET_RESERVATION_KEY] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        unset($_SESSION[self::TARGET_RESERVATION_KEY]);

        return $id ? (int)$id : null;
    }

    /**
     * Devuelve la identidad verificada sin extender su vencimiento.
     * Una nueva verificación es la única forma de crear otra sesión.
     *
     * @return array{contacto_tipo: string, contacto: string, verified_at: int, expires_at: int}|null
     */
    public static function obtener(bool $renovar = false): ?array
    {
        self::start();
        $sesion = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_array($sesion) || (int)($sesion['expires_at'] ?? 0) <= time()) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }

        if (
            !in_array((string)($sesion['contacto_tipo'] ?? ''), ContactoService::TIPOS, true)
            || trim((string)($sesion['contacto'] ?? '')) === ''
        ) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }

        return $sesion;
    }

    /**
     * Cierra solo el contexto público; no borra login, rol ni datos del staff.
     */
    public static function cerrar(): void
    {
        self::start();
        unset($_SESSION[self::SESSION_KEY], $_SESSION[self::CSRF_KEY], $_SESSION[self::TARGET_RESERVATION_KEY]);
    }
}
