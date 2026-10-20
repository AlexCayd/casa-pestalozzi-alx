<?php
/**
 * CRUD de estaciones de impresión térmica (ESC/POS) bajo /admin/printers.
 * Permite alta/edición/baja de impresoras y enviar un ticket de prueba a cada una.
 * El interruptor GLOBAL del servicio NO está aquí: es un ajuste del POS y lo
 * edita AdminConfigurationController en /admin/configuracion/pos.
 * Sigue el mismo patrón que AdminMenuController (render, validarId, redirect, alertas).
 */

namespace Controllers;

use Model\Impresora;
use Model\ConfiguracionPos;
use Classes\TicketPrinter;
use MVC\Router;

class AdminPrintersController
{
    private const PRINTERS_PATH = '/admin/printers';
    private const PRINTERS_CSS = '/build/css/admin/menu.css';
    // Hoja y script propios, además de menu.css (de donde siguen saliendo el
    // formulario y la estructura del listado): el color por área, el botón de
    // prueba y sus estados no son de Menú.
    private const IMPRESORAS_CSS = '/build/css/admin/impresoras.css';
    private const IMPRESORAS_JS = '/build/js/admin/impresoras.js';

    // Respaldo de las áreas de producción por si no se puede leer la tabla. La
    // fuente es `areas_produccion` (ver areasProduccion()), que además trae el
    // color de cada estación.
    private const AREAS = [
        1 => 'Café',
        2 => 'Jugos',
        3 => 'Cocina',
        4 => 'Horno',
    ];

    public static function index(Router $router): void
    {
        self::render('printers/index', [
            'title' => 'Estaciones de impresión',
            'topbarSection' => 'Estaciones de impresión',
            'impresoras' => Impresora::all(),
            'areas' => self::areasProduccion(),
            'alertas' => Impresora::getAlertas(),
            // Sólo para rotular las filas: el interruptor se movió a
            // Configuración > POS. Lectura directa y no
            // TicketPrinter::servicioActivo(), que cachea el valor por proceso
            // para servir a las cuatro áreas de una misma comanda.
            'servicioActivo' => ConfiguracionPos::impresionActiva(),
        ]);
    }

    public static function create(Router $router): void
    {
        $impresora = new Impresora();
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::asignarDatos($impresora);
            $alertas = $impresora->validar();

            if (empty($alertas)) {
                if (self::guardar($impresora)) {
                    Impresora::setAlerta('exito', 'Impresora creada correctamente');
                    self::index($router);
                    return;
                }

                Impresora::setAlerta('error', 'No se pudo guardar la impresora');
                $alertas = Impresora::getAlertas();
            }
        }

        self::render('printers/form', [
            'title' => 'Nueva impresora',
            'topbarSection' => 'Estaciones de impresión / Nueva impresora',
            'impresora' => $impresora,
            'areas' => self::areasProduccion(),
            'alertas' => $alertas,
            'accion' => 'Crear',
        ]);
    }

    public static function edit(Router $router): void
    {
        $id = self::validarId($_GET['id'] ?? $_POST['id'] ?? null, $router);
        $impresora = Impresora::find($id);

        if (!$impresora) {
            Impresora::setAlerta('error', 'La impresora no existe');
            self::index($router);
            return;
        }

        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::asignarDatos($impresora);
            $alertas = $impresora->validar();

            if (empty($alertas)) {
                if (self::guardar($impresora)) {
                    Impresora::setAlerta('exito', 'Impresora actualizada correctamente');
                    self::index($router);
                    return;
                }

                Impresora::setAlerta('error', 'No se pudo actualizar la impresora');
                $alertas = Impresora::getAlertas();
            }
        }

        self::render('printers/form', [
            'title' => 'Editar impresora',
            'topbarSection' => 'Estaciones de impresión / Editar impresora',
            'impresora' => $impresora,
            'areas' => self::areasProduccion(),
            'alertas' => $alertas,
            'accion' => 'Actualizar',
        ]);
    }

    public static function delete(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::PRINTERS_PATH);
        }

        $id = self::validarId($_POST['id'] ?? null, $router);
        $impresora = Impresora::find($id);

        if (!$impresora) {
            Impresora::setAlerta('error', 'La impresora no existe');
            self::index($router);
            return;
        }

        if ($impresora->eliminar()) {
            Impresora::setAlerta('exito', 'Impresora eliminada correctamente');
        } else {
            Impresora::setAlerta('error', 'No se pudo eliminar la impresora');
        }

        self::index($router);
    }

    /**
     * Envía un ticket de prueba a la impresora seleccionada. La impresión es un
     * efecto secundario: aunque falle, el endpoint vuelve al listado con su alerta.
     *
     * Dos respuestas para la misma ruta. Sin JS, el formulario hace POST y
     * vuelve al listado con la alerta, como siempre. Con JS (impresoras.js)
     * la petición pide JSON y el botón cuenta el resultado en su sitio
     * —imprimiendo, enviada, falló— más un AppNotice con el mismo mensaje que
     * daría la alerta: probar una estación no tiene por qué recargar la página
     * ni mandar la vista arriba del todo.
     */
    public static function test(Router $router): void
    {
        $json = self::pideJson();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($json) {
                self::responderJson(405, false, 'Método no permitido');
            }
            self::redirect(self::PRINTERS_PATH);
        }

        // validarId() responde con la página entera: en JSON se valida antes.
        if ($json && !filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
            self::responderJson(422, false, 'Identificador no válido');
        }

        $id = self::validarId($_POST['id'] ?? null, $router);
        $impresora = Impresora::find($id);

        if (!$impresora) {
            if ($json) {
                self::responderJson(404, false, 'La impresora no existe');
            }
            Impresora::setAlerta('error', 'La impresora no existe');
            self::index($router);
            return;
        }

        $areas = self::areasProduccion();
        $areaNombre = $impresora->area_id !== null
            ? ($areas[(int)$impresora->area_id]['nombre'] ?? null)
            : null;

        if (TicketPrinter::imprimirPrueba($impresora, $areaNombre)) {
            if ($json) {
                self::responderJson(200, true, "Ticket de prueba enviado a {$impresora->nombre}");
            }
            Impresora::setAlerta('exito', "Ticket de prueba enviado a {$impresora->nombre}");
        } else {
            switch ($impresora->conexion) {
                case 'windows':
                    $sugerencia = "Verifica que el recurso compartido \"{$impresora->dispositivo}\" exista (\\\\{$impresora->dispositivo}), que el nombre coincida con el de uso compartido y que el servicio de Apache tenga permiso para acceder a él";
                    break;
                default:
                    $sugerencia = 'Verifica que esté encendida y conectada a la red';
            }

            // Detalle real de la excepción subyacente (ej. "Failed to copy file
            // to printer"), clave para diagnosticar durante la fase de pruebas.
            $detalle = TicketPrinter::ultimoError();
            $mensaje = "No se pudo imprimir en {$impresora->nombre}. {$sugerencia}";
            if ($detalle) {
                $mensaje .= " · Detalle: {$detalle}";
            }
            if ($json) {
                // 200 y no 5xx: la petición se atendió; lo que falló es la
                // impresora, y eso ES la respuesta, no un error del servidor.
                self::responderJson(200, false, $mensaje);
            }
            Impresora::setAlerta('error', $mensaje);
        }

        self::index($router);
    }

    // Vuelca el POST en el objeto, normalizando los campos especiales.
    private static function asignarDatos(Impresora $impresora): void
    {
        $impresora->sincronizar($_POST);

        $impresora->rol = in_array($_POST['rol'] ?? '', ['comanda', 'cuenta'], true)
            ? $_POST['rol'] : 'comanda';
        $impresora->conexion = in_array($_POST['conexion'] ?? '', ['red', 'windows'], true)
            ? $_POST['conexion'] : 'red';
        $impresora->ancho = (int) ($_POST['ancho'] ?? 48);
        $impresora->activo = isset($_POST['activo']) ? 1 : 0;

        // Sólo los campos del tipo de conexión elegido son relevantes; el resto
        // se normaliza para no arrastrar datos del otro modo. Windows usa el
        // campo `dispositivo` (nombre de impresora / smb://host/recurso).
        if ($impresora->conexion === 'red') {
            $impresora->host = trim((string) ($_POST['host'] ?? ''));
            $impresora->puerto = (int) ($_POST['puerto'] ?? 9100);
            $impresora->dispositivo = null;
        } else {
            $impresora->dispositivo = trim((string) ($_POST['dispositivo'] ?? '')) ?: null;
            $impresora->host = '';
            $impresora->puerto = 9100;
        }

        // La cuenta no se asocia a un área; la comanda sí.
        if ($impresora->rol === 'cuenta') {
            $impresora->area_id = null;
        } else {
            $area = (int) ($_POST['area_id'] ?? 0);
            $impresora->area_id = $area > 0 ? $area : null;
        }
    }

    /**
     * Persiste la impresora con SQL crudo para respetar el NULL de area_id (las
     * impresoras de cuenta no tienen área). El crear()/actualizar() genérico de
     * ActiveRecord entrecomilla todos los valores y rompería el INSERT del NULL.
     */
    private static function guardar(Impresora $impresora): bool
    {
        $nombre   = Impresora::escaparString($impresora->nombre);
        $rol      = Impresora::escaparString($impresora->rol);
        $conexion = Impresora::escaparString($impresora->conexion);
        $host     = Impresora::escaparString($impresora->host);
        $puerto   = (int) $impresora->puerto;
        $ancho    = (int) $impresora->ancho;
        $activo   = (int) $impresora->activo;
        $areaSql  = $impresora->area_id !== null ? (int) $impresora->area_id : 'NULL';
        $dispositivoSql = $impresora->dispositivo !== null
            ? "'" . Impresora::escaparString($impresora->dispositivo) . "'"
            : 'NULL';

        if ($impresora->id) {
            $id = (int) $impresora->id;
            return (bool) Impresora::ejecutarSQL(
                "UPDATE impresoras
                 SET nombre = '{$nombre}', area_id = {$areaSql}, rol = '{$rol}',
                     conexion = '{$conexion}', host = '{$host}', puerto = {$puerto},
                     dispositivo = {$dispositivoSql}, ancho = {$ancho}, activo = {$activo}
                 WHERE id = {$id} LIMIT 1"
            );
        }

        return (bool) Impresora::ejecutarSQL(
            "INSERT INTO impresoras (nombre, area_id, rol, conexion, host, puerto, dispositivo, ancho, activo)
             VALUES ('{$nombre}', {$areaSql}, '{$rol}', '{$conexion}', '{$host}', {$puerto}, {$dispositivoSql}, {$ancho}, {$activo})"
        );
    }

    private static function render(string $view, array $data = []): void
    {
        AdminController::render($view, array_merge([
            'activeModule' => 'printers',
            'styles' => [self::PRINTERS_CSS, self::IMPRESORAS_CSS],
            'scripts' => [self::IMPRESORAS_JS],
        ], $data));
    }

    /**
     * Áreas de producción con su color, indexadas por id:
     * [id => ['nombre' => string, 'color' => ?string, 'tinta' => 'ink'|'paper']].
     *
     * El color es `areas_produccion.color`, el mismo que corona el tablero KDS
     * de esa estación, y la vista lo inyecta como custom property en línea
     * para teñir la fila. Es dato de negocio que se edita, no un token: por eso
     * se valida como hex aquí —va a parar a un atributo style— y si no lo es
     * llega null y la fila sale neutra en vez de con una declaración inválida.
     *
     * `tinta` dice qué ancla del panel va ENCIMA de ese color en una pastilla
     * sólida. Se elige aquí porque el color lo decide quien edita el área y el
     * CSS no puede calcular contraste: la vista lo traduce a --admin-ink o
     * --admin-paper, así que ningún hex sale de la base hacia la tinta.
     *
     * @return array<int, array{nombre: string, color: ?string, tinta: string}>
     */
    private static function areasProduccion(): array
    {
        static $areas = null;
        if ($areas !== null) {
            return $areas;
        }

        $areas = [];
        try {
            $res = Impresora::getDB()->query('SELECT id, nombre, color FROM areas_produccion ORDER BY id ASC');
            while ($res && ($fila = $res->fetch_assoc())) {
                $color = trim((string) ($fila['color'] ?? ''));
                $valido = preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $color) === 1;
                $areas[(int) $fila['id']] = [
                    'nombre' => (string) $fila['nombre'],
                    'color' => $valido ? $color : null,
                    'tinta' => $valido ? self::tintaSobre($color) : 'ink',
                ];
            }
        } catch (\Throwable $e) {
            error_log('AdminPrintersController::areasProduccion - ' . $e->getMessage());
        }

        if (empty($areas)) {
            foreach (self::AREAS as $id => $nombre) {
                $areas[$id] = ['nombre' => $nombre, 'color' => null, 'tinta' => 'ink'];
            }
        }

        return $areas;
    }

    /**
     * 'ink' o 'paper': la de las dos anclas del panel que más contrasta con un
     * color, por luminancia relativa de WCAG (no por el promedio «percibido»
     * de AdminAreaController, que se equivoca en los tonos medios). Los dos
     * valores son los de --admin-ink (#0b0b0c) y --admin-paper (#fbfbfa) en
     * _globals.scss, ya convertidos a luminancia.
     */
    private static function tintaSobre(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $canal = static function (string $par): float {
            $c = hexdec($par) / 255;
            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $canal(substr($hex, 0, 2))
            + 0.7152 * $canal(substr($hex, 2, 2))
            + 0.0722 * $canal(substr($hex, 4, 2));

        $lInk = 0.0037;
        $lPaper = 0.9640;
        $contraInk = ($l + 0.05) / ($lInk + 0.05);
        $contraPaper = ($lPaper + 0.05) / ($l + 0.05);

        return $contraInk >= $contraPaper ? 'ink' : 'paper';
    }

    /** El botón de prueba con JS pide JSON; el formulario sin JS, HTML. */
    private static function pideJson(): bool
    {
        return stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
    }

    private static function responderJson(int $codigo, bool $ok, string $mensaje): void
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function validarId($id, Router $router): int
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (!$id) {
            Impresora::setAlerta('error', 'Identificador no válido');
            self::index($router);
            exit;
        }

        return $id;
    }

    /** Id del admin en sesión, para dejar rastro de quién movió el interruptor. */
    private static function usuarioAutenticadoId(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $usuarioId = filter_var(
            $_SESSION['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return $usuarioId ? (int) $usuarioId : null;
    }

    private static function redirect(string $url): void
    {
        header('Location: ' . $url, true, 302);
        exit;
    }
}
