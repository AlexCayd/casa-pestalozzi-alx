<?php

/**
 * Roles y permisos: el módulo donde se crean roles y se decide a qué partes
 * del sistema entra cada uno.
 *
 * La guardia central (Classes\Auth) ya exige «Roles y permisos · ver» para
 * mirar y «editar» para cualquier POST. Lo que queda aquí son las reglas de
 * contenido —qué rol se puede tocar, qué permisos se pueden dar—, que viven
 * en Services\Security\RolService.
 */

namespace Controllers;

use Classes\Auth;
use Model\Rol;
use MVC\Router;
use Services\Security\AdminCsrfService;
use Services\Security\Permisos;
use Services\Security\RolService;

class AdminRolesController
{
    private const MENU_CSS = '/build/css/admin/menu.css';
    private const RUTA = '/admin/roles';

    public static function index(Router $router): void
    {
        $catalogo = Rol::catalogo();
        $usuarios = Rol::usuariosPorRol();
        $actor = Auth::rolActual();
        $puedeEditar = Auth::puede('roles', Permisos::EDITAR);

        $filas = [];
        foreach ($catalogo as $slug => $info) {
            $rol = $info['rol'];
            $filas[] = [
                'rol' => $rol,
                'permisos' => $info['permisos'],
                'acceso' => $info['acceso'],
                'usuarios' => $usuarios[$slug] ?? 0,
                'gestionable' => $puedeEditar && RolService::puedeGestionar($actor, (string) $rol->tipo, $info['permisos']),
            ];
        }

        self::render('roles/index', [
            'title' => 'Roles y permisos',
            'topbarSection' => 'Roles y permisos',
            'filas' => $filas,
            'puedeEditar' => $puedeEditar,
            'alertas' => self::alertasResultado((string) ($_GET['resultado'] ?? '')),
            'adminCsrfToken' => AdminCsrfService::token(),
        ]);
    }

    public static function crear(Router $router): void
    {
        $datos = ['nombre' => '', 'descripcion' => '', 'permisos' => []];
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!AdminCsrfService::validar($_POST['admin_csrf'] ?? null)) {
                self::redirect(self::RUTA . '?resultado=csrf_invalido');
            }
            $datos = self::datosDelFormulario();
            $resultado = RolService::guardar(0, $datos, Auth::rolActual());
            if ($resultado['ok']) {
                self::redirect(self::RUTA . '?resultado=creado');
            }
            $alertas['error'] = $resultado['errores'] ?? ['No fue posible guardar el rol. Intenta de nuevo.'];
        }

        self::renderFormulario('Nuevo rol', 'Crear rol', self::RUTA . '/crear', $datos, $alertas, null, 0);
    }

    public static function editar(Router $router): void
    {
        $id = (int) filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $rol = $id > 0 ? Rol::find($id) : null;
        if (!$rol) {
            self::redirect(self::RUTA . '?resultado=no_existe');
        }

        $datos = [
            'nombre' => (string) $rol->nombre,
            'descripcion' => (string) $rol->descripcion,
            'permisos' => $rol->permisos(),
        ];
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!AdminCsrfService::validar($_POST['admin_csrf'] ?? null)) {
                self::redirect(self::RUTA . '?resultado=csrf_invalido');
            }
            $datos = self::datosDelFormulario();
            $resultado = RolService::guardar($id, $datos, Auth::rolActual());
            if ($resultado['ok']) {
                self::redirect(self::RUTA . '?resultado=actualizado');
            }
            if (($resultado['codigo'] ?? '') === RolService::NO_EXISTE) {
                self::redirect(self::RUTA . '?resultado=no_existe');
            }
            $alertas['error'] = $resultado['errores'] ?? ['No fue posible guardar el rol. Intenta de nuevo.'];
        }

        self::renderFormulario(
            'Editar rol',
            'Guardar cambios',
            self::RUTA . '/editar?id=' . $id,
            $datos,
            $alertas,
            $rol,
            Rol::usuariosPorRol()[(string) $rol->slug] ?? 0
        );
    }

    public static function eliminar(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::RUTA);
        }
        if (!AdminCsrfService::validar($_POST['admin_csrf'] ?? null)) {
            self::redirect(self::RUTA . '?resultado=csrf_invalido');
        }

        $id = (int) filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $resultado = RolService::eliminar($id, Auth::rolActual());
        if ($resultado['ok']) {
            self::redirect(self::RUTA . '?resultado=eliminado');
        }

        // El motivo concreto (cuántos usuarios lo tienen, por qué está
        // protegido) viaja por la sesión: en la URL sólo cabe un código.
        Auth::start();
        $_SESSION['_roles_error'] = $resultado['errores'][0] ?? 'No fue posible eliminar el rol.';
        self::redirect(self::RUTA . '?resultado=error_eliminar');
    }

    // ── Internos ──────────────────────────────────────────────────

    /** @return array{nombre: string, descripcion: string, permisos: array<string, string>} */
    private static function datosDelFormulario(): array
    {
        $permisos = [];
        foreach ((array) ($_POST['permisos'] ?? []) as $modulo => $nivel) {
            if (is_string($modulo) && is_string($nivel) && $nivel !== '') {
                $permisos[$modulo] = $nivel;
            }
        }

        return [
            'nombre' => substr(trim((string) ($_POST['nombre'] ?? '')), 0, 120),
            'descripcion' => substr(trim((string) ($_POST['descripcion'] ?? '')), 0, 500),
            'permisos' => Permisos::normalizar($permisos),
        ];
    }

    private static function renderFormulario(
        string $titulo,
        string $accion,
        string $action,
        array $datos,
        array $alertas,
        ?Rol $rol,
        int $usuarios
    ): void {
        $actor = Auth::rolActual();
        $tipo = $rol ? (string) $rol->tipo : Rol::TIPO_PERSONALIZADO;
        $soloLectura = !Auth::puede('roles', Permisos::EDITAR)
            || ($rol !== null && !RolService::puedeGestionar($actor, $tipo, $rol->permisos()));

        // Un rol que no se puede tocar se abre para consultar: el título no
        // promete una edición que no hay.
        if ($soloLectura && $rol !== null) {
            $titulo = 'Permisos del rol';
        }

        self::render('roles/form', [
            'title' => $titulo,
            'topbarSection' => 'Roles y permisos / ' . $titulo,
            'accion' => $accion,
            'action' => $action,
            'datos' => $datos,
            'rol' => $rol,
            'tipo' => $tipo,
            'usuarios' => $usuarios,
            'soloLectura' => $soloLectura,
            // El tope de lo que el actor puede dar, módulo por módulo: las
            // opciones por encima se pintan deshabilitadas (el servidor lo
            // vuelve a comprobar al guardar).
            'topeActor' => $actor['sistema'] ? Permisos::todos() : $actor['permisos'],
            'alertas' => $alertas,
            'adminCsrfToken' => AdminCsrfService::token(),
        ]);
    }

    private static function alertasResultado(string $resultado): array
    {
        if ($resultado === 'error_eliminar') {
            Auth::start();
            $mensaje = (string) ($_SESSION['_roles_error'] ?? 'No fue posible eliminar el rol.');
            unset($_SESSION['_roles_error']);
            return ['error' => [$mensaje]];
        }

        return match ($resultado) {
            'creado' => ['exito' => ['Rol creado. Ya puedes asignarlo desde Usuarios.']],
            'actualizado' => ['exito' => ['Rol actualizado. Los cambios valen desde la siguiente acción de cada usuario, sin cerrar su sesión.']],
            'eliminado' => ['exito' => ['Rol eliminado.']],
            'no_existe' => ['error' => ['El rol no existe.']],
            'csrf_invalido' => ['error' => ['La confirmación expiró. Recarga la página e inténtalo de nuevo.']],
            default => [],
        };
    }

    private static function render(string $view, array $data): void
    {
        AdminController::render($view, array_merge([
            'activeModule' => 'roles',
            'styles' => [self::MENU_CSS],
            'scripts' => [],
        ], $data));
    }

    private static function redirect(string $url): void
    {
        header('Location: ' . $url, true, 302);
        exit;
    }
}
