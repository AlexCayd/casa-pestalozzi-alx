<?php
/**
 * CRUD de estaciones de impresión térmica (ESC/POS) bajo /admin/printers.
 * Permite alta/edición/baja de impresoras y enviar un ticket de prueba a cada una.
 * Sigue el mismo patrón que AdminMenuController (render, validarId, redirect, alertas).
 */

namespace Controllers;

use Model\Impresora;
use Classes\TicketPrinter;
use MVC\Router;

class AdminPrintersController
{
    private const PRINTERS_PATH = '/admin/printers';
    private const PRINTERS_CSS = '/build/css/admin/menu.css';

    // Áreas de producción (areas_produccion). Una comanda se imprime por área.
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
            'areas' => self::AREAS,
            'alertas' => Impresora::getAlertas(),
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
            'areas' => self::AREAS,
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
            'areas' => self::AREAS,
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
     */
    public static function test(Router $router): void
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

        $areaNombre = $impresora->area_id !== null
            ? (self::AREAS[(int)$impresora->area_id] ?? null)
            : null;

        if (TicketPrinter::imprimirPrueba($impresora, $areaNombre)) {
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
            'styles' => [self::PRINTERS_CSS],
            'scripts' => [],
        ], $data));
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

    private static function redirect(string $url): void
    {
        header('Location: ' . $url, true, 302);
        exit;
    }
}
