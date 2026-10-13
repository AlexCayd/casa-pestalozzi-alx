<?php

namespace Controllers;

use Classes\Auth;
use Model\Usuario;
use MVC\Router;

class AuthController {
    /**
     * /login: la pantalla de acceso al panel. El formulario envía a
     * /admin/login, que es quien valida.
     */
    public static function login(Router $router) {
        // Alguien con sesión activa no ve el login: va directo al panel.
        if (Auth::check()) {
            header('Location: /admin');
            exit;
        }

        $alertasAdmin = [];

        if (($_GET['resultado'] ?? '') === 'cuenta_eliminada') {
            $alertasAdmin['aviso'][] = 'Tu cuenta fue eliminada. Entra con otro usuario administrador.';
        }

        include_once __DIR__ . '/../views/auth/login.php';
    }

    /**
     * Acceso del administrador por usuario + contraseña alfanumérica → /admin.
     *
     * En GET redirige a /login: la pantalla es una sola.
     */
    public static function loginAdmin(Router $router) {
        if (Auth::check()) {
            header('Location: /admin');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /login', true, 302);
            exit;
        }

        $alertasAdmin = [];

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $alertasAdmin['error'][] = 'Ingresa tu usuario y contraseña';
        } else {
            $usuario = Usuario::porCredenciales($username, $password);

            if ($usuario) {
                Auth::login($usuario);
                header('Location: /admin');
                exit;
            }

            // Mensaje genérico: no revelar si el usuario existe.
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
