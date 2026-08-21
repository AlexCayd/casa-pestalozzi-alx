<?php

namespace Controllers;

use Classes\Auth;
use Model\Usuario;
use MVC\Router;

class AuthController {
    /**
     * /login: una sola pantalla con dos pestañas.
     *
     * Los dos accesos siguen siendo endpoints distintos (/login y /admin/login)
     * porque su semántica lo es: por NIP la identidad se resuelve sola y el
     * destino depende del rol; por contraseña hay que exigir rol admin y
     * devolver un error deliberadamente genérico. Además, un único formulario
     * con un campo one-time-code y otro current-password confunde a los
     * gestores de contraseñas.
     *
     * Ambos incluyen esta misma vista y fijan $tabActiva, así que un POST
     * fallido vuelve a su pestaña sin depender de JavaScript.
     */
    public static function login(Router $router) {
        // Alguien con sesión activa no ve el login: va directo a su vista.
        if (Auth::check()) {
            header('Location: ' . Auth::destinoPorRol());
            exit;
        }

        $alertas = [];
        $alertasAdmin = [];
        $tabActiva = ($_GET['tab'] ?? '') === 'admin' ? 'admin' : 'nip';

        // El aviso vive en la pestaña de contraseña, así que se abre ahí: sin
        // esto el mensaje se renderiza en un panel oculto y nadie lo lee.
        if (($_GET['resultado'] ?? '') === 'cuenta_eliminada') {
            $alertasAdmin['aviso'][] = 'Tu cuenta fue eliminada. Entra con otro usuario administrador.';
            $tabActiva = 'admin';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $tabActiva = 'nip';
            $nip = trim((string) ($_POST['nip'] ?? ''));

            if (!self::nipLoginPermitido()) {
                $alertas['error'][] = 'NIP incorrecto o usuario inactivo';
            } elseif (!preg_match('/^\d{4}$/', $nip)) {
                self::registrarFalloNip();
                $alertas['error'][] = 'NIP incorrecto o usuario inactivo';
            } else {
                $usuario = Usuario::porNip($nip);

                if ($usuario) {
                    self::limpiarFallosNip();
                    Auth::login($usuario);
                    header('Location: ' . Auth::destinoPorRol($usuario->rol));
                    exit;
                }

                self::registrarFalloNip();
                $alertas['error'][] = 'NIP incorrecto o usuario inactivo';
            }
        }

        include_once __DIR__ . '/../views/auth/login.php';
    }

    /** Limita intentos por sesión y huella de IP sin bloquear cuentas reales. */
    private static function nipLoginPermitido(): bool
    {
        Auth::start();
        $ahora = time();
        $ip = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $estado = $_SESSION['_nip_login_rate'] ?? null;

        if (!is_array($estado) || $estado['ip'] !== $ip || $ahora - (int) ($estado['inicio'] ?? 0) >= 60) {
            $_SESSION['_nip_login_rate'] = ['ip' => $ip, 'inicio' => $ahora, 'fallos' => 0];
            return true;
        }

        return (int) ($estado['fallos'] ?? 0) < 5;
    }

    private static function registrarFalloNip(): void
    {
        Auth::start();
        if (!isset($_SESSION['_nip_login_rate']) || !is_array($_SESSION['_nip_login_rate'])) {
            self::nipLoginPermitido();
        }
        $_SESSION['_nip_login_rate']['fallos'] = (int) ($_SESSION['_nip_login_rate']['fallos'] ?? 0) + 1;
    }

    private static function limpiarFallosNip(): void
    {
        Auth::start();
        unset($_SESSION['_nip_login_rate']);
    }

    /**
     * Acceso del administrador por usuario + contraseña alfanumérica → /admin.
     * Un mesero o cocinero con credenciales válidas no entra por aquí: solo admin.
     *
     * En GET redirige a /login: la pantalla es una sola. Se conserva la ruta
     * para no romper marcadores ni POST antiguos.
     */
    public static function loginAdmin(Router $router) {
        if (Auth::check()) {
            header('Location: ' . Auth::destinoPorRol());
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /login', true, 302);
            exit;
        }

        $alertas = [];
        $alertasAdmin = [];
        $tabActiva = 'admin';

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $alertasAdmin['error'][] = 'Ingresa tu usuario y contraseña';
        } else {
            $usuario = Usuario::porCredenciales($username, $password);

            if ($usuario && $usuario->rol === 'admin') {
                Auth::login($usuario);
                header('Location: /admin');
                exit;
            }

            // Mensaje genérico: no revelar si el usuario existe o si el
            // rol no es admin.
            $alertasAdmin['error'][] = 'Credenciales incorrectas';
        }

        include_once __DIR__ . '/../views/auth/login.php';
    }

    public static function logout() {
        if($_SERVER['REQUEST_METHOD'] === 'POST') {
            Auth::logout();
            header('Location: /login');
            exit;
        }
        header('Location: /');
        exit;
    }
}
