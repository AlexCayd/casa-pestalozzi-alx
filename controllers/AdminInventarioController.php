<?php
/**
 * Módulo de Inventario (Ingredientes) del panel admin.
 * CRUD de ingredientes y ajuste manual de existencias con bitácora.
 */

namespace Controllers;

use Model\Ingrediente;
use MVC\Router;
use Services\Inventario;
use Services\RangoPeriodo;

class AdminInventarioController
{
    private const PATH = '/admin/inventario';
    private const CSS = '/build/css/admin/inventario.css';
    private const JS = '/build/js/admin/inventario.js';

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
        $mermas = self::mermasDelPeriodo((string) $rango['start'], (string) $rango['end']);

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
            'comparando' => $consumoPrev !== null,
            'deltaConsumo' => $consumoPrev === null
                ? null
                : RangoPeriodo::delta($consumo['valor'], $consumoPrev['valor']),
            // El periodo previo ya se consultaba entero y sólo se aprovechaba
            // el consumo. Ni el valor del inventario ni el bajo stock admiten
            // comparativo: son fotos del stock de hoy, sin histórico del que
            // sacar el de hace un mes.
            'deltaMerma' => $consumoPrev === null
                ? null
                : RangoPeriodo::delta($consumo['mermaValor'], $consumoPrev['mermaValor']),
            'motivosMerma' => Inventario::MOTIVOS_MERMA,
            'mermas' => $mermas['lista'],
            'mermaPorMotivo' => $mermas['porMotivo'],
            'alertas' => Ingrediente::getAlertas(),
        ]);
    }

    /**
     * Mermas registradas en el tramo, de la más reciente a la más antigua.
     *
     * Se valorizan con el costo que el movimiento congeló; el COALESCE cubre
     * los registros anteriores a que la bitácora lo guardara. El LEFT JOIN a
     * usuarios es por la FK ON DELETE SET NULL: dar de baja a quien registró la
     * merma no debe borrar la merma.
     *
     * @return array{lista: array<int, array<string, mixed>>, porMotivo: array<string, float>}
     */
    private static function mermasDelPeriodo(string $start, string $end, int $limite = 25): array
    {
        $resultado = ['lista' => [], 'porMotivo' => []];

        try {
            $db = Ingrediente::getDB();
            $limite = max(1, min(100, $limite));
            $filtro = "mi.tipo = 'merma'
                       AND mi.created_at >= '{$start} 00:00:00'
                       AND mi.created_at <= '{$end} 23:59:59'";

            $res = $db->query(
                "SELECT mi.id, mi.cantidad, mi.motivo, mi.nota, mi.created_at,
                        COALESCE(mi.costo_unitario, i.costo) AS costo,
                        i.nombre AS ingrediente, i.unidad,
                        u.nombre AS usuario
                   FROM movimientos_inventario mi
                   JOIN ingredientes i ON i.id = mi.ingrediente_id
                   LEFT JOIN usuarios u ON u.id = mi.usuario_id
                  WHERE {$filtro}
                  ORDER BY mi.created_at DESC, mi.id DESC
                  LIMIT {$limite}"
            );
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $cantidad = abs((float) $row['cantidad']);
                    $resultado['lista'][] = [
                        'ingrediente' => (string) $row['ingrediente'],
                        'unidad' => (string) $row['unidad'],
                        'cantidad' => $cantidad,
                        'motivo' => (string) ($row['motivo'] ?? ''),
                        'nota' => (string) ($row['nota'] ?? ''),
                        'valor' => $cantidad * (float) $row['costo'],
                        'usuario' => (string) ($row['usuario'] ?? ''),
                        'fecha' => (string) $row['created_at'],
                    ];
                }
            }

            // El desglose por motivo va sobre TODO el periodo, no sobre las
            // filas mostradas: es lo que dice dónde se está perdiendo producto.
            $res = $db->query(
                "SELECT mi.motivo,
                        COALESCE(SUM(ABS(mi.cantidad) * COALESCE(mi.costo_unitario, i.costo)), 0) AS valor
                   FROM movimientos_inventario mi
                   JOIN ingredientes i ON i.id = mi.ingrediente_id
                  WHERE {$filtro}
                  GROUP BY mi.motivo
                  ORDER BY valor DESC"
            );
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $resultado['porMotivo'][(string) ($row['motivo'] ?? 'otro')] = (float) $row['valor'];
                }
            }
        } catch (\Throwable $e) {
            error_log('AdminInventarioController::mermasDelPeriodo - ' . $e->getMessage());
        }

        return $resultado;
    }

    /**
     * Movimientos de un tramo: cuánto salió por venta, cuánto se tiró y cuántas
     * entradas y correcciones hubo.
     *
     * Las salidas por venta se valorizan con el costo ACTUAL del ingrediente
     * porque no lo guardaban al momento; es una aproximación y la vista lo
     * dice. Las mermas sí traen su costo congelado y se valorizan con él.
     *
     * @return array{valor: float, movimientos: int, ajustes: int, entradas: int,
     *               mermaValor: float, mermas: int}
     */
    private static function consumo(string $start, string $end): array
    {
        $resumen = [
            'valor' => 0.0,
            'movimientos' => 0,
            'ajustes' => 0,
            'entradas' => 0,
            'mermaValor' => 0.0,
            'mermas' => 0,
        ];

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

            // COALESCE sobre el costo del ingrediente para los movimientos
            // anteriores a que la bitácora guardara el costo del momento.
            $res = $db->query(
                "SELECT COALESCE(SUM(ABS(mi.cantidad) * COALESCE(mi.costo_unitario, i.costo)), 0) AS valor,
                        COUNT(*) AS n
                   FROM movimientos_inventario mi
                   JOIN ingredientes i ON i.id = mi.ingrediente_id
                  WHERE mi.tipo = 'merma' AND {$filtro}"
            );
            if ($res && ($row = $res->fetch_assoc())) {
                $resumen['mermaValor'] = (float) $row['valor'];
                $resumen['mermas'] = (int) $row['n'];
            }

            $res = $db->query(
                "SELECT mi.tipo, COUNT(*) AS n FROM movimientos_inventario mi
                  WHERE mi.tipo IN ('ajuste', 'entrada') AND {$filtro}
                  GROUP BY mi.tipo"
            );
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $clave = $row['tipo'] === 'entrada' ? 'entradas' : 'ajustes';
                    $resumen[$clave] = (int) $row['n'];
                }
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
            $usuarioSql = self::usuarioActual() ?? 'NULL';
            Ingrediente::ejecutarSQL(
                "INSERT INTO movimientos_inventario (ingrediente_id, tipo, cantidad, usuario_id, ticket_item_id)
                 VALUES ({$ingId}, 'ajuste', {$deltaSql}, {$usuarioSql}, NULL)"
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
            $costoSql = number_format((float) $ingrediente->costo, 4, '.', '');
            $usuarioSql = self::usuarioActual() ?? 'NULL';
            // 'entrada' y no 'ajuste': recibir mercancía no es corregir un
            // conteo, y mientras compartían tipo el panel no podía separarlas.
            Ingrediente::ejecutarSQL(
                "INSERT INTO movimientos_inventario (ingrediente_id, tipo, cantidad, costo_unitario, usuario_id, ticket_item_id)
                 VALUES ({$ingId}, 'entrada', {$entradaSql}, {$costoSql}, {$usuarioSql}, NULL)"
            );
        } catch (\Throwable $e) {
            error_log('AdminInventarioController::entrada - ' . $e->getMessage());
        }

        Ingrediente::setAlerta('exito', 'Entrada registrada: ' . htmlspecialchars($ingrediente->nombre));
        self::index($router);
    }

    /** Merma: producto que se tira. Descuenta stock y lo deja como costo. */
    public static function merma(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::PATH);
        }

        $id = self::validarId($_POST['id'] ?? null, $router);
        $cantidad = $_POST['cantidad'] ?? null;

        if (!is_numeric($cantidad)) {
            Ingrediente::setAlerta('error', 'La cantidad mermada debe ser un número mayor a cero');
            self::index($router);
            return;
        }

        $resultado = Inventario::registrarMerma(
            $id,
            (float) $cantidad,
            trim((string) ($_POST['motivo'] ?? '')),
            (string) ($_POST['nota'] ?? ''),
            self::usuarioActual()
        );

        Ingrediente::setAlerta(
            $resultado['ok'] ? 'exito' : 'error',
            $resultado['mensaje'] ?: 'No se pudo registrar la merma.'
        );
        self::index($router);
    }

    /** Quién está registrando el movimiento; null si no hay sesión. */
    private static function usuarioActual(): ?int
    {
        return ((int) ($_SESSION['id'] ?? 0)) ?: null;
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
            'scripts' => [self::JS],
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
