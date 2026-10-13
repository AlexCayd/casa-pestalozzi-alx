<?php

/**
 * Controlador del módulo de usuarios dentro del panel de administración.
 * Gestiona el listado, creación, edición, activación/desactivación y cambio de contraseña.
 */

namespace Controllers;

use Classes\Auth;
use Model\Usuario;
use MVC\Router;
use Services\Security\AdminCsrfService;
use Services\Users\UsuarioService;

class AdminUsersController
{
    private const MENU_CSS = '/build/css/admin/menu.css';
    private const USERS_CSS = '/build/css/admin/users.css';
    private const ADMIN_ACTIVO_REQUERIDO = 'Debe existir un usuario administrador activo siempre.';

    public static function index(Router $router): void
    {
        $filtros = self::leerFiltros();
        $usuarios = Usuario::buscarAdmin($filtros);
        $alertas = self::alertasResultado($_GET['resultado'] ?? '');

        $data = [
            'title' => 'Usuarios',
            'topbarSection' => 'Usuarios',
            'usuarios' => $usuarios,
            'filtros' => $filtros,
            'filtrosActivos' => self::hayFiltrosActivos($filtros),
            'alertas' => $alertas,
            'totalAdminsActivos' => Usuario::contarAdminsActivos(),
            'partialUrl' => AdminController::filterUrl('/admin/usuarios', $filtros),
            'adminCsrfToken' => AdminCsrfService::token(),
        ];

        if (AdminController::isPartialRequest()) {
            AdminController::renderPartial('users/index', array_merge($data, ['partialOnly' => true]));
            return;
        }

        self::render('users/index', $data);
    }

    public static function userCreate(Router $router): void
    {
        $user = new Usuario();
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!self::adminCsrfValido()) {
                self::redirect('/admin/usuarios?resultado=csrf_invalido');
            }

            $user->sincronizar($_POST);

            // Checkbox: si no viene en POST, queda inactivo.
            $user->activo = isset($_POST['activo']) ? 1 : 0;

            $resultado = UsuarioService::crear($user);
            $user = $resultado['usuario'] ?? $user;
            $alertas = $resultado['alertas'] ?? [];

            if ($resultado['ok'] ?? false) {
                header('Location: /admin/usuarios?resultado=creado');
                exit;
            }

            if (empty($alertas['error'])) {
                $alertas['error'][] = 'No se pudo crear el usuario. Intenta de nuevo.';
            }
        }

        self::render('users/create', [
            'title' => 'Registrar usuario',
            'topbarSection' => 'Usuarios / Nuevo usuario',
            'usuario' => $user,
            'alertas' => $alertas,
            'accion' => 'Crear usuario',
            'modo' => 'crear',
            'action' => '/admin/usuarios/create',
            'adminCsrfToken' => AdminCsrfService::token(),
        ]);
    }

    public static function userEdit(Router $router): void
    {
        $id = self::idFromQuery();

        if (!$id) {
            header('Location: /admin/usuarios');
            exit;
        }

        $user = Usuario::find($id);

        if (!$user) {
            header('Location: /admin/usuarios');
            exit;
        }

        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!self::adminCsrfValido()) {
                self::redirect('/admin/usuarios?resultado=csrf_invalido');
            }

            $datos = $_POST;
            $datos['activo'] = isset($_POST['activo']) ? 1 : 0;
            $resultado = UsuarioService::editar($id, $datos);
            $user = $resultado['usuario'] ?? $user;
            $alertas = $resultado['alertas'] ?? [];

            if ($resultado['ok'] ?? false) {
                $queryResultado = ($resultado['codigo'] ?? '') === UsuarioService::USUARIO_SIN_CAMBIOS
                    ? 'usuario_sin_cambios'
                    : 'actualizado';
                header('Location: /admin/usuarios?resultado=' . $queryResultado);
                exit;
            }

            if (($resultado['codigo'] ?? '') === UsuarioService::ADMIN_ACTIVO_REQUERIDO) {
                $alertas['error'][] = self::ADMIN_ACTIVO_REQUERIDO;
            } elseif (empty($alertas['error'])) {
                $alertas['error'][] = 'No se pudo actualizar el usuario. Intenta de nuevo.';
            }
        }

        self::render('users/edit', [
            'title' => 'Editar usuario',
            'topbarSection' => 'Usuarios / Editar usuario',
            'usuario' => $user,
            'alertas' => $alertas,
            'accion' => 'Guardar cambios',
            'modo' => 'editar',
            'action' => '/admin/usuarios/edit?id=' . $id,
            'adminCsrfToken' => AdminCsrfService::token(),
        ]);
    }

    /** Cambio de contraseña administrativa con reautenticación del actor. */
    public static function cambiarPassword(Router $router): void
    {
        $id = self::idFromQuery();

        if (!$id) {
            header('Location: /admin/usuarios');
            exit;
        }

        $user = Usuario::find($id);

        if (!$user) {
            header('Location: /admin/usuarios');
            exit;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $esPropio = (int) ($_SESSION['id'] ?? 0) === $id;
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!self::adminCsrfValido()) {
                self::redirect('/admin/usuarios?resultado=csrf_invalido');
            }

            $resultado = UsuarioService::cambiarPassword(
                $id,
                (int) ($_SESSION['id'] ?? 0),
                (string) ($_POST['secreto_actual'] ?? ''),
                (string) ($_POST['nuevo'] ?? ''),
                (string) ($_POST['confirmacion'] ?? '')
            );
            $alertas = $resultado['alertas'] ?? [];

            if ($resultado['ok'] ?? false) {
                header('Location: /admin/usuarios?resultado=password');
                exit;
            }

            if (($resultado['codigo'] ?? '') === UsuarioService::CREDENCIAL_ACTUAL_INCORRECTA) {
                // Mensaje deliberadamente genérico: no revela si falló por la
                // contraseña o por el usuario.
                $alertas['error'][] = 'No se pudo verificar tu contraseña de administrador.';
            } elseif (empty($alertas['error'])) {
                $alertas['error'][] = 'No se pudo actualizar la credencial. Intenta de nuevo.';
            }
        }

        self::render('users/change-password', [
            'title' => 'Cambiar contraseña',
            'topbarSection' => 'Usuarios / Cambiar contraseña',
            'usuario' => $user,
            'alertas' => $alertas,
            'esPropio' => $esPropio,
            'action' => '/admin/usuarios/cambiar-password?id=' . $id,
            'adminCsrfToken' => AdminCsrfService::token(),
        ]);
    }

    public static function deactivate(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /admin/usuarios');
            exit;
        }

        if (!self::adminCsrfValido()) {
            self::redirect('/admin/usuarios?resultado=csrf_invalido');
        }

        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);

        if (!$id) {
            self::redirect('/admin/usuarios?resultado=id_invalido');
        }

        $resultado = UsuarioService::cambiarActivo((int)$id, false);
        self::redirect('/admin/usuarios?resultado=' . self::resultadoServicio($resultado));
    }

    public static function activate(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /admin/usuarios');
            exit;
        }

        if (!self::adminCsrfValido()) {
            self::redirect('/admin/usuarios?resultado=csrf_invalido');
        }

        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);

        if (!$id) {
            self::redirect('/admin/usuarios?resultado=id_invalido');
        }

        $resultado = UsuarioService::cambiarActivo((int)$id, true);
        self::redirect('/admin/usuarios?resultado=' . self::resultadoServicio($resultado));
    }

    public static function delete(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect('/admin/usuarios');
        }

        if (!self::adminCsrfValido()) {
            self::redirect('/admin/usuarios?resultado=csrf_invalido');
        }

        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);

        if (!$id) {
            self::redirect('/admin/usuarios?resultado=id_invalido');
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $usuarioActualId = (int) ($_SESSION['id'] ?? 0);
        $resultado = UsuarioService::eliminar((int)$id, $usuarioActualId);

        // Un admin puede borrarse a sí mismo mientras quede otro admin activo.
        // Si lo hace, la sesión apunta a una fila inexistente: hay que cerrarla
        // antes de redirigir o el panel queda en un estado imposible.
        if (($resultado['ok'] ?? false) && ($resultado['autoeliminacion'] ?? false)) {
            Auth::logout();
            self::redirect('/login?resultado=cuenta_eliminada');
        }

        self::redirect('/admin/usuarios?resultado=' . self::resultadoServicio($resultado));
    }

    private static function render(string $view, array $data = []): void
    {
        AdminController::render($view, array_merge([
            'activeModule' => 'users',
            'styles' => [self::MENU_CSS, self::USERS_CSS],
            'scripts' => [],
        ], $data));
    }

    private static function adminCsrfValido(): bool
    {
        return Auth::check()
            && AdminCsrfService::validar($_POST['admin_csrf'] ?? null);
    }

    private static function leerFiltros(): array
    {
        $q = substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $status = (string) ($_GET['status'] ?? '');

        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = '';
        }

        return [
            'q' => $q,
            'status' => $status,
        ];
    }

    private static function hayFiltrosActivos(array $filtros): bool
    {
        foreach ($filtros as $valor) {
            if ((string) $valor !== '') {
                return true;
            }
        }

        return false;
    }

    private static function idFromQuery(): int
    {
        $id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);

        return $id ?: 0;
    }

    private static function alertasResultado(string $resultado): array
    {
        return match ($resultado) {
            'creado' => ['exito' => ['Usuario creado correctamente']],
            'actualizado' => ['exito' => ['Usuario actualizado correctamente']],
            'password' => ['exito' => ['Contraseña actualizada correctamente']],
            'activado' => ['exito' => ['Estado del usuario actualizado correctamente.']],
            'desactivado' => ['exito' => ['Estado del usuario actualizado correctamente.']],
            'eliminado' => ['exito' => ['Usuario eliminado correctamente.']],
            'id_invalido' => ['error' => ['Identificador de usuario no válido.']],
            'no_existe' => ['error' => ['El usuario no existe.']],
            'admin_activo_requerido' => ['error' => [self::ADMIN_ACTIVO_REQUERIDO]],
            'error_eliminar' => ['error' => ['No se pudo eliminar el usuario.']],
            'usuario_activado' => ['exito' => ['Usuario activado correctamente.']],
            'usuario_desactivado' => ['exito' => ['Usuario desactivado correctamente.']],
            'usuario_sin_cambios' => ['warning' => ['El usuario ya tenia el estado solicitado; no se realizaron cambios.']],
            'error_guardado' => ['error' => ['No fue posible guardar el cambio solicitado.']],
            'csrf_invalido' => ['error' => ['La confirmación expiró. Recarga la página e inténtalo de nuevo.']],
            default => [],
        };
    }

    private static function resultadoServicio(array $resultado): string
    {
        return match ((string)($resultado['codigo'] ?? UsuarioService::ERROR_GUARDADO)) {
            UsuarioService::USUARIO_ACTIVADO => 'usuario_activado',
            UsuarioService::USUARIO_DESACTIVADO => 'usuario_desactivado',
            UsuarioService::USUARIO_SIN_CAMBIOS => 'usuario_sin_cambios',
            UsuarioService::USUARIO_ELIMINADO => 'eliminado',
            UsuarioService::ADMIN_ACTIVO_REQUERIDO => 'admin_activo_requerido',
            UsuarioService::USUARIO_NO_EXISTE => 'no_existe',
            default => 'error_guardado',
        };
    }

    private static function redirect(string $url): void
    {
        header('Location: ' . $url, true, 302);
        exit;
    }
}
