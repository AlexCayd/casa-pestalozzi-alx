<?php

namespace Classes;

use Model\Usuario;

/**
 * Sesión y protección de rutas del personal.
 *
 * Un solo formulario en /login con dos pestañas; el rol guardado en la BD
 * decide qué rutas puede visitar cada quien:
 *   - waiter → /punto-de-venta y las APIs del POS
 *   - cook   → /area/* y las APIs de los tableros de producción
 *   - admin  → todo, incluido /admin
 * Cada pestaña envía a su propio endpoint (/login y /admin/login), que siguen
 * existiendo. Venga de donde venga la sesión, el rol es lo único que manda.
 *
 * Las listas de API se separan por rol a propósito: una ruta nueva que no esté
 * en ninguna lista queda pública, así que al añadir un endpoint hay que
 * anotarlo aquí.
 */
class Auth {

    /** APIs del punto de venta: meseros y admin. */
    private const APIS_POS = [
        '/api/punto-de-venta',
        '/api/abrir-ticket',
        '/api/cerrar-ticket',
        '/api/enviar-comanda',
        '/api/entregar-item',
        '/api/actualizar-ticket',
        '/api/cancelar-item',
        '/api/punto-de-venta/reservaciones',
        '/api/punto-de-venta/mesa-contexto',
        '/api/punto-de-venta/reservaciones/comenzar',
        '/api/punto-de-venta/reservaciones/cancelar',
        '/api/punto-de-venta/reservaciones/no-show',
        '/api/corte-caja',
        '/api/sugerencias',
    ];

    /** APIs de los tableros de producción: cocineros y admin. */
    private const APIS_AREA = [
        '/api/area-items',
        '/api/avanzar-item',
        '/api/retroceder-item',
    ];

    /**
     * Lecturas que necesitan los dos roles de piso: el catálogo para pintar la
     * carta y el detalle del ticket, que la comanda del área también consulta.
     */
    private const APIS_PISO = [
        '/api/productos',
        '/api/ticket-items',
    ];

    /** Escrituras de configuración que siempre exigen rol administrador. */
    private const APIS_ADMIN = [
        '/api/configuracion/horarios/semanales',
        '/api/configuracion/horarios/especiales',
        '/api/configuracion/horarios/excepciones',
    ];

    public static function start(): void {
        if (session_status() === PHP_SESSION_NONE) {
            $environment = (string)(getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? ''));
            if (in_array($environment, ['development', 'testing'], true)) {
                $sessionPath = trim((string)(getenv('SESSION_SAVE_PATH') ?: ($_ENV['SESSION_SAVE_PATH'] ?? '')));
                if ($sessionPath === '') {
                    $sessionPath = sys_get_temp_dir();
                }
                if (is_dir($sessionPath) && is_writable($sessionPath)) {
                    ini_set('session.save_path', $sessionPath);
                }
            }
            $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            $params = session_get_cookie_params();
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?? '',
                'secure' => $https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function login(Usuario $usuario): void {
        self::start();
        // Evitar fijación de sesión: id nuevo al autenticarse
        session_regenerate_id(true);
        $_SESSION['login']    = true;
        $_SESSION['id']       = (int) $usuario->id;
        $_SESSION['nombre']   = $usuario->nombre;
        $_SESSION['username'] = $usuario->username;
        $_SESSION['rol']      = $usuario->rol;
    }

    public static function logout(): void {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool)($params['secure'] ?? false),
                'httponly' => (bool)($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    public static function check(): bool {
        self::start();
        return !empty($_SESSION['login']) && !empty($_SESSION['id']);
    }

    public static function rol(): string {
        self::start();
        return (string) ($_SESSION['rol'] ?? '');
    }

    public static function esAdmin(): bool {
        return self::check() && self::rol() === 'admin';
    }

    public static function esMesero(): bool {
        return self::check() && self::rol() === 'waiter';
    }

    public static function esCocinero(): bool {
        return self::check() && self::rol() === 'cook';
    }

    /** Vista inicial que corresponde a un rol tras iniciar sesión. */
    public static function destinoPorRol(?string $rol = null): string {
        $rol = $rol ?? self::rol();

        if ($rol === 'admin') {
            return '/admin';
        }

        return $rol === 'cook' ? '/area' : '/punto-de-venta';
    }

    /**
     * Guardia central de rutas: se llama antes de resolver la ruta.
     * /admin/*                 → solo admin
     * /punto-de-venta, APIs POS → waiter y admin
     * /area, /area/*, APIs área → cook y admin
     * El resto (sitio público, /feedback, /api/feedback…) queda libre.
     *
     * Sin sesión se redirige a /login (o 401 JSON). Con sesión pero sin el rol
     * que toca, se manda a la vista de trabajo del rol (o 403 JSON): no hay
     * página de error, la persona simplemente aterriza donde sí puede operar.
     */
    public static function proteger(string $url): void {
        // El endpoint del formulario de contraseña es público
        if ($url === '/admin/login') {
            return;
        }

        $esAdminUrl = $url === '/admin'
            || str_starts_with($url, '/admin/')
            || in_array($url, self::APIS_ADMIN, true);
        $esPosUrl = $url === '/punto-de-venta'
            || in_array($url, self::APIS_POS, true);
        $esAreaUrl = $url === '/area'
            || str_starts_with($url, '/area/')
            || in_array($url, self::APIS_AREA, true);
        $esPisoUrl = in_array($url, self::APIS_PISO, true);

        if (!$esAdminUrl && !$esPosUrl && !$esAreaUrl && !$esPisoUrl) {
            return;
        }

        $esApi = str_starts_with($url, '/api/') || str_starts_with($url, '/admin/api/');

        if (!self::check()) {
            if ($esApi) {
                self::negarJson(401, 'Sesión expirada. Vuelve a iniciar sesión.');
            }
            header('Location: /login');
            exit;
        }

        // El admin entra a todo; para el resto, cada zona exige su rol.
        $permitido = self::esAdmin()
            || ($esPosUrl && self::esMesero())
            || ($esAreaUrl && self::esCocinero())
            || ($esPisoUrl && (self::esMesero() || self::esCocinero()));

        if (!$permitido) {
            if ($esApi) {
                self::negarJson(403, 'No tienes permisos para esta acción.');
            }
            header('Location: ' . self::destinoPorRol());
            exit;
        }
    }

    /**
     * Libera el candado del archivo de sesión sin perder $_SESSION en memoria.
     *
     * PHP mantiene un lock exclusivo desde session_start() hasta el final del
     * script, así que dos peticiones del mismo navegador se atienden en serie.
     * En el POS eso hacía que /api/ticket-items esperara a que /api/sugerencias
     * terminara su llamada a n8n. Los endpoints de solo lectura llaman a esto
     * justo después de la guardia de Auth para que se atiendan en paralelo.
     *
     * No usar en endpoints que escriban en $_SESSION: los cambios posteriores
     * ya no se persisten.
     */
    public static function liberarSesion(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private static function negarJson(int $codigo, string $msg): void {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'codigo' => $codigo === 401 ? 'NO_AUTORIZADO' : 'PERMISO_DENEGADO',
            'mensaje' => $msg,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
