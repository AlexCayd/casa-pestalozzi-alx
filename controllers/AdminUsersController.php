<?php

/**
 * Controlador del módulo de usuarios dentro del panel de administración.
 * Gestiona el listado, creación, edición, activación/desactivación y cambio de contraseña.
 */

namespace Controllers;

use Classes\Auth;
use Model\Rol;
use Model\Unidad;
use Model\Usuario;
use MVC\Router;
use Services\Security\AdminCsrfService;
use Services\Security\RolService;
use Services\Users\UsuarioService;

class AdminUsersController
{
    private const MENU_CSS = '/build/css/admin/menu.css';
    private const USERS_CSS = '/build/css/admin/users.css';
    private const USERS_JS = '/build/js/admin/users.js';
    private const ADMIN_ACTIVO_REQUERIDO = 'Debe existir siempre un super admin activo.';
    private const SIN_PERMISO_CUENTA = 'Tu rol no puede gestionar esa cuenta.';

    /** Roles con sus permisos, una vez por petición. */
    private static ?array $catalogo = null;

    public static function index(Router $router): void
    {
        $filtros = self::leerFiltros();
        $usuarios = Usuario::buscarAdmin($filtros);
        $alertas = self::alertasResultado($_GET['resultado'] ?? '');
        // El listado nunca es una superficie válida para revelar un NIP. Si
        // quedó un flash pendiente por una navegación manual, se consume y se
        // descarta sin incorporarlo al HTML.
        self::consumirNipFlash();

        $data = [
            'title' => 'Usuarios',
            'topbarSection' => 'Usuarios',
            'usuarios' => $usuarios,
            'filtros' => $filtros,
            'filtrosActivos' => self::hayFiltrosActivos($filtros),
            'roleLabels' => self::etiquetasRoles(),
            // El filtro del listado ofrece todos los roles, no sólo los que
            // el actor puede asignar.
            'roles' => array_keys(self::catalogo()),
            // Los roles cuyas cuentas puede tocar el actor: el resto de filas
            // se ve, pero sin acciones.
            'gestionables' => self::rolesAsignables(),
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
            // La unidad sólo la asigna el super admin.
            if (!Auth::esSuperAdmin()) {
                $user->unidad_id = null;
            }

            if (!in_array((string) $user->rol, self::rolesAsignables(), true)) {
                $resultado = ['ok' => false, 'alertas' => ['error' => ['Tu rol no puede asignar ese rol.']]];
            } else {
                $resultado = UsuarioService::crear($user);
            }
            $user = $resultado['usuario'] ?? $user;
            $alertas = $resultado['alertas'] ?? [];

            if ($resultado['ok'] ?? false) {
                $nip = $resultado['nip'] ?? null;
                if ($nip !== null) {
                    self::guardarNipFlash($nip, '/admin/usuarios?resultado=creado', 'alta');
                    header('Location: /admin/usuarios/create?resultado=creado');
                } else {
                    self::limpiarNipFlash();
                    header('Location: /admin/usuarios?resultado=creado');
                }
                exit;
            }

            if (empty($alertas['error'])) {
                $alertas['error'][] = 'No se pudo crear el usuario. Intenta de nuevo.';
            }
        }

        $nipFlash = self::consumirNipFlash();
        if ($nipFlash !== null) {
            self::marcarRespuestaConNip();
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
            'nipFlash' => $nipFlash,
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

        if (!self::puedeGestionarCuenta((string) $user->rol)) {
            self::redirect('/admin/usuarios?resultado=sin_permiso_cuenta');
        }

        $rolOriginal = (string) $user->rol;
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!self::adminCsrfValido()) {
                self::redirect('/admin/usuarios?resultado=csrf_invalido');
            }

            $nuevoRol = (string) ($_POST['rol'] ?? $user->rol);
            $nuevoActivo = isset($_POST['activo']) ? 1 : 0;

            $datos = $_POST;
            $datos['rol'] = $nuevoRol;
            $datos['activo'] = $nuevoActivo;
            // La unidad sólo la cambia el super admin; para el resto se
            // conserva la que tenía.
            if (!Auth::esSuperAdmin()) {
                $datos['unidad_id'] = $user->unidad_id;
            }

            if (!in_array($nuevoRol, self::rolesAsignables(), true)) {
                $resultado = ['ok' => false, 'usuario' => $user, 'alertas' => ['error' => ['Tu rol no puede asignar ese rol.']]];
            } else {
                $resultado = UsuarioService::editar($id, $datos);
            }
            $user = $resultado['usuario'] ?? $user;
            $alertas = $resultado['alertas'] ?? [];

            if ($resultado['ok'] ?? false) {
                $queryResultado = ($resultado['codigo'] ?? '') === UsuarioService::USUARIO_SIN_CAMBIOS
                    ? 'usuario_sin_cambios'
                    : 'actualizado';
                $nip = $resultado['nip'] ?? null;
                if ($nip !== null) {
                    self::guardarNipFlash($nip, '/admin/usuarios?resultado=actualizado', 'edicion');
                    header('Location: /admin/usuarios/edit?id=' . $id . '&resultado=' . $queryResultado);
                } else {
                    self::limpiarNipFlash();
                    header('Location: /admin/usuarios?resultado=' . $queryResultado);
                }
                exit;
            }

            if (($resultado['codigo'] ?? '') === UsuarioService::ADMIN_ACTIVO_REQUERIDO) {
                $alertas['error'][] = self::ADMIN_ACTIVO_REQUERIDO;
            } elseif (empty($alertas['error'])) {
                $alertas['error'][] = 'No se pudo actualizar el usuario. Intenta de nuevo.';
            }
        }

        $nipFlash = self::consumirNipFlash();
        if ($nipFlash !== null) {
            self::marcarRespuestaConNip();
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
            'rolOriginal' => $rolOriginal,
            'nipFlash' => $nipFlash,
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

        if (!Usuario::rolUsaContrasena((string) $user->rol)) {
            header('Location: /admin/usuarios/edit?id=' . $id);
            exit;
        }

        $esPropio = (int) ($_SESSION['id'] ?? 0) === $id;
        // La propia contraseña siempre; la de otra cuenta, sólo si su rol es
        // de los que el actor gestiona.
        if (!$esPropio && !self::puedeGestionarCuenta((string) $user->rol)) {
            self::redirect('/admin/usuarios?resultado=sin_permiso_cuenta');
        }
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

        self::exigirCuentaGestionable((int) $id);
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

        self::exigirCuentaGestionable((int) $id);
        $resultado = UsuarioService::cambiarActivo((int)$id, true);
        self::redirect('/admin/usuarios?resultado=' . self::resultadoServicio($resultado));
    }

    /** Regenera el NIP de un usuario de piso después de una confirmación CSRF. */
    public static function regenerarNip(): void
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

        self::exigirCuentaGestionable((int) $id);
        $resultado = UsuarioService::regenerarNip((int) $id);
        if ($resultado['ok'] ?? false) {
            self::guardarNipFlash(
                $resultado['nip'] ?? null,
                '',
                'regeneracion'
            );
            self::redirect('/admin/usuarios/edit?id=' . (int) $id . '&resultado=nip_regenerado');
        }

        $codigo = (string) ($resultado['codigo'] ?? '');
        if ($codigo === UsuarioService::USUARIO_NO_EXISTE) {
            self::redirect('/admin/usuarios?resultado=no_existe');
        }
        if ($codigo === UsuarioService::ROL_INVALIDO) {
            self::redirect('/admin/usuarios?resultado=rol_invalido');
        }
        if ($codigo === UsuarioService::NIP_CONFIGURACION_INVALIDA || $codigo === UsuarioService::NIP_NO_DISPONIBLE) {
            self::redirect('/admin/usuarios?resultado=nip_error');
        }

        self::redirect('/admin/usuarios?resultado=error_guardado');
    }

    private static function guardarNipFlash(?string $nip, string $afterUrl, string $flujo): void
    {
        self::limpiarNipFlash();
        if ($nip === null || !preg_match('/^\d{4}$/', $nip)) {
            return;
        }

        Auth::start();
        $_SESSION['_admin_user_nip_flash'] = [
            'nip' => $nip,
            'expires_at' => time() + 120,
            'after_url' => $afterUrl,
            'flujo' => $flujo,
        ];
    }

    /** @return array{nip:string, after_url:string, flujo:string}|null */
    private static function consumirNipFlash(): ?array
    {
        Auth::start();
        $flash = $_SESSION['_admin_user_nip_flash'] ?? null;
        unset($_SESSION['_admin_user_nip_flash']);

        if (!is_array($flash) || (int) ($flash['expires_at'] ?? 0) < time()) {
            return null;
        }

        $nip = (string) ($flash['nip'] ?? '');
        if (!preg_match('/^\d{4}$/', $nip)) {
            return null;
        }

        return [
            'nip' => $nip,
            'after_url' => (string) ($flash['after_url'] ?? '/admin/usuarios'),
            'flujo' => (string) ($flash['flujo'] ?? 'alta'),
        ];
    }

    private static function limpiarNipFlash(): void
    {
        Auth::start();
        unset($_SESSION['_admin_user_nip_flash']);
    }

    private static function marcarRespuestaConNip(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
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

        self::exigirCuentaGestionable((int) $id);
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
            // En los formularios sólo se ofrecen los roles que el actor puede
            // asignar; el filtro del listado (index) los quiere todos.
            'roles' => self::rolesAsignables(),
            'catalogoRoles' => self::catalogo(),
            'unidades' => self::unidades(),
            'puedeAsignarUnidad' => Auth::esSuperAdmin(),
        ], $data));
    }

    /**
     * La guardia central ya exigió «Usuarios · editar» para cualquier POST de
     * este módulo; aquí sólo queda la sesión y el token.
     */
    private static function adminCsrfValido(): bool
    {
        return Auth::check()
            && AdminCsrfService::validar($_POST['admin_csrf'] ?? null);
    }

    // ── Roles: qué puede asignar y qué cuentas puede tocar el actor ──

    private static function catalogo(): array
    {
        if (self::$catalogo === null) {
            try {
                self::$catalogo = Rol::catalogo();
            } catch (\Throwable $e) {
                error_log('AdminUsersController::catalogo - ' . $e->getMessage());
                self::$catalogo = [];
            }
        }

        return self::$catalogo;
    }

    /** @return array<string, string> slug => nombre */
    private static function etiquetasRoles(): array
    {
        $etiquetas = [];
        foreach (self::catalogo() as $slug => $info) {
            $etiquetas[$slug] = (string) $info['rol']->nombre;
        }

        return $etiquetas;
    }

    /** @return list<string> */
    private static function rolesAsignables(): array
    {
        return RolService::rolesAsignables(Auth::rolActual(), self::catalogo());
    }

    private static function puedeGestionarCuenta(string $rolCuenta): bool
    {
        return RolService::puedeAsignar(
            Auth::rolActual(),
            $rolCuenta,
            self::catalogo()[$rolCuenta]['permisos'] ?? []
        );
    }

    /** Corta la acción si la cuenta tiene un rol que el actor no gestiona. */
    private static function exigirCuentaGestionable(int $id): void
    {
        $cuenta = Usuario::find($id);
        if ($cuenta && !self::puedeGestionarCuenta((string) $cuenta->rol)) {
            self::redirect('/admin/usuarios?resultado=sin_permiso_cuenta');
        }
    }

    private static function unidades(): array
    {
        try {
            return Unidad::todas();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function leerFiltros(): array
    {
        $q = substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $role = (string) ($_GET['role'] ?? '');
        $status = (string) ($_GET['status'] ?? '');

        if (!isset(self::catalogo()[$role])) {
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
            'nip_regenerado' => ['exito' => ['NIP regenerado. Entrégalo ahora; por seguridad no podrá consultarse nuevamente.']],
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
            'nip_error' => ['error' => ['No fue posible generar un NIP disponible. Intenta nuevamente.']],
            'rol_invalido' => ['error' => ['La regeneración sólo aplica a cuentas que entran con NIP.']],
            'csrf_invalido' => ['error' => ['La confirmación expiró. Recarga la página e inténtalo de nuevo.']],
            'sin_permiso_cuenta' => ['error' => [self::SIN_PERMISO_CUENTA]],
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
