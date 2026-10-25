<?php

namespace Services\Security;

/**
 * Catálogo de permisos y decisión de acceso por rol.
 *
 * Un rol tiene, por cada MÓDULO del sistema, uno de tres estados: sin acceso,
 * `ver` o `editar`. Los módulos son los del menú del panel más las dos
 * pantallas de piso (Punto de venta y Producción), que comparten permiso con
 * su entrada del panel: es la misma operación vista desde una tablet.
 *
 * El nivel que exige cada petición sale del MÉTODO, no de una lista a mano:
 * GET y HEAD son consulta (`ver`); POST, DELETE y cualquier otro son cambio
 * (`editar`). Así una ruta nueva hereda la regla sola. La excepción son las
 * rutas COMPARTIDAS (buzón, reportar un problema): son del cromo del panel, no
 * de un módulo, y las usa cualquiera que entre al panel.
 *
 * Las dos funciones que deciden —decidir() y acceso()— son puras: reciben los
 * permisos ya cargados y no tocan la base ni la sesión. Classes\Auth las
 * alimenta en cada petición y run-roles-permisos.php las prueba sin servidor.
 *
 * Una ruta /admin/* que no esté en RUTAS queda reservada a los roles de
 * SISTEMA (super admin y administrador): un módulo nuevo sin mapear no se
 * abre por descuido a un rol personalizado. El test de contrato lista las
 * rutas del router que caen en ese hueco.
 */
final class Permisos
{
    public const VER = 'ver';
    public const EDITAR = 'editar';
    public const NIVELES = [self::VER, self::EDITAR];

    /** Roles de sistema: todo en `editar` y no se pueden editar ni borrar. */
    public const SUPER_ADMIN = 'super_admin';
    public const ADMIN = 'admin';
    public const ROLES_SISTEMA = [self::SUPER_ADMIN, self::ADMIN];

    /** Roles predefinidos: se editan sus permisos, no se borran. */
    public const MESERO = 'waiter';
    public const COCINERO = 'cook';

    public const ACCESO_CONTRASENA = 'contrasena';
    public const ACCESO_NIP = 'nip';
    public const ACCESO_NINGUNO = 'ninguno';

    public const GRUPO_PANEL = 'panel';
    public const GRUPO_PISO = 'piso';

    /**
     * Los módulos, en el orden del menú lateral. La clave es la misma que
     * AdminController::MODULES (de ahí 'productos' para Recetas, 'pdv' y
     * 'area'): es lo que se guarda en rol_permisos.modulo, así que NO se
     * renombra una clave sin migrar la tabla.
     *
     * `grupo` decide cómo entra un rol: con algún módulo del panel, usuario y
     * contraseña; sólo con módulos de piso, NIP (ver acceso()).
     */
    public const MODULOS = [
        'analytics' => [
            'titulo' => 'Análisis de datos',
            'detalle' => 'Indicadores de venta, ocupación y desempeño.',
            'grupo' => self::GRUPO_PANEL,
            'ruta' => '/admin/analytics',
        ],
        'ia' => [
            'titulo' => 'Asistente IA',
            'detalle' => 'Preguntas en lenguaje natural sobre los datos.',
            'grupo' => self::GRUPO_PANEL,
            'ruta' => '/admin/ia',
        ],
        'menu' => [
            'titulo' => 'Menú',
            'detalle' => 'Platillos, categorías, fotos, variantes y PDF de la carta.',
            'grupo' => self::GRUPO_PANEL,
            'ruta' => '/admin/menu',
        ],
        'productos' => [
            'titulo' => 'Recetas',
            'detalle' => 'Recetas y subrecetas de cada platillo.',
            'grupo' => self::GRUPO_PANEL,
            'ruta' => '/admin/recetas',
        ],
        'inventario' => [
            'titulo' => 'Inventario',
            'detalle' => 'Ingredientes, existencias, movimientos y proveedores.',
            'grupo' => self::GRUPO_PANEL,
            'ruta' => '/admin/inventario',
        ],
        'finanzas' => [
            'titulo' => 'Finanzas',
            'detalle' => 'Cortes, gastos fijos y estado de resultados.',
            'grupo' => self::GRUPO_PANEL,
            'ruta' => '/admin/finanzas',
        ],
        'pdv' => [
            'titulo' => 'Punto de venta',
            'detalle' => 'Mapa de mesas, tickets, comandas, cobro y reservas del día.',
            'grupo' => self::GRUPO_PISO,
            'ruta' => '/punto-de-venta',
        ],
        'area' => [
            'titulo' => 'Producción',
            'detalle' => 'Tableros de cocina, horno y barras.',
            'grupo' => self::GRUPO_PISO,
            'ruta' => '/area',
        ],
        'reservations' => [
            'titulo' => 'Reservaciones',
            'detalle' => 'Agenda, detalle y estrategia de mesas. En el punto de venta, comentar reservas y ver el contacto del cliente.',
            'grupo' => self::GRUPO_PANEL,
            'ruta' => '/admin/reservaciones',
        ],
        'catas' => [
            'titulo' => 'Catas',
            'detalle' => 'Agenda de catas de la landing.',
            'grupo' => self::GRUPO_PANEL,
            'ruta' => '/admin/catas',
        ],
        'feedback' => [
            'titulo' => 'Feedback de clientes',
            'detalle' => 'Opiniones de los comensales.',
            'grupo' => self::GRUPO_PANEL,
            'ruta' => '/admin/feedback',
        ],
        'tickets' => [
            'titulo' => 'Tickets',
            'detalle' => 'Historial de tickets cerrados.',
            'grupo' => self::GRUPO_PANEL,
            'ruta' => '/admin/tickets',
        ],
        'printers' => [
            'titulo' => 'Estaciones de impresión',
            'detalle' => 'Impresoras de comandas y cuentas. En el punto de venta, el detalle técnico de una impresión fallida.',
            'grupo' => self::GRUPO_PANEL,
            'ruta' => '/admin/printers',
        ],
        'users' => [
            'titulo' => 'Usuarios',
            'detalle' => 'Cuentas del personal, NIP y contraseñas.',
            'grupo' => self::GRUPO_PANEL,
            'ruta' => '/admin/usuarios',
        ],
        'configuration' => [
            'titulo' => 'Configuración',
            'detalle' => 'Horarios, anuncio, paleta, reservaciones e impresión.',
            'grupo' => self::GRUPO_PANEL,
            'ruta' => '/admin/configuracion',
        ],
        'roles' => [
            'titulo' => 'Roles y permisos',
            'detalle' => 'Roles del sistema y a qué módulos entra cada uno.',
            'grupo' => self::GRUPO_PANEL,
            'ruta' => '/admin/roles',
        ],
    ];

    /**
     * Rutas de cualquier usuario del panel, sin importar sus módulos: el
     * inicio (que redirige al primer módulo permitido), el buzón de avisos del
     * topbar y «Reportar un problema». Prefijos.
     */
    private const COMPARTIDAS = [
        '/admin',            // sólo la exacta: ver modulosDeRuta()
        '/admin/api/buzon',
        '/admin/api/reportes',
    ];

    /**
     * Prefijo → módulos que la abren (basta uno). El orden importa: gana el
     * PRIMER prefijo que casa, así que los más específicos van antes.
     * Un prefijo casa con la ruta exacta y con todo lo que cuelga de `prefijo/`.
     */
    private const RUTAS = [
        '/admin/panaderia/menu' => ['menu'],
        '/admin/panaderia/recetas' => ['productos'],
        '/admin/panaderia/inventario' => ['inventario'],
        '/admin/global' => ['configuration'],
        // Las direcciones de la antigua Operación de reservaciones redirigen
        // al POS: se abren con el permiso del POS, como hasta ahora.
        '/admin/reservaciones/operacion' => ['pdv'],
        '/admin/reservations/operation' => ['pdv'],

        '/admin/analytics' => ['analytics'],
        '/admin/api/analytics' => ['analytics'],
        '/admin/ia' => ['ia'],
        '/admin/menu' => ['menu'],
        '/admin/api/menu' => ['menu'],
        '/admin/recetas' => ['productos'],
        '/admin/productos' => ['productos'],
        '/admin/inventario' => ['inventario'],
        '/admin/api/inventario' => ['inventario'],
        // El histórico de precios lo abren el formulario del platillo, el del
        // ingrediente y el de la receta: cualquiera de los tres basta.
        '/admin/api/historial-precios' => ['inventario', 'menu', 'productos'],
        // Las consultas rápidas viven en cuatro pantallas y en el asistente.
        '/admin/api/consultas' => ['analytics', 'ia', 'menu', 'inventario', 'tickets'],
        '/admin/finanzas' => ['finanzas'],
        '/admin/punto-de-venta' => ['pdv'],
        '/admin/area' => ['area'],
        '/admin/api/area-items' => ['area'],
        '/admin/api/advance-item' => ['area'],
        '/admin/api/rollback-item' => ['area'],
        '/admin/reservaciones' => ['reservations'],
        '/admin/reservations' => ['reservations'],
        '/admin/api/reservaciones' => ['reservations'],
        '/admin/catas' => ['catas'],
        '/admin/feedback' => ['feedback'],
        '/admin/api/feedback-areas' => ['feedback'],
        '/admin/tickets' => ['tickets'],
        '/admin/printers' => ['printers'],
        '/admin/usuarios' => ['users'],
        '/admin/configuracion' => ['configuration'],
        '/admin/api/horarios-impactos' => ['configuration'],
        '/admin/roles' => ['roles'],

        // Piso.
        '/punto-de-venta' => ['pdv'],
        '/area' => ['area'],
    ];

    /**
     * APIs fuera de /admin/, por ruta EXACTA. Una /api/... que no esté aquí
     * queda pública: al añadir un endpoint del personal hay que anotarlo.
     * (Antes eran las listas APIS_POS / APIS_AREA / APIS_PISO / APIS_ADMIN de
     * Classes\Auth; se movieron aquí para que la ruta y su módulo vivan juntos.)
     */
    private const APIS = [
        // Punto de venta.
        '/api/punto-de-venta' => ['pdv'],
        '/api/abrir-ticket' => ['pdv'],
        '/api/cerrar-ticket' => ['pdv'],
        '/api/cancelar-mesa' => ['pdv'],
        '/api/enviar-comanda' => ['pdv'],
        '/api/entregar-item' => ['pdv'],
        '/api/actualizar-ticket' => ['pdv'],
        '/api/cancelar-item' => ['pdv'],
        '/api/punto-de-venta/reservaciones' => ['pdv'],
        '/api/punto-de-venta/mesa-contexto' => ['pdv'],
        '/api/punto-de-venta/reservaciones/comenzar' => ['pdv'],
        '/api/punto-de-venta/reservaciones/cancelar' => ['pdv'],
        '/api/punto-de-venta/reservaciones/no-show' => ['pdv'],
        '/api/punto-de-venta/reservaciones/disponibilidad' => ['pdv'],
        '/api/punto-de-venta/reservaciones/crear' => ['pdv'],
        '/api/punto-de-venta/reservaciones/asignar-mesas' => ['pdv'],
        '/api/punto-de-venta/reservaciones/reasignar' => ['pdv'],
        '/api/punto-de-venta/reservaciones/comentario' => ['pdv'],
        '/api/punto-de-venta/reservaciones/reubicaciones/revisar' => ['pdv'],
        '/api/corte-caja' => ['pdv'],
        '/api/sugerencias' => ['pdv'],
        '/api/impresion/alertas/atender' => ['pdv'],
        // Tableros de producción.
        '/api/area-items' => ['area'],
        '/api/avanzar-item' => ['area'],
        '/api/retroceder-item' => ['area'],
        // Lecturas que necesitan las dos pantallas de piso.
        '/api/productos' => ['pdv', 'area'],
        '/api/ticket-items' => ['pdv', 'area'],
        // Escrituras de horario que viven fuera de /admin/.
        '/api/configuracion/horarios/semanales' => ['configuration'],
        '/api/configuracion/horarios/especiales' => ['configuration'],
        '/api/configuracion/horarios/excepciones' => ['configuration'],
        '/api/configuracion/horarios/excepciones/estado' => ['configuration'],
    ];

    /** Pública aunque viva bajo /admin: el formulario de contraseña. */
    private const PUBLICAS = ['/admin/login'];

    // ── Clasificación de rutas ────────────────────────────────────

    /**
     * Qué protege una ruta.
     *
     * @return array{tipo: 'publica'|'compartida'|'modulos'|'solo_sistema', modulos: list<string>}
     */
    public static function clasificar(string $url): array
    {
        if (in_array($url, self::PUBLICAS, true)) {
            return ['tipo' => 'publica', 'modulos' => []];
        }

        if (isset(self::APIS[$url])) {
            return ['tipo' => 'modulos', 'modulos' => self::APIS[$url]];
        }

        // /admin a secas es compartida; los demás prefijos compartidos, con
        // todo lo que cuelga de ellos.
        if (in_array($url, ['/admin', '/admin/contexto', '/admin/panaderia'], true)) {
            return ['tipo' => 'compartida', 'modulos' => []];
        }
        foreach (self::COMPARTIDAS as $prefijo) {
            if ($prefijo !== '/admin' && self::casa($url, $prefijo)) {
                return ['tipo' => 'compartida', 'modulos' => []];
            }
        }

        foreach (self::RUTAS as $prefijo => $modulos) {
            if (self::casa($url, $prefijo)) {
                return ['tipo' => 'modulos', 'modulos' => $modulos];
            }
        }

        if (self::casa($url, '/admin')) {
            return ['tipo' => 'solo_sistema', 'modulos' => []];
        }

        return ['tipo' => 'publica', 'modulos' => []];
    }

    public static function nivelRequerido(string $metodo): string
    {
        return in_array(strtoupper($metodo), ['GET', 'HEAD'], true) ? self::VER : self::EDITAR;
    }

    /** Catálogo de rutas 3B; no reutiliza una excepción de rol para conceder acceso. */
    public static function clasificarDirecto(string $url): array
    {
        if (in_array($url,['/login','/admin/login','/logout'],true)) return ['tipo'=>'publica','modulos'=>[]];
        $normal=preg_replace('~^/admin/(global|panaderia)(?=/|$)~','/admin',$url);
        if (in_array($normal,['/admin','/admin/contexto'],true)) return ['tipo'=>'compartida','modulos'=>[]];
        if (self::casa($normal,'/admin/almacenes') || in_array($url,['/admin/global/catalogo','/admin/global/inventario','/admin/panaderia/inventario'],true)) return ['tipo'=>'modulos','modulos'=>['inventario']];
        if (self::casa($normal, '/admin/api/buzon')) return ['tipo'=>'modulos','modulos'=>['reservations']];
        if (preg_match('~^/admin/usuarios(?:/|$)~',$normal)) return ['tipo'=>'modulos','modulos'=>['users']];
        if (preg_match('~^/admin/roles(?:/|$)~',$normal)) return ['tipo'=>'solo_sistema','modulos'=>[]];
        if (in_array($normal,['/admin/reservaciones/operacion','/admin/reservations/operation'],true)) return ['tipo'=>'modulos','modulos'=>['reservations_map']];
        if (str_starts_with($url,'/api/punto-de-venta/reservaciones')) return ['tipo'=>'modulos','modulos'=>['pdv','reservations_map']];
        if (self::casa($normal,'/admin/area') || in_array($normal,['/admin/api/area-items','/admin/api/advance-item','/admin/api/rollback-item'],true)) return ['tipo'=>'modulos','modulos'=>['area']];
        if (self::casa($normal,'/admin/punto-de-venta')) return ['tipo'=>'modulos','modulos'=>['configuration']];
        $clasificada=self::clasificar($normal);
        if ($clasificada['tipo']==='publica' && str_starts_with($url,'/api/')) {
            // Las APIs públicas actuales conservan sus guardias de token/integración.
            $publicas=['/api/reservaciones/horarios','/api/reservaciones/disponibilidad','/api/reservaciones/retencion','/api/reservaciones/crear','/api/reservaciones/modificar','/api/reservaciones/confirmar-modificacion','/api/reservaciones/cancelar','/api/reservaciones/contacto/codigo','/api/reservaciones/contacto/estado','/api/reservaciones/contacto/verificar','/api/reservaciones/mis-reservaciones','/api/reservaciones/contacto/logout','/api/reservaciones/cambio-horario/disponibilidad','/api/reservaciones/cambio-horario/modificar','/api/reservaciones/cambio-horario/cancelar','/api/reservaciones/gestionar/disponibilidad','/api/reservaciones/gestionar/modificar','/api/reservaciones/gestionar/cancelar','/api/integraciones/n8n/reservaciones/recordatorios/preparar','/api/integraciones/n8n/reservaciones/recordatorios/reclamar','/api/integraciones/n8n/reservaciones/notificacion-resultado','/api/feedback','/api/feedback-n8n'];
            if (in_array($url,$publicas,true)) return $clasificada;
            return ['tipo'=>'solo_sistema','modulos'=>[]];
        }
        return $clasificada;
    }

    /**
     * La decisión de la guardia central.
     *
     * @param array{sistema: bool, permisos: array<string, string>}|null $rol
     *        null cuando no hay sesión
     * @return array{resultado: 'libre'|'login'|'permitir'|'negar', motivo: string}
     *         motivo: 'sin_modulo' (no tiene el módulo) o 'solo_lectura'
     *         (lo tiene en `ver` y la petición escribe)
     */
    public static function decidir(string $url, string $metodo, ?array $rol): array
    {
        $ruta = self::clasificar($url);

        if ($ruta['tipo'] === 'publica') {
            return ['resultado' => 'libre', 'motivo' => ''];
        }
        if ($rol === null) {
            return ['resultado' => 'login', 'motivo' => ''];
        }
        if (!empty($rol['sistema'])) {
            return ['resultado' => 'permitir', 'motivo' => ''];
        }

        $permisos = self::normalizar($rol['permisos'] ?? []);

        if ($ruta['tipo'] === 'compartida') {
            return self::tienePanel($permisos)
                ? ['resultado' => 'permitir', 'motivo' => '']
                : ['resultado' => 'negar', 'motivo' => 'sin_modulo'];
        }

        if ($ruta['tipo'] === 'solo_sistema') {
            return ['resultado' => 'negar', 'motivo' => 'sin_modulo'];
        }

        $requerido = self::nivelRequerido($metodo);
        $alcanza = false;
        $loTiene = false;
        foreach ($ruta['modulos'] as $modulo) {
            if (!isset($permisos[$modulo])) {
                continue;
            }
            $loTiene = true;
            if (self::alcanza($permisos[$modulo], $requerido)) {
                $alcanza = true;
                break;
            }
        }

        if ($alcanza) {
            return ['resultado' => 'permitir', 'motivo' => ''];
        }

        return ['resultado' => 'negar', 'motivo' => $loTiene ? 'solo_lectura' : 'sin_modulo'];
    }

    // ── Reglas de rol ─────────────────────────────────────────────

    /**
     * Cómo entra un rol, deducido de sus permisos: con algún módulo del panel,
     * usuario y contraseña; sólo con pantallas de piso, NIP; sin módulos, no
     * entra. Deducido y no elegido, para que no pueda quedar incoherente —un
     * NIP abriendo Finanzas desde una tablet—.
     */
    public static function acceso(bool $sistema, array $permisos): string
    {
        if ($sistema || self::tienePanel(self::normalizar($permisos))) {
            return self::ACCESO_CONTRASENA;
        }

        return self::normalizar($permisos) !== [] ? self::ACCESO_NIP : self::ACCESO_NINGUNO;
    }

    /** ¿Algún módulo del panel (no de piso)? */
    public static function tienePanel(array $permisos): bool
    {
        foreach (array_keys(self::normalizar($permisos)) as $modulo) {
            if ((self::MODULOS[$modulo]['grupo'] ?? '') === self::GRUPO_PANEL) {
                return true;
            }
        }

        return false;
    }

    /**
     * A dónde aterriza un rol al entrar: el panel para los de sistema; si no,
     * el primer módulo del panel que tenga (en el orden del menú); si sólo es
     * de piso, su pantalla.
     */
    public static function destino(bool $sistema, array $permisos): string
    {
        if ($sistema) {
            return '/admin';
        }

        $permisos = self::normalizar($permisos);
        foreach (self::MODULOS as $clave => $modulo) {
            if ($modulo['grupo'] === self::GRUPO_PANEL && isset($permisos[$clave])) {
                return $modulo['ruta'];
            }
        }
        foreach (self::MODULOS as $clave => $modulo) {
            if (isset($permisos[$clave])) {
                return $modulo['ruta'];
            }
        }

        return '/login';
    }

    /**
     * ¿Puede quien tiene $actor dar $nuevos? Nadie concede más de lo que
     * tiene: módulo por módulo, el nivel nuevo no puede pasar del suyo.
     * Los roles de sistema lo tienen todo.
     */
    public static function puedeOtorgar(bool $actorSistema, array $actor, array $nuevos): bool
    {
        if ($actorSistema) {
            return true;
        }

        $actor = self::normalizar($actor);
        foreach (self::normalizar($nuevos) as $modulo => $nivel) {
            if (!isset($actor[$modulo]) || !self::alcanza($actor[$modulo], $nivel)) {
                return false;
            }
        }

        return true;
    }

    /** ¿El nivel $tiene cubre lo que pide $requerido? */
    public static function alcanza(string $tiene, string $requerido): bool
    {
        return $tiene === self::EDITAR || ($tiene === self::VER && $requerido === self::VER);
    }

    /**
     * Deja sólo módulos del catálogo con un nivel válido. Lo que llega de un
     * formulario o de una fila vieja (un módulo retirado) se filtra aquí y en
     * ningún otro sitio.
     *
     * @return array<string, string>
     */
    public static function normalizar(array $permisos): array
    {
        $limpios = [];
        foreach ($permisos as $modulo => $nivel) {
            if (isset(self::MODULOS[$modulo]) && in_array($nivel, self::NIVELES, true)) {
                $limpios[$modulo] = $nivel;
            }
        }

        return $limpios;
    }

    /** Los permisos de un rol de sistema: todo en editar. */
    public static function todos(): array
    {
        return array_fill_keys(array_keys(self::MODULOS), self::EDITAR);
    }

    public static function esSistema(string $slug): bool
    {
        return in_array($slug, self::ROLES_SISTEMA, true);
    }

    private static function casa(string $url, string $prefijo): bool
    {
        return $url === $prefijo || str_starts_with($url, $prefijo . '/');
    }
}
