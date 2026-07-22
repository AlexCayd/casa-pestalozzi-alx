<?php

/**
 * Controlador del módulo de usuarios dentro del panel de administración.
 * Gestiona el listado, creación, edición, activación/desactivación y cambio de contraseña.
 */

namespace Controllers;

use Model\Usuario;
use MVC\Router;
use Services\UsuarioService;

class AdminUsersController
{
    private const MENU_CSS = '/build/css/admin/menu.css';
    private const USERS_CSS = '/build/css/admin/users.css';
    private const ADMIN_ACTIVO_REQUERIDO = 'Debe existir un usuario administrador activo siempre.';
    private const ROLE_LABELS = [
        'admin' => 'Administrador',
        'waiter' => 'Mesero',
        'cashier' => 'Cajero',
        'observer' => 'Observador',
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

    public static function changePassword(Router $router): void
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
            $resultado = UsuarioService::cambiarPassword(
                $id,
                (string)($_POST['password'] ?? ''),
                (string)($_POST['password_confirm'] ?? '')
            );
            $user = $resultado['usuario'] ?? $user;
            $alertas = $resultado['alertas'] ?? [];

            if ($resultado['ok'] ?? false) {
                header('Location: /admin/usuarios?resultado=password');
                exit;
            }

            if (empty($alertas['error'])) {
                $alertas['error'][] = 'No se pudo actualizar la clave. Intenta de nuevo.';
            }
        }

        self::render('users/change-password', [
            'title' => 'Cambiar contraseña',
            'topbarSection' => 'Usuarios / Cambiar contraseña',
            'usuario' => $user,
            'alertas' => $alertas,
            'action' => '/admin/usuarios/change-password?id=' . $id,
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
        self::redirect('/admin/usuarios?resultado=' . self::resultadoServicio($resultado));
    }

    private static function render(string $view, array $data = []): void
    {
        AdminController::render($view, array_merge([
            'activeModule' => 'users',
            'styles' => [self::MENU_CSS, self::USERS_CSS],
            'scripts' => [],
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
            'activado' => ['exito' => ['Estado del usuario actualizado correctamente.']],
            'desactivado' => ['exito' => ['Estado del usuario actualizado correctamente.']],
            'eliminado' => ['exito' => ['Usuario eliminado correctamente.']],
            'id_invalido' => ['error' => ['Identificador de usuario no válido.']],
            'no_existe' => ['error' => ['El usuario no existe.']],
            'autoeliminacion' => ['error' => ['No puedes eliminar tu propio usuario.']],
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
            UsuarioService::AUTOELIMINACION => 'autoeliminacion',
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
