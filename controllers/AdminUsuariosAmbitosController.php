<?php

namespace Controllers;

use Classes\Auth;
use MVC\Router;
use Services\Security\AdminCsrfService;
use Services\Security\Contextos;
use Services\Security\PermisosUsuario;
use Services\Users\UsuarioService;

/** Interfaz única de permisos directos, compartida por los tres ámbitos. */
final class AdminUsuariosAmbitosController
{
    private const MENU_CSS = '/build/css/admin/menu.css';
    private const USERS_CSS = '/build/css/admin/users.css';
    private const DIRECT_USERS_JS = '/build/js/admin/users-directos.js';

    public static function index(Router $router): void
    {
        $contexto = self::contexto();
        $actorId = self::actorId();
        $actor = PermisosUsuario::cargar($actorId);
        $filtros = [
            'q' => self::query('q'),
            'status' => self::query('status'),
        ];
        if ($contexto === 'global') {
            $ambito = self::query('ambito');
            if ($ambito !== '' && isset(Contextos::NOMBRES[$ambito])) {
                $filtros['ambito'] = $ambito;
            }
        }

        $resultado = UsuarioService::listarDirectos($actorId, $contexto, $filtros);
        $usuarios = self::lista($resultado);
        $alertas = self::flashAlertas();
        if (empty($resultado['ok'])) {
            $alertas['error'][] = self::mensajeCodigo(self::codigo($resultado));
        }
        // Abrir el listado cancela cualquier NIP pendiente; solo el edit exacto puede leerlo.
        self::consumirNipFlash(null, $contexto);
        self::noStore();

        self::render('users/directo-index', [
            'title' => 'Usuarios',
            'topbarSection' => 'Usuarios',
            'contextoUsuarios' => $contexto,
            'contextoNombre' => Contextos::NOMBRES[$contexto] ?? 'Usuarios',
            'usuarios' => $usuarios,
            'filtros' => $filtros,
            'filtrosActivos' => $filtros['q'] !== '' || $filtros['status'] !== '' || !empty($filtros['ambito']),
            'alertas' => $alertas,
            'adminCsrfToken' => AdminCsrfService::token(),
            'baseUrl' => self::baseUrl($contexto),
            'puedeEditar' => is_array($actor) && self::puedeEditar($actor, $contexto),
        ]);
    }

    public static function crear(Router $router): void
    {
        self::formulario(null);
    }

    public static function editar(Router $router): void
    {
        $id = self::id($_GET['id'] ?? null);
        if ($id === null) {
            self::redirect(self::baseUrl(self::contexto()) . '?resultado=no_encontrado');
        }
        self::formulario($id);
    }

    public static function guardar(Router $router): void
    {
        $contexto = self::contexto();
        if (!self::postCsrfValido()) {
            self::redirect(self::baseUrl($contexto) . '?resultado=csrf_invalido');
        }
        $id = self::id($_POST['id'] ?? null);
        $datos = self::datosPost();
        $resultado = UsuarioService::guardarDirecto(self::actorId(), $contexto, $id, $datos);

        if (!empty($resultado['ok'])) {
            $usuario = is_array($resultado['usuario'] ?? null) ? $resultado['usuario'] : [];
            $usuarioId = (int) ($usuario['id'] ?? $id ?? 0);
            if (isset($resultado['nip']) && preg_match('/^\d{4}$/', (string) $resultado['nip'])) {
                self::noStore();
                if ($usuarioId > 0) self::guardarNipFlash((string) $resultado['nip'], self::baseUrl($contexto) . '?resultado=guardado', $contexto, $usuarioId, 'guardado');
            }
            self::redirect(self::baseUrl($contexto) . ($usuarioId > 0 ? '/edit?id=' . $usuarioId . '&resultado=guardado' : '?resultado=guardado'));
        }

        self::formulario($id, $resultado, $datos);
    }

    public static function estado(Router $router): void
    {
        self::mutacionSimple('estado');
    }

    public static function activar(Router $router): void
    {
        self::setEstado(true);
    }

    public static function desactivar(Router $router): void
    {
        self::setEstado(false);
    }

    public static function eliminar(Router $router): void
    {
        self::mutacionSimple('eliminar');
    }

    public static function regenerarNip(Router $router): void
    {
        self::mutacionSimple('regenerar-nip');
    }

    private static function mutacionSimple(string $accion): void
    {
        $contexto = self::contexto();
        if (!self::postCsrfValido()) {
            self::redirect(self::baseUrl($contexto) . '?resultado=csrf_invalido');
        }
        $id = self::id($_POST['id'] ?? null);
        if ($id === null) {
            self::redirect(self::baseUrl($contexto) . '?resultado=no_encontrado');
        }

        if ($accion === 'estado') {
            $activo = in_array((string) ($_POST['activo'] ?? ''), ['1', 'true', 'on'], true);
            $resultado = UsuarioService::estadoDirecto(self::actorId(), $contexto, $id, $activo);
        } elseif ($accion === 'eliminar') {
            $resultado = UsuarioService::eliminarDirecto(self::actorId(), $contexto, $id);
        } else {
            $resultado = UsuarioService::regenerarNipDirecto(self::actorId(), $contexto, $id);
        }

        if (!empty($resultado['ok']) && $accion === 'regenerar-nip'
            && preg_match('/^\d{4}$/', (string) ($resultado['nip'] ?? ''))) {
            self::noStore();
            self::guardarNipFlash((string) $resultado['nip'], self::baseUrl($contexto) . '?resultado=nip_regenerado', $contexto, $id, 'nip_regenerado');
            self::redirect(self::baseUrl($contexto) . '/edit?id=' . $id . '&resultado=nip_regenerado');
        }
        $codigoExito = $accion === 'eliminar' && strtoupper((string) ($resultado['codigo'] ?? '')) === 'MEMBRESIA_REVOCADA'
            ? 'membresia_revocada'
            : $accion . '_ok';
        self::redirect(self::baseUrl($contexto) . '?resultado=' . (!empty($resultado['ok']) ? $codigoExito : self::codigo($resultado)));
    }

    private static function setEstado(bool $activo): void
    {
        $contexto = self::contexto();
        if (!self::postCsrfValido()) self::redirect(self::baseUrl($contexto) . '?resultado=csrf_invalido');
        $id = self::id($_POST['id'] ?? null);
        if ($id === null) self::redirect(self::baseUrl($contexto) . '?resultado=no_encontrado');
        $resultado = UsuarioService::estadoDirecto(self::actorId(), $contexto, $id, $activo);
        self::redirect(self::baseUrl($contexto) . '?resultado=' . (!empty($resultado['ok']) ? 'estado_ok' : self::codigo($resultado)));
    }

    private static function formulario(?int $id, array $resultado = [], array $datosEnviados = []): void
    {
        $contexto = self::contexto();
        $actorId = self::actorId();
        $perfil = [];
        $alertas = self::errores($resultado);
        if ($id !== null) {
            $detalle = UsuarioService::detalleDirecto($actorId, $contexto, $id);
            if (empty($detalle['ok']) || !is_array($detalle['usuario'] ?? null)) {
                self::redirect(self::baseUrl($contexto) . '?resultado=no_encontrado');
            }
            $perfil = $detalle['usuario'];
            $tienePanelNoEditable = false;
            if ($contexto !== 'global') {
                foreach ((array) ($perfil['ambitos'] ?? []) as $slug => $ambitoTarget) {
                    if ($slug === $contexto || !is_array($ambitoTarget)) continue;
                    $defs = self::catalogo((string) $slug);
                    foreach ((array) ($ambitoTarget['permisos'] ?? []) as $modulo => $nivel) {
                        if ($nivel !== '' && $nivel !== null && ($defs[$modulo]['grupo'] ?? 'panel') !== 'piso') {
                            $tienePanelNoEditable = true;
                            break 2;
                        }
                    }
                }
            }
            $perfil['panel_remoto'] = ($perfil['tipo_acceso'] ?? '') === 'contrasena' && $tienePanelNoEditable;
        }
        if ($datosEnviados !== []) {
            $perfil = array_merge(is_array($perfil) ? $perfil : [], $datosEnviados);
        }

        $actor = PermisosUsuario::cargar($actorId);
        if (!is_array($actor)) {
            self::redirect(self::baseUrl($contexto) . '?resultado=sin_permiso');
        }
        $ambitos = self::ambitosPermitidos($actor, $contexto);
        $puedeEditar = self::puedeEditar($actor, $contexto);
        $catalogos = [];
        foreach ($ambitos as $slug) {
            $catalogos[$slug] = self::catalogo($slug);
        }
        $targetAmbitos = is_array($perfil['ambitos'] ?? null) ? $perfil['ambitos'] : [];
        $esCompartida = $id !== null && $contexto !== 'global'
            && (!empty($perfil['compartida']) || !empty($perfil['es_compartida']) || count(array_filter($targetAmbitos, static fn($a) => is_array($a) && !empty($a['activo']))) > 1);
        $esSuper = !empty($perfil['super']) || !empty($perfil['es_superadmin']);
        $identidadEditable = $id === null || $contexto === 'global'
            || (!$esCompartida && !$esSuper);
        $identity = [
            'id' => $id,
            'username' => (string) ($perfil['username'] ?? ''),
            'nombre' => (string) ($perfil['nombre'] ?? ''),
            'activo' => array_key_exists('activo', $perfil) ? (bool) $perfil['activo'] : true,
            'ambitos' => $targetAmbitos,
            'es_superadmin' => $esSuper,
            'es_compartida' => $esCompartida,
            'tipo_acceso' => (string) ($perfil['tipo_acceso'] ?? 'ninguno'),
            'panel_remoto' => !empty($perfil['panel_remoto']),
        ];
        $filtros = [];
        $base = self::baseUrl($contexto);
        self::noStore();

        self::render('users/directo-form', [
            'title' => $id === null ? 'Nuevo usuario' : 'Editar usuario',
            'topbarSection' => 'Usuarios / ' . ($id === null ? 'Nuevo usuario' : 'Editar'),
            'contextoUsuarios' => $contexto,
            'contextoNombre' => Contextos::NOMBRES[$contexto] ?? 'Usuarios',
            'usuario' => $identity,
            'ambitosPermitidos' => $ambitos,
            'catalogos' => $catalogos,
            'facultadesPorAmbito' => self::facultadesPorAmbito($actor, $contexto, $ambitos),
            'techosPorAmbito' => self::techosPorAmbito($actor, $contexto, $ambitos),
            'identityEditable' => $identidadEditable && $puedeEditar,
            'puedeEditar' => $puedeEditar,
            'esCompartida' => $esCompartida,
            'esSuper' => $esSuper,
            'alertas' => $alertas,
            'adminCsrfToken' => AdminCsrfService::token(),
            'baseUrl' => $base,
            'nipFlash' => self::consumirNipFlash($id, $contexto),
        ]);
    }

    private static function render(string $view, array $data): void
    {
        AdminController::render($view, array_merge([
            'activeModule' => 'users',
            'styles' => [self::MENU_CSS, self::USERS_CSS],
            'scripts' => [self::DIRECT_USERS_JS],
        ], $data));
    }

    private static function contexto(): string
    {
        return Contextos::deRuta((string) ($_SERVER['PATH_INFO'] ?? '/admin/usuarios'));
    }

    private static function baseUrl(string $contexto): string
    {
        return $contexto === 'restaurante' ? '/admin/usuarios' : '/admin/' . $contexto . '/usuarios';
    }

    private static function actorId(): int
    {
        Auth::start();
        return (int) ($_SESSION['id'] ?? 0);
    }

    private static function ambitosPermitidos(array $actor, string $contexto): array
    {
        if (!empty($actor['super'])) return $contexto === 'global' ? array_keys(Contextos::NOMBRES) : [$contexto];
        $current = $actor['ambitos'][$contexto] ?? [];
        if ($contexto === 'global'
            && is_array($current)
            && !empty($current['facultades'])
            && in_array('usuarios.gestionar', $current['facultades'], true)
            && in_array($current['permisos']['users'] ?? null, ['editar'], true)) {
            return array_keys(Contextos::NOMBRES);
        }
        return isset($actor['ambitos'][$contexto]) ? [$contexto] : [];
    }

    private static function puedeEditar(array $actor, string $contexto): bool
    {
        if (!empty($actor['super'])) return true;
        if (!PermisosUsuario::permitido($actor, $contexto, 'users', 'editar')) return false;
        $facultades = (array) ($actor['ambitos'][$contexto]['facultades'] ?? []);
        return in_array('usuarios.gestionar', $facultades, true)
            && in_array('permisos.delegar', $facultades, true);
    }

    private static function catalogo(string $contexto): array
    {
        if (!method_exists(PermisosUsuario::class, 'catalogo')) {
            return [];
        }
        $catalogo = PermisosUsuario::catalogo($contexto);
        return is_array($catalogo) ? $catalogo : [];
    }

    private static function facultadesCatalogo(string $contexto): array
    {
        return \Services\Users\AccesosUsuario::catalogoFacultades($contexto);
    }

    private static function facultadesPorAmbito(array $actor, string $contexto, array $permitidos): array
    {
        $result = [];
        foreach ($permitidos as $slug) {
            $catalogo = self::facultadesCatalogo($slug);
            if (!empty($actor['super'])) {
                $result[$slug] = $catalogo;
                continue;
            }
            $origen = $actor['ambitos'][$contexto]['facultades'] ?? [];
            $result[$slug] = array_intersect_key($catalogo, array_fill_keys((array) $origen, true));
        }
        return $result;
    }

    private static function techosPorAmbito(array $actor, string $contexto, array $permitidos): array
    {
        $result = [];
        foreach ($permitidos as $slug) {
            $origen = $actor['ambitos'][$contexto] ?? [];
            $result[$slug] = !empty($actor['super']) ? '*' : (array) ($origen['permisos'] ?? []);
        }
        return $result;
    }

    private static function datosPost(): array
    {
        $datos = [];
        foreach (['username', 'nombre', 'password', 'password_confirm', 'admin_password'] as $key) {
            if (isset($_POST[$key]) && is_string($_POST[$key])) {
                $datos[$key] = in_array($key, ['password', 'password_confirm', 'admin_password'], true)
                    ? $_POST[$key]
                    : trim($_POST[$key]);
            }
        }
        if (isset($_POST['activo'])) {
            $datos['activo'] = in_array((string) $_POST['activo'], ['1', 'true', 'on'], true);
        }
        $rawAmbitos = $_POST['ambitos'] ?? [];
        $ambitos = [];
        if (is_array($rawAmbitos)) {
            foreach ($rawAmbitos as $slug => $unidad) {
                if (!is_string($slug) || !isset(Contextos::NOMBRES[$slug]) || !is_array($unidad)) {
                    continue;
                }
                $permisos = [];
                foreach ((array) ($unidad['permisos'] ?? []) as $modulo => $nivel) {
                    if (is_string($modulo) && is_string($nivel)) {
                        $permisos[$modulo] = $nivel;
                    }
                }
                $facultades = array_values(array_filter(
                    (array) ($unidad['facultades'] ?? []),
                    static fn($facultad) => is_string($facultad)
                ));
                $ambitos[$slug] = [
                    'activo' => isset($unidad['activo']) && in_array((string) $unidad['activo'], ['1', 'true', 'on'], true),
                    'permisos' => $permisos,
                    'facultades' => $facultades,
                ];
            }
        }
        $datos['ambitos'] = $ambitos;
        return $datos;
    }

    private static function lista(array $resultado): array
    {
        if (isset($resultado['usuarios']) && is_array($resultado['usuarios'])) {
            return $resultado['usuarios'];
        }
        if (isset($resultado['usuarios']) && is_iterable($resultado['usuarios'])) {
            return iterator_to_array($resultado['usuarios']);
        }
        return [];
    }

    private static function flashAlertas(): array
    {
        $mapa = [
            'guardado' => ['exito' => ['Los accesos quedaron guardados.']],
            'estado_ok' => ['exito' => ['El estado del usuario se actualizó.']],
            'eliminar_ok' => ['exito' => ['El usuario se eliminó.']],
            'membresia_revocada' => ['exito' => ['Se revocó el acceso de esta persona a este ámbito.']],
            'nip_regenerado' => ['exito' => ['Se generó un NIP nuevo. Entrégalo ahora; no podrá consultarse después.']],
            'csrf_invalido' => ['error' => ['La sesión del formulario venció. Recarga la página e inténtalo nuevamente.']],
            'sin_permiso' => ['error' => ['No tienes facultades para administrar usuarios en este ámbito.']],
            'no_encontrado' => ['error' => ['No se encontró el usuario o no está disponible en este ámbito.']],
        ];
        $codigo = self::query('resultado');
        if (isset($mapa[$codigo])) return $mapa[$codigo];
        if ($codigo !== '') return ['error' => [self::mensajeCodigo(strtoupper($codigo))]];
        return [];
    }

    private static function errores(array $resultado): array
    {
        if ($resultado === []) return [];
        $errores = $resultado['errores'] ?? [];
        if (!is_array($errores)) {
            return ['error' => ['No fue posible guardar los cambios. Revisa los datos e inténtalo nuevamente.']];
        }
        $plano = [];
        $recoger = static function ($valor) use (&$plano, &$recoger): void {
            if (is_string($valor) && trim($valor) !== '') $plano[] = $valor;
            elseif (is_array($valor)) foreach ($valor as $hijo) $recoger($hijo);
        };
        $recoger($errores);
        if (isset($resultado['mensaje']) && is_string($resultado['mensaje'])) $plano[] = $resultado['mensaje'];
        if ($plano === [] && empty($resultado['ok'])) $plano[] = self::mensajeCodigo((string) ($resultado['codigo'] ?? ''));
        return $plano === [] ? [] : ['error' => array_values(array_unique($plano))];
    }

    private static function mensajeCodigo(string $codigo): string
    {
        $mensajes = [
            'SIN_PERMISO' => 'No tienes facultades suficientes para realizar este cambio.',
            'ADMIN_ACTIVO_REQUERIDO' => 'Debe quedar al menos un superadministrador activo.',
            'USUARIO_NO_EXISTE' => 'No se encontró el usuario o no está disponible en este ámbito.',
            'NIP_CONFIGURACION_INVALIDA' => 'No se pudo generar el NIP. Revisa la configuración de seguridad.',
            'TIPO_ACCESO_INVALIDO' => 'Esta cuenta no utiliza acceso por NIP.',
        ];
        return $mensajes[strtoupper($codigo)] ?? 'No se pudo completar la operación. Revisa los datos y vuelve a intentarlo.';
    }

    private static function codigo(array $resultado): string
    {
        $codigo = (string) ($resultado['codigo'] ?? 'error');
        return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $codigo) ?: 'error');
    }

    private static function query(string $key): string
    {
        return isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : '';
    }

    private static function id($value): ?int
    {
        if (!is_scalar($value) || !preg_match('/^[1-9][0-9]*$/', (string) $value)) {
            return null;
        }
        return (int) $value;
    }

    private static function postCsrfValido(): bool
    {
        return Auth::check() && AdminCsrfService::validar(is_string($_POST['admin_csrf'] ?? null) ? $_POST['admin_csrf'] : null);
    }

    private static function guardarNipFlash(string $nip, string $afterUrl, string $contexto, int $targetId, string $resultado): void
    {
        Auth::start();
        $_SESSION['_admin_user_nip_flash'] = [
            'nip' => $nip,
            'after_url' => $afterUrl,
            'flujo' => 'directo',
            'actor_id' => self::actorId(),
            'target_id' => $targetId,
            'created_at' => time(),
            'expected_path' => self::baseUrl($contexto) . '/edit',
            'expected_result' => $resultado,
        ];
    }

    private static function consumirNipFlash(?int $targetId, string $contexto): ?array
    {
        Auth::start();
        $flash = $_SESSION['_admin_user_nip_flash'] ?? null;
        unset($_SESSION['_admin_user_nip_flash']);
        if ($targetId === null || !is_array($flash) || !preg_match('/^\d{4}$/', (string) ($flash['nip'] ?? ''))
            || (int) ($flash['created_at'] ?? 0) < time() - 90
            || (int) ($flash['created_at'] ?? 0) > time()
            || (int) ($flash['actor_id'] ?? 0) !== self::actorId()
            || (int) ($flash['target_id'] ?? 0) !== $targetId
            || ($flash['expected_path'] ?? '') !== self::baseUrl($contexto) . '/edit'
            || (string) ($_SERVER['PATH_INFO'] ?? '') !== ($flash['expected_path'] ?? '')
            || array_keys($_GET) !== ['id', 'resultado']
            || self::id($_GET['id'] ?? null) !== $targetId
            || (string) ($_GET['resultado'] ?? '') !== (string) ($flash['expected_result'] ?? '')) {
            return null;
        }
        return $flash;
    }

    private static function noStore(): void
    {
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
    }

    private static function redirect(string $url): void
    {
        header('Location: ' . $url, true, 303);
        exit;
    }
}
