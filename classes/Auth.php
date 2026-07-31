<?php

namespace Classes;

use Model\Usuario;

/**
 * Sesión y protección de rutas del personal.
 *
 * Dos accesos separados; el rol guardado en la BD decide qué rutas puede
 * visitar cada quien:
 *   - /login (NIP)                    → personal de piso → /punto-de-venta y pantallas de área
 *   - /admin/login (usuario+password) → admin → /admin (panel completo)
 * Venga de donde venga la sesión, /admin/* exige rol admin.
 */
class Auth {

    /** Rutas exactas de API del personal (devuelven 401 JSON en vez de redirigir). */
    private const APIS_STAFF = [
        '/api/punto-de-venta',
        '/api/productos',
        '/api/abrir-ticket',
        '/api/liberar-reservacion',
        '/api/cerrar-ticket',
        '/api/enviar-comanda',
        '/api/ticket-items',
        '/api/entregar-item',
        '/api/actualizar-ticket',
        '/api/cancelar-item',
        '/api/area-items',
        '/api/avanzar-item',
        '/api/retroceder-item',
        '/api/punto-de-venta/reservaciones',
        '/api/punto-de-venta/mesa-contexto',
        '/api/punto-de-venta/reservaciones/llegada',
        '/api/punto-de-venta/reservaciones/comenzar',
        '/api/punto-de-venta/reservaciones/cancelar',
        '/api/punto-de-venta/reservaciones/no-show',
    ];

    /** Escrituras de configuración que siempre exigen rol administrador. */
    private const APIS_ADMIN = [
        '/api/configuracion/horarios/semanales',
        '/api/configuracion/horarios/especiales',
        '/api/configuracion/horarios/excepciones',
    ];

    public static function start(): void {
        if (session_status() === PHP_SESSION_NONE) {
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
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'], $params['secure'], $params['httponly']);
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

    /** Vista inicial que corresponde a un rol tras iniciar sesión. */
    public static function destinoPorRol(?string $rol = null): string {
        $rol = $rol ?? self::rol();
        return $rol === 'admin' ? '/admin' : '/punto-de-venta';
    }

    /**
     * Guardia central de rutas: se llama antes de resolver la ruta.
     * /admin/*            → solo rol admin (sin sesión: a /admin/login)
     * /punto-de-venta, /area/* → cualquier usuario autenticado (sin sesión: a /login)
     * APIs del personal   → cualquier usuario autenticado (401 JSON)
     * El resto (sitio público, /feedback, /api/feedback…) queda libre.
     */
    public static function proteger(string $url): void {
        // El propio formulario de acceso del admin es público
        if ($url === '/admin/login') {
            return;
        }

        $esAdminUrl = $url === '/admin'
            || str_starts_with($url, '/admin/')
            || in_array($url, self::APIS_ADMIN, true);
        $esStaffUrl = $url === '/punto-de-venta'
            || str_starts_with($url, '/area/')
            || in_array($url, self::APIS_STAFF, true);

        if (!$esAdminUrl && !$esStaffUrl) {
            return;
        }

        $esApi = str_starts_with($url, '/api/') || str_starts_with($url, '/admin/api/');

        if (!self::check()) {
            if ($esApi) {
                self::negarJson(401, 'Sesión expirada. Vuelve a iniciar sesión.');
            }
            header('Location: ' . ($esAdminUrl ? '/admin/login' : '/login'));
            exit;
        }

        if ($esAdminUrl && !self::esAdmin()) {
            if ($esApi) {
                self::negarJson(403, 'No tienes permisos para esta acción.');
            }
            // Usuario autenticado sin permisos de admin: a su vista de trabajo
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
