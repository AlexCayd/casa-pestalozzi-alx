<?php

namespace Controllers;

use Classes\Auth;
use Classes\Email;
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

            if (!preg_match('/^\d{4}$/', $nip)) {
                $alertas['error'][] = 'Ingresa un NIP de 4 dígitos';
            } else {
                $usuario = Usuario::porNip($nip);

                if ($usuario) {
                    Auth::login($usuario);
                    header('Location: ' . Auth::destinoPorRol($usuario->rol));
                    exit;
                }

                $alertas['error'][] = 'NIP incorrecto o usuario inactivo';
            }
        }

        include_once __DIR__ . '/../views/auth/login.php';
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

    public static function registro(Router $router) {
        $alertas = [];
        $usuario = new Usuario;

        if($_SERVER['REQUEST_METHOD'] === 'POST') {

            $usuario->sincronizar($_POST);
            
            $alertas = $usuario->validar_cuenta();

            if(empty($alertas)) {
                $existeUsuario = Usuario::where('email', $usuario->email);

                if($existeUsuario) {
                    Usuario::setAlerta('error', 'El Usuario ya esta registrado');
                    $alertas = Usuario::getAlertas();
                } else {
                    // Hashear el password
                    $usuario->hashPassword();

                    // Eliminar password2
                    unset($usuario->password2);

                    // Generar el Token
                    $usuario->crearToken();

                    // Crear un nuevo usuario
                    $resultado =  $usuario->guardar();

                    // Enviar email
                    $email = new Email($usuario->email, $usuario->nombre, $usuario->token);
                    $email->enviarConfirmacion();
                    

                    if($resultado) {
                        header('Location: /mensaje');
                    }
                }
            }
        }

        // Render a la vista
        $router->render('auth/registro', [
            'titulo' => 'Crea tu cuenta en DevWebcamp',
            'usuario' => $usuario, 
            'alertas' => $alertas
        ]);
    }

    public static function olvide(Router $router) {
        $alertas = [];
        
        if($_SERVER['REQUEST_METHOD'] === 'POST') {
            $usuario = new Usuario($_POST);
            $alertas = $usuario->validarEmail();

            if(empty($alertas)) {
                // Buscar el usuario
                $usuario = Usuario::where('email', $usuario->email);

                if($usuario && $usuario->confirmado) {

                    // Generar un nuevo token
                    $usuario->crearToken();
                    unset($usuario->password2);

                    // Actualizar el usuario
                    $usuario->guardar();

                    // Enviar el email
                    $email = new Email( $usuario->email, $usuario->nombre, $usuario->token );
                    $email->enviarInstrucciones();


                    // Imprimir la alerta
                    // Usuario::setAlerta('exito', 'Hemos enviado las instrucciones a tu email');

                    $alertas['exito'][] = 'Hemos enviado las instrucciones a tu email';
                } else {
                 
                    // Usuario::setAlerta('error', 'El Usuario no existe o no esta confirmado');

                    $alertas['error'][] = 'El Usuario no existe o no esta confirmado';
                }
            }
        }

        // Muestra la vista
        $router->render('auth/olvide', [
            'titulo' => 'Olvide mi Password',
            'alertas' => $alertas
        ]);
    }

    public static function reestablecer(Router $router) {

        $token = s($_GET['token']);

        $token_valido = true;

        if(!$token) header('Location: /');

        // Identificar el usuario con este token
        $usuario = Usuario::where('token', $token);

        if(empty($usuario)) {
            Usuario::setAlerta('error', 'Token No Válido, intenta de nuevo');
            $token_valido = false;
        }


        if($_SERVER['REQUEST_METHOD'] === 'POST') {

            // Añadir el nuevo password
            $usuario->sincronizar($_POST);

            // Validar el password
            $alertas = $usuario->validarPassword();

            if(empty($alertas)) {
                // Hashear el nuevo password
                $usuario->hashPassword();

                // Eliminar el Token
                $usuario->token = null;

                // Guardar el usuario en la BD
                $resultado = $usuario->guardar();

                // Redireccionar
                if($resultado) {
                    header('Location: /');
                }
            }
        }

        $alertas = Usuario::getAlertas();
        
        // Muestra la vista
        $router->render('auth/reestablecer', [
            'titulo' => 'Reestablecer Password',
            'alertas' => $alertas,
            'token_valido' => $token_valido
        ]);
    }

    public static function mensaje(Router $router) {

        $router->render('auth/mensaje', [
            'titulo' => 'Cuenta Creada Exitosamente'
        ]);
    }

    public static function confirmar(Router $router) {
        
        $token = s($_GET['token']);

        if(!$token) header('Location: /');

        // Encontrar al usuario con este token
        $usuario = Usuario::where('token', $token);

        if(empty($usuario)) {
            // No se encontró un usuario con ese token
            Usuario::setAlerta('error', 'Token No Válido');
        } else {
            // Confirmar la cuenta
            $usuario->confirmado = 1;
            $usuario->token = '';
            unset($usuario->password2);
            
            // Guardar en la BD
            $usuario->guardar();

            Usuario::setAlerta('exito', 'Cuenta Comprobada Correctamente');
        }

     

        $router->render('auth/confirmar', [
            'titulo' => 'Confirma tu cuenta DevWebcamp',
            'alertas' => Usuario::getAlertas()
        ]);
    }
}
