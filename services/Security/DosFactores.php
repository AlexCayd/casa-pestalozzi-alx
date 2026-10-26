<?php

namespace Services\Security;

use Classes\Auth;
use Model\Usuario;
use Services\Contact\ContactoService;
use Services\Integrations\N8nClient;
use Services\Notifications\NotificationConfig;

/**
 * Segundo paso del acceso de los roles de sistema (super admin y admin): tras
 * la contraseña correcta NO se abre sesión; se abre un reto en $_SESSION y se
 * manda un código de 6 dígitos al correo de la cuenta. Sólo quien lo teclea
 * entra (AuthController::verificacion).
 *
 * El reto vive en la sesión y no en la base: dura minutos, es de un solo
 * navegador y la sesión ya es server-side. Se guarda el sha256 del código,
 * nunca el código, salvo la copia de pruebas de mostrarCodigo().
 */
final class DosFactores
{
    public const VIGENCIA = 600;
    public const INTENTOS = 5;
    public const ESPERA_REENVIO = 30;
    public const ENVIOS = 5;
    public const WEBHOOK_PATH = '/webhook/admin/codigo-acceso';

    private const SESION = '_dos_factores';

    /** ¿Este rol pasa por el segundo paso? */
    public static function aplica(string $rol): bool
    {
        return Permisos::esSistema($rol);
    }

    /**
     * TEMPORAL — retirar en el deploy junto con el recuadro de
     * views/auth/dos-factores.php: mientras no haya correos de prueba, el
     * código se pinta en la pantalla. Se oculta sólo con APP_ENV=production
     * ESCRITO: NotificationConfig toma un APP_ENV vacío por production, y los
     * .env de hoy no lo declaran, así que con esa regla nadie podría entrar.
     * Red por si se olvida retirarlo: el deploy declara APP_ENV=production.
     */
    public static function mostrarCodigo(): bool
    {
        $entorno = $_ENV['APP_ENV'] ?? getenv('APP_ENV');

        return strtolower(trim((string) $entorno)) !== NotificationConfig::PRODUCTION;
    }

    /** Abre el reto tras la contraseña correcta y manda el primer código. */
    public static function iniciar(Usuario $usuario): array
    {
        Auth::start();
        // Id nuevo también aquí: el reto es medio acceso y no debe heredar
        // un id de sesión que alguien haya fijado antes de la contraseña.
        session_regenerate_id(true);
        $_SESSION[self::SESION] = [
            'id' => (int) $usuario->id,
            'nombre' => (string) $usuario->nombre,
            'email' => trim((string) ($usuario->email ?? '')),
            'envios' => 0,
        ];

        return self::emitir();
    }

    /** El reto en curso, o null si no hay medio acceso abierto. */
    public static function reto(): ?array
    {
        Auth::start();
        $reto = $_SESSION[self::SESION] ?? null;

        return is_array($reto) && (int) ($reto['id'] ?? 0) > 0 ? $reto : null;
    }

    public static function cancelar(): void
    {
        Auth::start();
        unset($_SESSION[self::SESION]);
    }

    /**
     * Código nuevo (invalida el anterior) y envío.
     *
     * @return array{ok: bool, codigo: string}
     */
    public static function emitir(?N8nClient $client = null): array
    {
        $reto = self::reto();
        if ($reto === null) {
            return ['ok' => false, 'codigo' => 'SIN_RETO'];
        }

        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ahora = time();
        $reto['hash'] = hash('sha256', $codigo);
        $reto['expira'] = $ahora + self::VIGENCIA;
        $reto['enviado'] = $ahora;
        $reto['intentos'] = 0;
        $reto['envios'] = (int) ($reto['envios'] ?? 0) + 1;
        $reto['visible'] = self::mostrarCodigo() ? $codigo : null;

        $envio = self::enviar($reto, $codigo, $client);
        // La pantalla lo lee para decir si el correo salió.
        $reto['entrega'] = $envio['codigo'];
        $_SESSION[self::SESION] = $reto;

        return $envio;
    }

    /**
     * Manda el código al webhook de n8n, que compone y envía el correo
     * (n8n/README.md). En development no hay transporte, como en el resto de
     * notificaciones.
     *
     * @return array{ok: bool, codigo: string}
     */
    public static function enviar(array $reto, string $codigo, ?N8nClient $client = null): array
    {
        if (($reto['email'] ?? '') === '') {
            return ['ok' => false, 'codigo' => 'SIN_CORREO'];
        }
        if ($client === null && !NotificationConfig::usesExternalTransport()) {
            return ['ok' => false, 'codigo' => 'NOTIFICACION_PREPARADA_DESARROLLO'];
        }

        try {
            $client ??= new N8nClient(NotificationConfig::n8nBaseUrl(), NotificationConfig::n8nSecret());
            $entrega = $client->post(self::WEBHOOK_PATH, [
                'tipo' => 'admin_codigo_acceso',
                'email' => $reto['email'],
                'nombre' => (string) ($reto['nombre'] ?? ''),
                'codigo' => $codigo,
                'vigencia_minutos' => intdiv(self::VIGENCIA, 60),
            ]);
        } catch (\Throwable) {
            $entrega = ['accepted' => false, 'codigo' => 'NOTIFICACION_CONFIGURACION_INVALIDA'];
        }

        if (($entrega['accepted'] ?? false) !== true) {
            // Sin el código ni el correo en el log.
            error_log('DosFactores::enviar - usuario ' . (int) $reto['id'] . ': ' . (string) ($entrega['codigo'] ?? '?'));
            return ['ok' => false, 'codigo' => (string) ($entrega['codigo'] ?? 'NOTIFICACION_NO_ACEPTADA')];
        }

        return ['ok' => true, 'codigo' => 'NOTIFICACION_ACEPTADA'];
    }

    /**
     * La decisión sobre un código tecleado, sin efectos: 'ok', 'formato',
     * 'vencido', 'agotado' (sin intentos) o 'incorrecto'.
     */
    public static function comprobar(array $reto, string $codigo, int $ahora): string
    {
        if (!preg_match('/^\d{6}$/', $codigo)) {
            return 'formato';
        }
        if ((int) ($reto['intentos'] ?? 0) >= self::INTENTOS) {
            return 'agotado';
        }
        if ($ahora > (int) ($reto['expira'] ?? 0)) {
            return 'vencido';
        }

        return hash_equals((string) ($reto['hash'] ?? ''), hash('sha256', $codigo)) ? 'ok' : 'incorrecto';
    }

    /**
     * Aplica comprobar() al reto de la sesión: cuenta el fallo y, con 'ok',
     * cierra el reto y devuelve el id de la cuenta.
     *
     * @return array{resultado: string, id?: int, restantes?: int}
     */
    public static function verificar(string $codigo): array
    {
        $reto = self::reto();
        if ($reto === null) {
            return ['resultado' => 'sin_reto'];
        }

        $resultado = self::comprobar($reto, trim($codigo), time());
        if ($resultado === 'ok') {
            self::cancelar();
            return ['resultado' => 'ok', 'id' => (int) $reto['id']];
        }

        if ($resultado === 'incorrecto') {
            $reto['intentos'] = (int) ($reto['intentos'] ?? 0) + 1;
            $_SESSION[self::SESION] = $reto;
            if ($reto['intentos'] >= self::INTENTOS) {
                $resultado = 'agotado';
            }
        }
        if ($resultado === 'agotado') {
            // Sin intentos el reto no se puede rescatar con un reenvío: vuelta
            // a la contraseña.
            self::cancelar();
        }

        return ['resultado' => $resultado, 'restantes' => max(0, self::INTENTOS - (int) ($reto['intentos'] ?? 0))];
    }

    /** Segundos que faltan para poder pedir otro código; -1 si ya no quedan envíos. */
    public static function esperaReenvio(array $reto, int $ahora): int
    {
        if ((int) ($reto['envios'] ?? 0) >= self::ENVIOS) {
            return -1;
        }

        return max(0, (int) ($reto['enviado'] ?? 0) + self::ESPERA_REENVIO - $ahora);
    }

    /** El correo de la cuenta como se le enseña a quien todavía no entró. */
    public static function correoVisible(array $reto): string
    {
        $email = (string) ($reto['email'] ?? '');

        return $email === '' ? '' : ContactoService::enmascarar(ContactoService::TIPO_EMAIL, $email);
    }
}
