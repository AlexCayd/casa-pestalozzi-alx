<?php

namespace Controllers;

use Classes\Auth;
use Model\Usuario;
use MVC\Router;
use Services\Security\DosFactores;

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
        // La guardia cerró la sesión porque el rol se quedó sin módulos. Va
        // en la pestaña de NIP, que es la que abre: la de contraseña sólo
        // pinta sus propios errores.
        if (($_GET['resultado'] ?? '') === 'sin_acceso') {
            $alertas['error'][] = 'Tu rol ya no tiene acceso a ningún módulo. Pide a un administrador que lo revise.';
        }
        if (($_GET['resultado'] ?? '') === 'verificacion_agotada') {
            $alertasAdmin['error'][] = 'Demasiados intentos con el código. Vuelve a iniciar sesión.';
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
                    // La primera página que se pinte lleva la llegada
                    // (views/components/llegada.php).
                    Auth::anunciarLlegada();
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
     * Acceso por usuario + contraseña alfanumérica: los roles de sistema y los
     * que tienen algún módulo del panel. Un rol de piso no entra por aquí.
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

            // porCredenciales() ya sólo encuentra cuentas cuyo rol entra con
            // contraseña (los de sistema y los que tienen módulos del panel).
            if ($usuario && DosFactores::aplica((string) $usuario->rol)) {
                // La contraseña sola no abre sesión: abre el reto del código.
                DosFactores::iniciar($usuario);
                header('Location: /login/verificacion');
                exit;
            }
            if ($usuario) {
                Auth::login($usuario);
                Auth::anunciarLlegada();
                header('Location: ' . Auth::destinoPorRol($usuario->rol));
                exit;
            }

            // Mensaje genérico: no revelar si el usuario existe o si su rol
            // entra por NIP.
            $alertasAdmin['error'][] = 'Credenciales incorrectas';
        }

        include_once __DIR__ . '/../views/auth/login.php';
    }

    /**
     * /login/verificacion: el código de 6 dígitos de los roles de sistema.
     * Sin reto abierto no hay nada que verificar y se vuelve a la contraseña.
     */
    public static function verificacion(Router $router) {
        if (Auth::check()) {
            header('Location: ' . Auth::destinoPorRol());
            exit;
        }
        if (DosFactores::reto() === null) {
            header('Location: /login?tab=admin');
            exit;
        }

        $alertas = [];
        $avisos = [];
        $errorCodigo = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $verificacion = DosFactores::verificar((string) ($_POST['codigo'] ?? ''));
            $resultado = $verificacion['resultado'];

            if ($resultado === 'ok') {
                $usuario = Usuario::find($verificacion['id']);
                if (!$usuario || (int) $usuario->activo !== 1) {
                    header('Location: /login?tab=admin');
                    exit;
                }
                Auth::login($usuario);
                Auth::anunciarLlegada();
                header('Location: ' . Auth::destinoPorRol($usuario->rol));
                exit;
            }
            if ($resultado === 'agotado' || $resultado === 'sin_reto') {
                header('Location: /login?tab=admin' . ($resultado === 'agotado' ? '&resultado=verificacion_agotada' : ''));
                exit;
            }

            $errorCodigo = true;
            $restantes = (int) ($verificacion['restantes'] ?? 0);
            $alertas[] = match ($resultado) {
                'vencido' => 'El código venció. Pide uno nuevo.',
                'formato' => 'Escribe los 6 dígitos del código.',
                default => 'Código incorrecto. ' . ($restantes === 1 ? 'Te queda 1 intento.' : "Te quedan {$restantes} intentos."),
            };
        }

        $reto = DosFactores::reto();
        $codigoVisible = DosFactores::mostrarCodigo() ? ($reto['visible'] ?? null) : null;
        $entrega = (string) ($reto['entrega'] ?? '');

        $aviso = (string) ($_GET['aviso'] ?? '');
        if ($aviso === 'reenviado' && ($codigoVisible !== null || $entrega === 'NOTIFICACION_ACEPTADA')) {
            $avisos[] = 'Te enviamos un código nuevo. El anterior ya no sirve.';
        } elseif ($aviso === 'espera') {
            $avisos[] = 'Espera unos segundos antes de pedir otro código.';
        } elseif ($aviso === 'sin_envios') {
            $avisos[] = 'Ya no se pueden pedir más códigos. Si no te llegó, vuelve a iniciar sesión.';
        }

        // Con el código en pantalla (pruebas) un correo que no salió no
        // bloquea a nadie; en production es lo primero que hay que leer.
        if ($codigoVisible === null && !$errorCodigo) {
            if ($entrega === 'SIN_CORREO') {
                $alertas[] = 'Tu cuenta no tiene un correo registrado. Pide a un super admin que lo agregue en Usuarios.';
            } elseif ($entrega !== 'NOTIFICACION_ACEPTADA') {
                $alertas[] = 'No pudimos enviar el código. Pide uno nuevo en unos segundos.';
            }
        }

        $ahora = time();
        $correo = DosFactores::correoVisible($reto);
        $vence = max(0, (int) $reto['expira'] - $ahora);
        $espera = DosFactores::esperaReenvio($reto, $ahora);

        include_once __DIR__ . '/../views/auth/dos-factores.php';
    }

    /** Código nuevo, con espera entre envíos y tope por reto (DosFactores). */
    public static function reenviarCodigo(Router $router) {
        $reto = DosFactores::reto();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $reto === null) {
            header('Location: /login?tab=admin');
            exit;
        }

        $espera = DosFactores::esperaReenvio($reto, time());
        if ($espera === 0) {
            DosFactores::emitir();
        }
        $aviso = $espera === 0 ? 'reenviado' : ($espera < 0 ? 'sin_envios' : 'espera');
        header('Location: /login/verificacion?aviso=' . $aviso);
        exit;
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
