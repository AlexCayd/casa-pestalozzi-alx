<?php

namespace Classes;

use Model\Rol;
use Model\Usuario;
use Services\Security\Permisos;
use Services\Security\Contextos;
use Services\Security\PermisosUsuario;

/**
 * Sesión y protección de rutas del personal.
 *
 * Un solo formulario en /login con dos pestañas (NIP y contraseña). Qué rutas
 * puede visitar cada quien lo decide su ROL con los permisos por módulo de
 * Roles y permisos: la tabla de rutas y la decisión viven en
 * Services\Security\Permisos; aquí se carga el rol de la sesión y se aplica
 * la respuesta (dejar pasar, mandar al login, negar).
 *
 * Los roles de sistema (super_admin y admin) lo tienen todo. Mesero y
 * Cocinero son roles predefinidos, editables como cualquier otro.
 *
 * Una ruta /api/... que no esté en Permisos::APIS queda pública: al añadir un
 * endpoint del personal hay que anotarlo allí.
 */
class Auth {

    /** Rol de la sesión ya resuelto, por petición: [slug, nombre, sistema, permisos]. */
    private static ?array $rolCache = null;
    private static ?array $perfilDirecto = null;

    /** Selección exclusiva por esquema; no mezcla permisos de roles y directos. */
    public static function multinegocio(): bool {
        if (!empty($_SESSION['_auth3b'])) return true;
        $db = Usuario::getDB();
        if (!$db) return false;
        try {
            if ($db->query("SELECT version FROM esquema_versiones WHERE version='multinegocio-3b-v1'")->fetch_row()) return true;
            throw new \RuntimeException('El esquema requiere completar la autorización 3B antes de utilizarlo.');
        } catch (\mysqli_sql_exception $e) {
            if ($e->getCode() === 1146) return false;
            throw new \RuntimeException('No se pudo verificar el esquema de autenticación.');
        }
    }

    public static function refrescar(): ?array {
        self::$perfilDirecto = null;
        if (!self::check()) return null;
        try { return self::$perfilDirecto = PermisosUsuario::cargar((int)$_SESSION['id']); }
        catch (\Throwable) { return null; }
    }

    public static function perfil(): array {
        return self::$perfilDirecto ?? self::refrescar() ?? ['id'=>0,'activo'=>false,'super'=>false,'ambitos'=>[]];
    }

    public static function evaluar(string $ctx, string $modulo, string $nivel='ver', ?string $tabla=null, ?int $id=null): array {
        $perfil = self::refrescar() ?? [];
        $r = PermisosUsuario::decidir($perfil,$ctx,$modulo,$nivel,(string)($_SESSION['auth_nivel'] ?? ''));
        if ($r['ok'] && $tabla !== null && !PermisosUsuario::recurso($perfil,$ctx,$tabla,(int)$id,$nivel,$modulo)) return ['ok'=>false,'codigo'=>'RECURSO_NO_ENCONTRADO'];
        return $r;
    }

    /** Aviso que la guardia deja para la siguiente pantalla del panel. */
    public const AVISO_PERMISO = '_aviso_permiso';

    public static function start(): void {
        if (session_status() === PHP_SESSION_NONE) {
            $environment = (string)(getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? ''));
            if (in_array($environment, ['development', 'test'], true)) {
                $sessionPath = trim((string)(getenv('SESSION_SAVE_PATH') ?: ($_ENV['SESSION_SAVE_PATH'] ?? '')));
                if ($sessionPath === '') {
                    $sessionPath = sys_get_temp_dir();
                }
                if (is_dir($sessionPath) && is_writable($sessionPath)) {
                    ini_set('session.save_path', $sessionPath);
                }
            }
            $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            $params = session_get_cookie_params();
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?? '',
                'secure' => $https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function login(Usuario $usuario, string $metodo = 'contrasena'): void {
        self::start();
        if (self::multinegocio()) {
            $perfil = PermisosUsuario::cargar((int)$usuario->id);
            $tipo = PermisosUsuario::tipoAcceso($perfil);
            if (empty($perfil['activo']) || !in_array($metodo,['nip','contrasena'],true) || $tipo !== $metodo) throw new \RuntimeException('Método de autenticación no permitido para esta cuenta.');
            session_regenerate_id(true);
            $_SESSION = ['login'=>true,'id'=>(int)$usuario->id,'nombre'=>$perfil['nombre'],'username'=>$perfil['username'],'auth_nivel'=>$metodo,'_auth3b'=>true];
            self::$perfilDirecto=$perfil;
            self::$rolCache=null;
            return;
        }
        // Evitar fijación de sesión: id nuevo al autenticarse
        session_regenerate_id(true);
        self::$rolCache = null;
        $_SESSION['login']    = true;
        $_SESSION['id']       = (int) $usuario->id;
        $_SESSION['nombre']   = $usuario->nombre;
        $_SESSION['username'] = $usuario->username;
        $_SESSION['rol']      = $usuario->rol;
        self::cargarUnidad($usuario->unidad_id);
    }

    /**
     * Bandera de «recién entró»: la pone el login al autenticar y la consume la
     * primera página que se pinta después (layout del panel o shell del piso),
     * que es la única que lleva la animación de llegada. De un solo uso, así
     * que recargar o cambiar de módulo ya no la repite.
     */
    private const LLEGADA = '_llegada_pendiente';

    public static function anunciarLlegada(): void {
        self::start();
        $_SESSION[self::LLEGADA] = true;
    }

    /**
     * Devuelve true una sola vez por inicio de sesión.
     *
     * Una precarga de Speculation Rules (`Sec-Purpose: prefetch`) NO la
     * consume: esa respuesta puede no llegar a pintarse nunca, y se llevaría la
     * llegada de la navegación real.
     */
    public static function consumirLlegada(): bool {
        if (!self::check()) {
            return false;
        }
        $proposito = strtolower((string) ($_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? ''));
        if (str_contains($proposito, 'prefetch')) {
            return false;
        }
        if (empty($_SESSION[self::LLEGADA])) {
            return false;
        }
        unset($_SESSION[self::LLEGADA]);
        return true;
    }

    public static function logout(): void {
        self::$perfilDirecto = null;
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool)($params['secure'] ?? false),
                'httponly' => (bool)($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    public static function check(): bool {
        self::start();
        return !empty($_SESSION['login']) && !empty($_SESSION['id']);
    }

    public static function rol(): string {
        if (self::multinegocio()) return !empty(self::perfil()['super']) ? 'super_admin' : 'usuario_directo';
        self::start();
        return (string) ($_SESSION['rol'] ?? '');
    }

    /**
     * El rol de la sesión con sus permisos, resuelto una vez por petición.
     *
     * Lee $_SESSION y no exige check(): las vistas lo consultan y la guardia
     * ya validó la sesión antes. Los de sistema no tocan la base (lo tienen
     * todo); los demás se leen de roles/rol_permisos y, si la base no
     * responde, Mesero y Cocinero caen a sus permisos de siempre en vez de
     * quedarse sin nada a media jornada.
     *
     * @return array{slug: string, nombre: string, sistema: bool, permisos: array<string, string>}
     */
    public static function rolActual(): array {
        if (self::multinegocio()) {
            $p=self::perfil(); $ctx=Contextos::deRuta($_SERVER['PATH_INFO'] ?? '/admin');
            return ['slug'=>!empty($p['super'])?'super_admin':'usuario_directo','nombre'=>!empty($p['super'])?'Superadministrador':'Permisos directos','sistema'=>!empty($p['super']),'permisos'=>$p['ambitos'][$ctx]['permisos'] ?? []];
        }
        $slug = (string) ($_SESSION['rol'] ?? '');
        if (self::$rolCache !== null && self::$rolCache['slug'] === $slug) {
            return self::$rolCache;
        }

        return self::$rolCache = self::resolverRol($slug);
    }

    /** Lo mismo que rolActual() para un slug cualquiera (destinos, formularios). */
    public static function resolverRol(string $slug): array {
        if ($slug === '') {
            return ['slug' => '', 'nombre' => '', 'sistema' => false, 'permisos' => []];
        }

        if (Permisos::esSistema($slug)) {
            $nombre = Rol::NOMBRES_POR_DEFECTO[$slug] ?? $slug;
            try {
                $guardado = Rol::getDB() ? Rol::porSlug($slug) : null;
                if ($guardado) {
                    $nombre = (string) $guardado->nombre;
                }
            } catch (\Throwable $e) {
                // Sin base, el nombre de respaldo.
            }
            return ['slug' => $slug, 'nombre' => $nombre, 'sistema' => true, 'permisos' => Permisos::todos()];
        }

        try {
            $rol = Rol::getDB() ? Rol::porSlug($slug) : null;
            if ($rol) {
                return [
                    'slug' => $slug,
                    'nombre' => (string) $rol->nombre,
                    'sistema' => $rol->esSistema(),
                    'permisos' => $rol->permisos(),
                ];
            }
        } catch (\Throwable $e) {
            error_log('Auth::resolverRol - ' . $e->getMessage());
        }

        return [
            'slug' => $slug,
            'nombre' => Rol::NOMBRES_POR_DEFECTO[$slug] ?? $slug,
            'sistema' => false,
            'permisos' => Rol::PERMISOS_POR_DEFECTO[$slug] ?? [],
        ];
    }

    /** Super admin o administrador: los roles de sistema, que lo tienen todo. */
    public static function esAdmin(): bool {
        if (self::multinegocio()) return self::esSuperAdmin();
        return self::check() && Permisos::esSistema(self::rol());
    }

    public static function esSuperAdmin(): bool {
        if (self::multinegocio()) return self::check() && !empty(self::perfil()['activo']) && !empty(self::perfil()['super']);
        return self::check() && self::rol() === Permisos::SUPER_ADMIN;
    }

    /** El rol predefinido Mesero. Las listas de meseros cuentan sólo éste. */
    public static function esMesero(): bool {
        if (self::multinegocio()) return self::puede('pdv','operar','restaurante');
        return self::check() && self::rol() === Permisos::MESERO;
    }

    public static function esCocinero(): bool {
        if (self::multinegocio()) return self::puede('area','operar','restaurante');
        return self::check() && self::rol() === Permisos::COCINERO;
    }

    /** ¿El rol de la sesión llega a $nivel en $modulo? */
    public static function puede(string $modulo, string $nivel = Permisos::VER, ?string $contexto=null): bool {
        if (self::multinegocio()) {
            $ctx=$contexto ?? Contextos::deRuta($_SERVER['PATH_INFO'] ?? '/admin');
            return PermisosUsuario::decidir(self::perfil(),$ctx,$modulo,$nivel,(string)($_SESSION['auth_nivel'] ?? ''))['ok'];
        }
        $rol = self::rolActual();
        if ($rol['sistema']) {
            return true;
        }
        $tiene = $rol['permisos'][$modulo] ?? null;

        return $tiene !== null && Permisos::alcanza($tiene, $nivel);
    }

    /** ¿Entra al panel (algún módulo que no sea de piso)? */
    public static function tienePanel(): bool {
        if (self::multinegocio()) return PermisosUsuario::tipoAcceso(self::perfil()) === 'contrasena';
        $rol = self::rolActual();

        return $rol['sistema'] || Permisos::tienePanel($rol['permisos']);
    }

    public static function nombreRol(?string $slug = null): string {
        return $slug === null ? self::rolActual()['nombre'] : self::resolverRol($slug)['nombre'];
    }

    /**
     * Módulos a los que entra un rol, en lenguaje humano, para el formulario
     * de usuarios. Sale de los mismos permisos que aplica la guardia.
     *
     * @return array<int, array{titulo: string, detalle: string}>
     */
    public static function areasPorRol(string $rol): array {
        $resuelto = self::resolverRol($rol);
        $areas = [];
        foreach (Permisos::MODULOS as $clave => $modulo) {
            $nivel = $resuelto['permisos'][$clave] ?? null;
            if ($nivel === null) {
                continue;
            }
            $areas[] = [
                'titulo' => $modulo['titulo'] . ($nivel === Permisos::VER ? ' (sólo consulta)' : ''),
                'detalle' => $modulo['detalle'],
            ];
        }

        return $areas;
    }

    /** Vista inicial que corresponde a un rol tras iniciar sesión. */
    public static function destinoPorRol(?string $rol = null): string {
        if (self::multinegocio()) {
            foreach (self::contextos() as $ctx) if (self::puede('users','ver',$ctx)) return $ctx==='restaurante'?'/admin/usuarios':'/admin/'.$ctx.'/usuarios';
            foreach (self::contextos() as $ctx) foreach (PermisosUsuario::catalogo($ctx) as $mod=>$def) if(self::puede($mod,'ver',$ctx)) return $def['operativo'] ? $def['ruta'] : ($ctx==='restaurante'?'/admin':'/admin/'.$ctx);
            return '/login?resultado=sin_acceso';
        }
        $resuelto = $rol === null ? self::rolActual() : self::resolverRol($rol);

        $contextos = self::contextos();
        if ($contextos === []) {
            return '/login?resultado=sin_contexto';
        }
        if (!in_array('restaurante', $contextos, true) || $resuelto['slug'] === Permisos::SUPER_ADMIN) {
            return Contextos::inicio($contextos[0]);
        }

        return Permisos::destino($resuelto['sistema'], $resuelto['permisos']);
    }

    /**
     * Guardia central de rutas: se llama antes de resolver la ruta.
     *
     * Permisos::decidir() dice qué toca; aquí se responde. Sin sesión, al
     * login (o 401 JSON). Sin el módulo, a la vista de trabajo del rol (o 403
     * JSON): no hay página de error, la persona aterriza donde sí puede
     * operar. Con el módulo en «ver» y una petición que escribe, el formulario
     * vuelve a la pantalla de donde salió con el aviso de sólo consulta.
     */
    public static function proteger(string $url): void {
        if (!Usuario::getDB()) self::negarJson(503,'No fue posible verificar el acceso.');
        try { $directo=self::multinegocio(); }
        catch (\Throwable) { self::negarJson(503,'No fue posible verificar el acceso.'); }
        if ($directo) { self::protegerDirecto($url); return; }
        if (Permisos::clasificar($url)['tipo'] === 'publica') {
            return;
        }

        $metodo = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $esApi = str_starts_with($url, '/api/') || str_starts_with($url, '/admin/api/');
        $rol = null;
        if (self::check() && self::refrescarSesion()) {
            $actual = self::rolActual();
            $rol = ['sistema' => $actual['sistema'], 'permisos' => $actual['permisos']];
        }

        $decision = Permisos::decidir($url, $metodo, $rol);

        // Se aplica también a roles de sistema y a TODAS las APIs protegidas.
        if ($rol !== null && !Contextos::permite($url, self::rol(), $_SESSION['unidad_slug'] ?? null)) {
            if ($esApi) {
                self::negarJson(403, 'No tienes acceso a este contexto de trabajo.');
            }
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            $destinoContexto = self::destinoPorRol();
            require __DIR__ . '/../views/auth/contexto-denegado.php';
            exit;
        }

        if ($decision['resultado'] === 'libre' || $decision['resultado'] === 'permitir') {
            return;
        }

        if ($decision['resultado'] === 'login') {
            if ($esApi) {
                self::negarJson(401, 'Sesión expirada. Vuelve a iniciar sesión.');
            }
            header('Location: /login');
            exit;
        }

        $soloLectura = $decision['motivo'] === 'solo_lectura';
        $mensaje = $soloLectura
            ? 'Tu rol sólo puede consultar este módulo.'
            : 'No tienes permisos para esta acción.';

        if ($esApi) {
            self::negarJson(403, $mensaje);
        }

        $destino = self::destinoPorRol();
        if ($destino === '/login') {
            // Un rol que se quedó sin módulos: con la sesión abierta, el login
            // lo mandaría de vuelta aquí en bucle.
            self::logout();
            header('Location: /login?resultado=sin_acceso');
            exit;
        }

        if ($soloLectura && strtoupper($metodo) !== 'GET') {
            // Un formulario del panel: vuelve a la pantalla de donde salió,
            // con el aviso. Sólo a una ruta propia, nunca a un Referer ajeno.
            $_SESSION[self::AVISO_PERMISO] = $mensaje;
            $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
            $volver = parse_url($referer, PHP_URL_PATH);
            $consulta = parse_url($referer, PHP_URL_QUERY);
            if (is_string($volver) && str_starts_with($volver, '/') && !str_starts_with($volver, '//')) {
                $destino = $volver . (is_string($consulta) && $consulta !== '' ? '?' . $consulta : '');
            }
        }

        header('Location: ' . $destino);
        exit;
    }

    /** El aviso que dejó la guardia, una sola vez. */
    public static function consumirAvisoPermiso(): ?string {
        self::start();
        $aviso = $_SESSION[self::AVISO_PERMISO] ?? null;
        unset($_SESSION[self::AVISO_PERMISO]);

        return is_string($aviso) && $aviso !== '' ? $aviso : null;
    }

    /**
     * Vuelve a leer el rol y el estado de la cuenta en cada petición protegida:
     * un cambio de rol o de permisos, o una desactivación, valen desde la
     * siguiente petición y no desde el siguiente login. Si la cuenta ya no
     * existe o está inactiva, se cierra la sesión.
     *
     * Si la base no responde se conserva la sesión, pero la unidad queda sin
     * verificar y no autoriza operaciones de negocio.
     */
    private static function refrescarSesion(): bool {
        // Sin revalidación de la unidad no se reutiliza una asignación antigua.
        $_SESSION['unidad_slug'] = 'invalida';
        try {
            $db = Usuario::getDB();
            if (!$db) {
                return true;
            }
            $stmt = $db->prepare('SELECT u.rol, u.activo, u.unidad_id, n.slug AS unidad_slug FROM usuarios u LEFT JOIN unidades n ON n.id = u.unidad_id WHERE u.id = ? LIMIT 1');
            if (!$stmt) {
                return true;
            }
            $id = (int) ($_SESSION['id'] ?? 0);
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) {
                $stmt->close();
                return true;
            }
            $fila = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('Auth::refrescarSesion - ' . $e->getMessage());
            return true;
        }

        if (!$fila || (int) $fila['activo'] !== 1) {
            self::logout();
            return false;
        }

        if ((string) $fila['rol'] !== (string) ($_SESSION['rol'] ?? '')) {
            $_SESSION['rol'] = (string) $fila['rol'];
            self::$rolCache = null;
        }

        $_SESSION['unidad_slug'] = $fila['unidad_id'] === null ? null : ($fila['unidad_slug'] ?? 'invalida');

        return true;
    }

    public static function contextos(): array {
        if (self::multinegocio()) return !empty(self::perfil()['activo']) ? array_keys(self::perfil()['ambitos']) : [];
        $contextos = Contextos::permitidos(self::rol(), $_SESSION['unidad_slug'] ?? null);
        // El piso de Panadería aún no existe: una asignación futura no abre el panel.
        if (!self::tienePanel()) {
            return array_values(array_intersect($contextos, ['restaurante']));
        }
        return $contextos;
    }

    private static function cargarUnidad($id): void {
        $_SESSION['unidad_slug'] = $id === null ? null : 'invalida';
        if ($id !== null) {
            try {
                $unidad = \Model\Unidad::find((int) $id);
                $_SESSION['unidad_slug'] = $unidad ? (string) $unidad->slug : 'invalida';
            } catch (\Throwable $e) {
                error_log('Auth::cargarUnidad - no se pudo resolver la unidad');
            }
        }
    }

    private static function protegerDirecto(string $url): void {
        $ruta=Permisos::clasificarDirecto($url);
        if ($ruta['tipo']==='publica') return;
        $p=self::refrescar();
        if (!$p || empty($p['activo'])) { self::negarDirecto(401,'NO_AUTORIZADO','Vuelve a iniciar sesión.'); }
        if (!in_array($_SESSION['auth_nivel']??'',['nip','contrasena'],true)) self::negarDirecto(401,'NO_AUTORIZADO','Vuelve a iniciar sesión.');
        if (PermisosUsuario::tipoAcceso($p)==='contrasena' && ($_SESSION['auth_nivel']??'')!=='contrasena') { self::negarDirecto(401,'NIVEL_AUTH_REQUERIDO','Tus permisos requieren iniciar sesión con contraseña.'); }
        // El selector no pertenece a Restaurante: el controlador valida el destino.
        if ($url==='/admin/contexto' && ($_SESSION['auth_nivel']??'')==='contrasena') return;
        $ctx=Contextos::deRuta($url);
        if (!isset($p['ambitos'][$ctx])) self::negarDirecto(403,'AMBITO_DENEGADO','No tienes acceso a este ámbito.');
        if ($ruta['tipo']==='compartida') {
            if (($_SESSION['auth_nivel']??'')!=='contrasena') self::negarDirecto(403,'PERMISO_DENEGADO','Tu acceso operativo no permite abrir el panel administrativo.');
            if (($p['ambitos'][$ctx]['permisos']??[])===[]) self::negarDirecto(403,'PERMISO_DENEGADO','No tienes módulos autorizados.');
        } else {
            $permitido=false;
            foreach ($ruta['modulos'] as $mod) if (PermisosUsuario::permitido($p,$ctx,$mod,Permisos::nivelRequerido($_SERVER['REQUEST_METHOD']??'GET'))) $permitido=true;
            if (!$permitido) self::negarDirecto(403,'PERMISO_DENEGADO','No tienes permiso para esta operación.');
        }
        // No ejecutar consultas mononegocio contra el esquema nuevo. Usuarios y
        // navegación son la entrega habilitada; los demás módulos esperan adaptación.
        $usuarios=preg_match('~^/admin/(?:global/|panaderia/)?usuarios(?:/|$)~',$url)===1;
        $navegacion=in_array($url,['/admin','/admin/global','/admin/panaderia','/admin/contexto'],true);
        if (!$usuarios && !$navegacion) self::negarDirecto(503,'MODULO_PENDIENTE','Este módulo espera su adaptación al esquema multinegocio.');
    }

    private static function negarDirecto(int $status,string $codigo,string $mensaje): never {
        http_response_code($status);
        $url=$_SERVER['PATH_INFO']??'';
        if (str_contains($url,'/api/')) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'codigo'=>$codigo,'mensaje'=>$mensaje],JSON_UNESCAPED_UNICODE); }
        else {
            header('Content-Type: text/html; charset=utf-8');
            $mensajeAcceso=$mensaje;
            $tituloAcceso=$status===503?'Módulo pendiente':'Acceso denegado';
            $destinoContexto=$status===401?'/login?tab=admin&resultado=reauth':self::destinoPorRol();
            // Un módulo pendiente puede ser el destino operativo de la cuenta.
            if ($status===503) $destinoContexto='/login?tab=admin';
            $textoDestino=$status===401||$status===503?'Iniciar sesión':'Volver a mi espacio';
            require __DIR__.'/../views/auth/contexto-denegado.php';
        }
        exit;
    }

    /**
     * Libera el candado del archivo de sesión sin perder $_SESSION en memoria.
     *
     * PHP mantiene un lock exclusivo desde session_start() hasta el final del
     * script, así que dos peticiones del mismo navegador se atienden en serie.
     * En el POS eso hacía que /api/ticket-items esperara a que /api/sugerencias
     * terminara su llamada a n8n. Los endpoints de solo lectura llaman a esto
     * justo después de la guardia de Auth para que se atiendan en paralelo.
     *
     * No usar en endpoints que escriban en $_SESSION: los cambios posteriores
     * ya no se persisten.
     */
    public static function liberarSesion(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private static function negarJson(int $codigo, string $msg): void {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'codigo' => $codigo === 401 ? 'NO_AUTORIZADO' : 'PERMISO_DENEGADO',
            'mensaje' => $msg,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
