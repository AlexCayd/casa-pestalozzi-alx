<?php

/**
 * Controlador del módulo de usuarios dentro del panel de administración.
 * Gestiona el listado, creación, edición, activación/desactivación y cambio de contraseña.
 */

namespace Controllers;

use Classes\Auth;
use Model\Usuario;
use MVC\Router;
use Services\UsuarioService;

class AdminUsersController
{
    private const MENU_CSS = '/build/css/admin/menu.css';
    private const USERS_CSS = '/build/css/admin/users.css';
    private const USERS_JS = '/build/js/admin/users.js';
    private const ADMIN_ACTIVO_REQUERIDO = 'Debe existir un usuario administrador activo siempre.';
    private const ROLE_LABELS = [
        'admin' => 'Administrador',
        'waiter' => 'Mesero',
        'cook' => 'Cocinero',
    ];

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
            'roleLabels' => self::ROLE_LABELS,
            'alertas' => $alertas,
            'totalAdminsActivos' => Usuario::contarAdminsActivos(),
            'partialUrl' => AdminController::filterUrl('/admin/usuarios', $filtros),
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
            $nuevoRol = (string) ($_POST['rol'] ?? $user->rol);
            $nuevoActivo = isset($_POST['activo']) ? 1 : 0;

            $datos = $_POST;
            $datos['rol'] = $nuevoRol;
            $datos['activo'] = $nuevoActivo;
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
        ]);
    }

    /**
     * Cambio de credencial: contraseña para administradores, NIP de 4 dígitos
     * para el personal de piso. El tipo lo decide el rol del usuario destino.
     */
    public static function cambiarCredencial(Router $router): void
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

        $tipoCredencial = $user->rol === 'admin' ? 'password' : 'nip';
        $esPropio = (int) ($_SESSION['id'] ?? 0) === $id;
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $resultado = UsuarioService::cambiarCredencial(
                $id,
                (int) ($_SESSION['id'] ?? 0),
                (string) ($_POST['secreto_actual'] ?? ''),
                (string) ($_POST['nuevo'] ?? ''),
                (string) ($_POST['confirmacion'] ?? '')
            );
            $alertas = $resultado['alertas'] ?? [];

            if ($resultado['ok'] ?? false) {
                $destino = ($resultado['codigo'] ?? '') === UsuarioService::NIP_ACTUALIZADO
                    ? 'nip'
                    : 'password';
                header('Location: /admin/usuarios?resultado=' . $destino);
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

        $titulo = $tipoCredencial === 'nip' ? 'Cambiar NIP' : 'Cambiar contraseña';

        self::render('users/change-password', [
            'title' => $titulo,
            'topbarSection' => 'Usuarios / ' . $titulo,
            'usuario' => $user,
            'alertas' => $alertas,
            'tipoCredencial' => $tipoCredencial,
            'esPropio' => $esPropio,
            'action' => '/admin/usuarios/cambiar-credencial?id=' . $id,
        ]);
    }

    public static function deactivate(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /admin/usuarios');
            exit;
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
            'scripts' => [self::USERS_JS],
            'roles' => Usuario::rolesPermitidos(),
        ], $data));
    }

    private static function leerFiltros(): array
    {
        $q = substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $role = (string) ($_GET['role'] ?? '');
        $status = (string) ($_GET['status'] ?? '');

        if (!in_array($role, Usuario::rolesPermitidos(), true)) {
            $role = '';
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = '';
        }

        return [
            'q' => $q,
            'role' => $role,
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
            'nip' => ['exito' => ['NIP actualizado correctamente']],
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
