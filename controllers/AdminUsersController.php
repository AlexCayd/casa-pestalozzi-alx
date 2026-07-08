<?php

/**
 * Controlador del módulo de usuarios dentro del panel de administración.
 * Gestiona el listado, creación, edición, activación/desactivación y cambio de contraseña.
 */

namespace Controllers;

use Model\Usuario;
use MVC\Router;

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

        self::render('users/index', [
            'title' => 'Usuarios',
            'topbarSection' => 'Usuarios',
            'usuarios' => $usuarios,
            'filtros' => $filtros,
            'filtrosActivos' => self::hayFiltrosActivos($filtros),
            'roleLabels' => self::ROLE_LABELS,
            'alertas' => $alertas,
            'totalAdminsActivos' => Usuario::contarAdminsActivos(),
        ]);
    }

    public static function userCreate(Router $router): void
    {
        $user = new Usuario();
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $user->sincronizar($_POST);

            // Checkbox: si no viene en POST, queda inactivo.
            $user->activo = isset($_POST['activo']) ? 1 : 0;

            $alertas = $user->validarCrear();

            if (empty($alertas['error'])) {
                $user->hashPassword();

                $resultado = $user->guardar();

                if ($resultado && $resultado['resultado']) {
                    header('Location: /admin/usuarios?resultado=creado');
                    exit;
                }

                Usuario::setAlerta('error', 'No se pudo crear el usuario. Intenta de nuevo.');
                $alertas = Usuario::getAlertas();
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

            if (
                $user->esAdminActivo()
                && Usuario::contarAdminsActivos() <= 1
                && ($nuevoRol !== 'admin' || $nuevoActivo !== 1)
            ) {
                $alertas['error'][] = self::ADMIN_ACTIVO_REQUERIDO;
            } else {
                $user->sincronizar($_POST);

                // Checkbox: si no viene en POST, queda inactivo.
                $user->activo = $nuevoActivo;

                $alertas = $user->validarEditar();

                if (empty($alertas['error'])) {
                    $resultado = $user->guardar();

                    if ($resultado) {
                        header('Location: /admin/usuarios?resultado=actualizado');
                        exit;
                    }

                    Usuario::setAlerta('error', 'No se pudo actualizar el usuario. Intenta de nuevo.');
                    $alertas = Usuario::getAlertas();
                }
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
            $user->password = $_POST['password'] ?? '';
            $user->password_confirm = $_POST['password_confirm'] ?? '';

            $alertas = $user->validarCambioPassword();

            if (empty($alertas['error'])) {
                $user->hashPassword();

                $resultado = $user->guardar();

                if ($resultado) {
                    header('Location: /admin/usuarios?resultado=password');
                    exit;
                }

                Usuario::setAlerta('error', 'No se pudo actualizar la contraseña. Intenta de nuevo.');
                $alertas = Usuario::getAlertas();
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

        $user = Usuario::find($id);

        if (!$user) {
            self::redirect('/admin/usuarios?resultado=no_existe');
        }

        if ($user->esAdminActivo() && Usuario::contarAdminsActivos() <= 1) {
            self::redirect('/admin/usuarios?resultado=admin_activo_requerido');
        }

        $user->activo = 0;
        $user->guardar();

        header('Location: /admin/usuarios?resultado=desactivado');
        exit;
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

        $user = Usuario::find($id);

        if (!$user) {
            self::redirect('/admin/usuarios?resultado=no_existe');
        }

        $user->activo = 1;
        $user->guardar();

        header('Location: /admin/usuarios?resultado=activado');
        exit;
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

        $user = Usuario::find($id);

        if (!$user) {
            self::redirect('/admin/usuarios?resultado=no_existe');
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $usuarioActualId = (int) ($_SESSION['id'] ?? 0);
        if ($usuarioActualId > 0 && (int) $user->id === $usuarioActualId) {
            self::redirect('/admin/usuarios?resultado=autoeliminacion');
        }

        if ($user->esAdminActivo() && Usuario::contarAdminsActivos() <= 1) {
            self::redirect('/admin/usuarios?resultado=admin_activo_requerido');
        }

        if ($user->eliminar()) {
            self::redirect('/admin/usuarios?resultado=eliminado');
        }

        self::redirect('/admin/usuarios?resultado=error_eliminar');
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
            default => [],
        };
    }

    private static function redirect(string $url): void
    {
        header('Location: ' . $url, true, 302);
        exit;
    }
}
