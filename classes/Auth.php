<?php

namespace Classes;

use Model\Usuario;

/**
 * Sesión y protección de rutas del panel.
 *
 * Hay un solo tipo de usuario —administrador— y entra por /admin/login con
 * usuario y contraseña. Todo lo que cuelga de /admin/ queda cerrado por
 * prefijo, y las APIs de horarios que viven fuera de /admin/ se cierran por
 * la lista de abajo: una ruta /api/... que no esté en ella queda pública.
 */
class Auth {

    /** Escrituras de configuración que viven fuera de /admin/. */
    private const APIS_ADMIN = [
        '/api/configuracion/horarios/semanales',
        '/api/configuracion/horarios/especiales',
        '/api/configuracion/horarios/excepciones',
        '/api/configuracion/horarios/excepciones/estado',
    ];

    public static function start(): void {
        if (session_status() === PHP_SESSION_NONE) {
            $environment = (string)(getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? ''));
            if (in_array($environment, ['development', 'test'], true)) {
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

    /** Todo usuario con sesión es administrador: no hay otro tipo de cuenta. */
    public static function esAdmin(): bool {
        return self::check();
    }

    /**
     * Guardia central de rutas: se llama antes de resolver la ruta.
     * /admin y /admin/* (salvo el login) y las APIs de APIS_ADMIN exigen una
     * sesión de administrador. El resto del sitio es público.
     *
     * Sin sesión se redirige a /login (o 401 JSON).
     */
    public static function proteger(string $url): void {
        // El endpoint del formulario de contraseña es público
        if ($url === '/admin/login') {
            return;
        }

        $esAdminUrl = $url === '/admin'
            || str_starts_with($url, '/admin/')
            || in_array($url, self::APIS_ADMIN, true);

        if (!$esAdminUrl) {
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
    }

    private static function negarJson(int $codigo, string $msg): void {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'codigo' => 'NO_AUTORIZADO',
            'mensaje' => $msg,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
