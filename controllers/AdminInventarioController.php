<?php
/**
 * Módulo de Inventario (Ingredientes) del panel admin.
 * CRUD de ingredientes y ajuste manual de existencias con bitácora.
 */

namespace Controllers;

use Model\Ingrediente;
use MVC\Router;
use Services\RangoPeriodo;

class AdminInventarioController
{
    private const PATH = '/admin/inventario';
    private const CSS = '/build/css/admin/inventario.css';

    public static function index(Router $router): void
    {
        $rango = RangoPeriodo::resolver($_GET);

        $ingredientes = [];
        try {
            $ingredientes = Ingrediente::todos();
        } catch (\Throwable $e) {
            Ingrediente::setAlerta('error', 'No se pudo cargar el inventario. ¿Ya corriste la migración de la BD?');
        }

        $bajoStock = 0;
        $bajos = [];
        $valorInventario = 0.0;
        foreach ($ingredientes as $ing) {
            if ((float) $ing->stock <= (float) $ing->stock_minimo) {
                $bajoStock++;
                $bajos[] = $ing;
            }
            $valorInventario += (float) $ing->stock * (float) $ing->costo;
        }

        $consumo = self::consumo((string) $rango['start'], (string) $rango['end']);
        $consumoPrev = !empty($rango['comparar'])
            ? self::consumo((string) $rango['prevStart'], (string) $rango['prevEnd'])
            : null;

        self::render('inventario/index', [
            'title' => 'Inventario',
            'topbarSection' => 'Inventario',
            'rango' => $rango,
            'ingredientes' => $ingredientes,
            'ingredientesBajos' => $bajos,
            'totalIngredientes' => count($ingredientes),
            'bajoStock' => $bajoStock,
            'valorInventario' => $valorInventario,
            'consumo' => $consumo,
            'consumoPrev' => $consumoPrev,
            'deltaConsumo' => $consumoPrev === null
                ? null
                : RangoPeriodo::delta($consumo['valor'], $consumoPrev['valor']),
            'alertas' => Ingrediente::getAlertas(),
        ]);
    }

    /**
     * Consumo de insumos en un tramo: cuánto salió por venta y cuánto costó.
     * movimientos_inventario.cantidad es negativa en las salidas, y no guarda
     * el costo del momento, así que se valoriza con el costo actual del
     * ingrediente. Es una aproximación y la vista lo dice.
     *
     * @return array{valor: float, movimientos: int, ajustes: int}
     */
    private static function consumo(string $start, string $end): array
    {
        $resumen = ['valor' => 0.0, 'movimientos' => 0, 'ajustes' => 0];

        try {
            $db = Ingrediente::getDB();
            $filtro = "mi.created_at >= '{$start} 00:00:00' AND mi.created_at <= '{$end} 23:59:59'";

            $res = $db->query(
                "SELECT COALESCE(SUM(ABS(mi.cantidad) * i.costo), 0) AS valor,
                        COUNT(*) AS n
                   FROM movimientos_inventario mi
                   JOIN ingredientes i ON i.id = mi.ingrediente_id
                  WHERE mi.tipo = 'venta' AND mi.cantidad < 0 AND {$filtro}"
            );
            if ($res && ($row = $res->fetch_assoc())) {
                $resumen['valor'] = (float) $row['valor'];
                $resumen['movimientos'] = (int) $row['n'];
            }

            $res = $db->query(
                "SELECT COUNT(*) AS n FROM movimientos_inventario mi
                  WHERE mi.tipo = 'ajuste' AND {$filtro}"
            );
            if ($res && ($row = $res->fetch_assoc())) {
                $resumen['ajustes'] = (int) $row['n'];
            }
        } catch (\Throwable $e) {
            error_log('AdminInventarioController::consumo - ' . $e->getMessage());
        }

        return $resumen;
    }

    public static function create(Router $router): void
    {
        $ingrediente = new Ingrediente();
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $ingrediente->sincronizar($_POST);
            $ingrediente->activo = isset($_POST['activo']) ? 1 : 0;
            $alertas = $ingrediente->validar();

            if (empty($alertas)) {
                $resultado = $ingrediente->guardar();
                if ($resultado && ($resultado['resultado'] ?? false)) {
                    Ingrediente::setAlerta('exito', 'Ingrediente creado correctamente');
                    self::index($router);
                    return;
                }
                Ingrediente::setAlerta('error', 'No se pudo guardar el ingrediente');
                $alertas = Ingrediente::getAlertas();
            }
        }

        self::renderForm($ingrediente, $alertas, 'Crear ingrediente', 'Nuevo ingrediente');
    }

    public static function edit(Router $router): void
    {
        $id = self::validarId($_GET['id'] ?? null, $router);
        $ingrediente = Ingrediente::find($id);

        if (!$ingrediente) {
            Ingrediente::setAlerta('error', 'El ingrediente no existe');
            self::index($router);
            return;
        }

        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $ingrediente->sincronizar($_POST);
            $ingrediente->activo = isset($_POST['activo']) ? 1 : 0;
            $alertas = $ingrediente->validar();

            if (empty($alertas)) {
                if ($ingrediente->guardar()) {
                    Ingrediente::setAlerta('exito', 'Ingrediente actualizado correctamente');
                    self::index($router);
                    return;
                }
                Ingrediente::setAlerta('error', 'No se pudo actualizar el ingrediente');
                $alertas = Ingrediente::getAlertas();
            }
        }

        self::renderForm($ingrediente, $alertas, 'Guardar cambios', 'Editar ingrediente');
    }

    public static function delete(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::PATH);
        }

        $id = self::validarId($_POST['id'] ?? null, $router);
        $ingrediente = Ingrediente::find($id);

        if ($ingrediente && $ingrediente->eliminar()) {
            Ingrediente::setAlerta('exito', 'Ingrediente eliminado correctamente');
        } else {
            Ingrediente::setAlerta('error', 'No se pudo eliminar el ingrediente');
        }

        self::index($router);
    }

    /** Ajuste manual de existencias: fija el nuevo stock y registra el movimiento. */
    public static function ajustar(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::PATH);
        }

        $id = self::validarId($_POST['id'] ?? null, $router);
        $ingrediente = Ingrediente::find($id);
        $nuevo = $_POST['stock'] ?? null;

        if (!$ingrediente) {
            Ingrediente::setAlerta('error', 'El ingrediente no existe');
            self::index($router);
            return;
        }

        if (!is_numeric($nuevo)) {
            Ingrediente::setAlerta('error', 'El nuevo stock debe ser un número');
            self::index($router);
            return;
        }

        $anterior = (float) $ingrediente->stock;
        $nuevoStock = (float) $nuevo;
        $delta = $nuevoStock - $anterior;

        $ingrediente->stock = $nuevoStock;
        $ingrediente->guardar();

        try {
            $ingId = (int) $ingrediente->id;
            $deltaSql = number_format($delta, 3, '.', '');
            Ingrediente::ejecutarSQL(
                "INSERT INTO movimientos_inventario (ingrediente_id, tipo, cantidad, ticket_item_id)
                 VALUES ({$ingId}, 'ajuste', {$deltaSql}, NULL)"
            );
        } catch (\Throwable $e) {
            error_log('AdminInventarioController::ajustar - ' . $e->getMessage());
        }

        Ingrediente::setAlerta('exito', 'Existencias actualizadas');
        self::index($router);
    }

    /** Entrada de mercancía: SUMA la cantidad recibida al stock y la registra. */
    public static function entrada(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::PATH);
        }

        $id = self::validarId($_POST['id'] ?? null, $router);
        $ingrediente = Ingrediente::find($id);
        $cantidad = $_POST['cantidad'] ?? null;

        if (!$ingrediente) {
            Ingrediente::setAlerta('error', 'El ingrediente no existe');
            self::index($router);
            return;
        }

        if (!is_numeric($cantidad) || (float) $cantidad <= 0) {
            Ingrediente::setAlerta('error', 'La cantidad recibida debe ser un número mayor a cero');
            self::index($router);
            return;
        }

        $entrada = (float) $cantidad;
        $ingrediente->stock = (float) $ingrediente->stock + $entrada;
        $ingrediente->guardar();

        try {
            $ingId = (int) $ingrediente->id;
            $entradaSql = number_format($entrada, 3, '.', '');
            Ingrediente::ejecutarSQL(
                "INSERT INTO movimientos_inventario (ingrediente_id, tipo, cantidad, ticket_item_id)
                 VALUES ({$ingId}, 'ajuste', {$entradaSql}, NULL)"
            );
        } catch (\Throwable $e) {
            error_log('AdminInventarioController::entrada - ' . $e->getMessage());
        }

        Ingrediente::setAlerta('exito', 'Entrada registrada: ' . htmlspecialchars($ingrediente->nombre));
        self::index($router);
    }

    private static function renderForm(Ingrediente $ingrediente, array $alertas, string $accion, string $titulo): void
    {
        self::render('inventario/form', [
            'title' => $titulo,
            'topbarSection' => 'Inventario / ' . $titulo,
            'ingrediente' => $ingrediente,
            'unidades' => Ingrediente::UNIDADES,
            'alertas' => $alertas,
            'accion' => $accion,
        ]);
    }

    private static function render(string $view, array $data = []): void
    {
        AdminController::render($view, array_merge([
            'activeModule' => 'inventario',
            'styles' => [self::CSS],
            'scripts' => [],
        ], $data));
    }

    private static function validarId($id, Router $router): int
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) {
            Ingrediente::setAlerta('error', 'Identificador no válido');
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
