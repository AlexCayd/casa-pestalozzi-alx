<?php
declare(strict_types=1);
namespace Controllers;

use Classes\Auth;
use MVC\Router;
use Services\Inventory\AdministracionAlmacenes;
use Services\Inventory\DirectorioProveedores;
use Services\Inventory\InventarioError;
use Services\Security\AdminCsrfService;
use Services\Security\ContextoNegocio;
use Services\Security\Contextos;

/**
 * Proveedores de cada ámbito, colgados de Almacenes (arquitectura §17): mismo
 * permiso `inventario`, el ámbito sale de la ruta y cada escritura se autoriza de
 * nuevo en el servicio. Global además consulta el directorio de los tres negocios.
 */
final class AdminProveedoresController
{
    public static function index(Router $router): void
    {
        $ctx = ContextoNegocio::slug();
        ContextoNegocio::exigir('inventario', 'ver', $ctx);
        $consolidado = $ctx === 'global' && ($_GET['vista'] ?? '') === 'consolidado';
        $alertas = self::flash($ctx);
        $proveedores = [];
        try {
            $proveedores = self::servicio()->listar($ctx, $consolidado);
        } catch (\Throwable $e) {
            http_response_code(self::estado($e));
            $alertas['error'][] = self::mensaje($e, 'No se pudo consultar el directorio de proveedores.');
        }
        self::render('almacenes/proveedores', $ctx, [
            'title' => 'Proveedores', 'proveedores' => $proveedores, 'consolidado' => $consolidado, 'alertas' => $alertas,
        ]);
    }

    public static function nuevo(Router $router): void
    {
        $ctx = ContextoNegocio::slug();
        ContextoNegocio::exigir('inventario', 'editar', $ctx);
        $alertas = self::flash($ctx);
        self::render('almacenes/proveedor', $ctx, [
            'title' => 'Nuevo proveedor', 'proveedor' => ($_SESSION['_proveedores_datos'][$ctx] ?? []) + ['id' => null, 'activo' => 1, 'editable' => true, 'unidad_id' => null, 'slug' => $ctx],
            'vistaProveedor' => 'datos', 'modoEdicionArticulos' => false, 'resumenRetiro' => null,
            'asignaciones' => [], 'asignables' => [], 'historial' => null, 'alertas' => $alertas,
        ]);
        unset($_SESSION['_proveedores_datos'][$ctx]);
    }

    public static function ficha(Router $router): void
    {
        $ctx = ContextoNegocio::slug();
        ContextoNegocio::exigir('inventario', 'ver', $ctx);
        $alertas = self::flash($ctx);
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        try {
            if (!$id) throw new InventarioError('ID_INVALIDO', 'El proveedor solicitado no es válido.');
            $articuloLegacy = filter_var($_GET['historial_articulo'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (is_int($articuloLegacy) && $articuloLegacy > 0) {
                $paginaLegacy = filter_var($_GET['historial_pagina'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
                self::redirigir(self::base($ctx) . '/historial?' . http_build_query(['id' => $id, 'articulo' => $articuloLegacy, 'pagina' => $paginaLegacy]));
            }
            $vista = is_string($_GET['vista'] ?? null) && in_array($_GET['vista'], ['articulos', 'entradas', 'datos'], true) ? $_GET['vista'] : 'articulos';
            $servicio = self::servicio();
            $proveedor = $servicio->proveedor($ctx, $id);
            $editar = $proveedor['editable'] && Auth::puede('inventario', 'editar', $ctx);
            $editarArticulos = $vista === 'articulos' && $editar && ($_GET['editar'] ?? '') === '1';
            $datos = ['vistaProveedor' => $vista, 'modoEdicionArticulos' => $editarArticulos];
            if ($vista === 'articulos') {
                $datos['asignaciones'] = $servicio->asignaciones($ctx, $id);
                $datos['asignables'] = $editarArticulos ? $servicio->articulosAsignables($ctx) : [];
                $datos['articulosRetirados'] = ($_GET['retirados'] ?? '') === '1'
                    ? array_values(array_filter($servicio->articulosHistorialPrecios($ctx, $id), static fn($a) => $a['precio_actual'] === null))
                    : null;
            } elseif ($vista === 'entradas') {
                $datos['historial'] = $servicio->historial($ctx, $id);
            } else {
                // La vista de datos necesita saber si se permite borrar, pero no
                // debe cargar movimientos ni recorrer el historial paginado.
                $datos['resumenRetiro'] = $servicio->resumenRetiro($ctx, $id);
                $proveedor = ($_SESSION['_proveedores_datos'][$ctx] ?? []) + $proveedor;
                unset($_SESSION['_proveedores_datos'][$ctx]);
            }
            self::render('almacenes/proveedor', $ctx, [
                'title' => (string)$proveedor['nombre'], 'proveedor' => $proveedor, 'puedeEditarFicha' => $editar,
                'alertas' => $alertas,
            ] + $datos);
        } catch (\Throwable $e) {
            http_response_code(self::estado($e));
            $alertas['error'][] = self::mensaje($e, 'No se pudo abrir el proveedor.');
            self::render('almacenes/proveedores', $ctx, ['title' => 'Proveedores', 'proveedores' => [], 'consolidado' => false, 'alertas' => $alertas, 'sinListado' => true]);
        }
    }

    /** Historial aislado de una asociación proveedor-artículo; esta vista nunca escribe. */
    public static function historialArticulo(Router $router): void
    {
        $ctx = ContextoNegocio::slug();
        ContextoNegocio::exigir('inventario', 'ver', $ctx);
        $alertas = self::flash($ctx);
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $articuloId = filter_var($_GET['articulo'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        try {
            if (!$id || !$articuloId) throw new InventarioError('ID_INVALIDO', 'El proveedor o artículo solicitado no es válido.');
            $servicio = self::servicio();
            $proveedor = $servicio->proveedor($ctx, $id);
            $contextoPrecio = $servicio->contextoHistorialPrecio($ctx, $id, $articuloId);
            if ($contextoPrecio === null) throw new InventarioError('RECURSO_NO_ENCONTRADO', 'Este artículo no tiene una oferta ni historial de este proveedor.');
            $pagina = filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
            self::render('almacenes/proveedor-historial-precios', $ctx, [
                'title' => 'Historial · ' . $contextoPrecio['articulo'], 'proveedor' => $proveedor,
                'contextoPrecio' => $contextoPrecio,
                'historialPrecios' => $servicio->historialPrecios($ctx, $id, $pagina, $articuloId),
                'graficaPrecio' => $servicio->graficaPrecio($ctx, $id, $articuloId),
                'comparacionPrecios' => $servicio->compararPrecios($ctx, $id, $articuloId),
                'alertas' => $alertas,
            ]);
        } catch (\Throwable $e) {
            http_response_code(self::estado($e));
            self::render('almacenes/proveedor-historial-precios', $ctx, [
                'title' => 'Historial de precios', 'proveedor' => ['id' => (int)$id, 'nombre' => 'Proveedor', 'activo' => 0, 'editable' => false],
                'contextoPrecio' => null, 'historialPrecios' => ['filas' => [], 'pagina' => 1, 'paginas' => 1, 'total' => 0, 'limite' => 25],
                'graficaPrecio' => null, 'comparacionPrecios' => [],
                'alertas' => ['error' => [self::mensaje($e, 'No se pudo abrir el historial.')], 'exito' => [], 'advertencia' => []],
            ]);
        }
    }

    public static function guardar(Router $router): void
    {
        $ctx = self::post();
        $id = self::idOpcional($_POST['id'] ?? '');
        // Un id presente pero inválido no se degrada a un alta nueva.
        if ($id === null && ($_POST['id'] ?? '') !== '') { self::avisar($ctx, 'error', 'El proveedor no es válido.'); self::redirigir(self::base($ctx)); }
        $permitidos =['nombre', 'contacto', 'telefono', 'correo', 'notas', 'activo'];
        $datos = array_intersect_key($_POST, array_flip($permitidos));
        $r = self::servicio()->guardar($ctx, $id, $datos);
        try {
            AdministracionAlmacenes::exigirResultado($r);
            self::avisar($ctx, 'exito', $id === null ? 'Proveedor creado.' : 'Proveedor actualizado.');
            self::volver($ctx, (int)$r['proveedor_id'], 'datos');
        } catch (\Throwable $e) {
            self::avisar($ctx, 'error', self::mensaje($e, 'No se guardó el proveedor.'));
            // Sólo texto plano de los campos permitidos; la vista lo escapa al pintar.
            $_SESSION['_proveedores_datos'][$ctx] = array_map(static fn($v) => is_string($v) ? mb_substr($v, 0, 2000) : '', $datos) + ['activo' => isset($datos['activo']) ? 1 : 0];
            $id === null ? self::redirigir(self::base($ctx) . '/nuevo') : self::volver($ctx, $id, 'datos');
        }
    }

    public static function asignaciones(Router $router): void
    {
        $ctx = self::post();
        $id = self::idOpcional($_POST['id'] ?? '');
        if ($id === null) { self::avisar($ctx, 'error', 'El proveedor no es válido.'); self::redirigir(self::base($ctx)); return; }
        // Filas del formulario: las marcadas para quitar y la fila nueva vacía no viajan.
        $filas = [];
        foreach ((array)($_POST['filas'] ?? []) as $fila) {
            if (!is_array($fila) || !empty($fila['quitar']) || ($fila['articulo_id'] ?? '') === '') continue;
            $filas[] = [
                'articulo_id' => is_string($fila['articulo_id'] ?? null) ? $fila['articulo_id'] : '',
                'costo' => is_string($fila['costo'] ?? null) && $fila['costo'] !== '' ? $fila['costo'] : '0',
                'codigo' => is_string($fila['codigo'] ?? null) ? $fila['codigo'] : '',
                'preferente' => !empty($fila['preferente']) ? '1' : '0',
            ];
        }
        $r = self::servicio()->guardarAsignaciones($ctx, $id, $filas);
        $volverEditar = false;
        try {
            AdministracionAlmacenes::exigirResultado($r);
            self::avisar($ctx, 'exito', 'Artículos del proveedor actualizados.');
        } catch (\Throwable $e) {
            self::avisar($ctx, 'error', self::mensaje($e, 'No se guardaron los artículos del proveedor.'));
            $volverEditar = true;
        }
        self::volver($ctx, $id, 'articulos', $volverEditar);
    }

    public static function eliminar(Router $router): void
    {
        $ctx = self::post();
        $id = self::idOpcional($_POST['id'] ?? '');
        $r = $id === null ? ['ok' => false, 'mensaje' => 'El proveedor no es válido.'] : self::servicio()->eliminar($ctx, $id);
        try {
            AdministracionAlmacenes::exigirResultado($r);
            self::avisar($ctx, 'exito', 'Proveedor eliminado.');
            self::redirigir(self::base($ctx));
        } catch (\Throwable $e) {
            self::avisar($ctx, 'error', self::mensaje($e, 'No se eliminó el proveedor.'));
            $id === null ? self::redirigir(self::base($ctx)) : self::volver($ctx, $id, 'datos');
        }
    }

    public static function base(string $ctx): string { return AdminAlmacenesController::base($ctx) . '/proveedores'; }

    /** Todas las escrituras: método, permiso de edición del ámbito y CSRF de esta superficie. */
    private static function post(): string
    {
        $ctx = ContextoNegocio::slug();
        ContextoNegocio::exigir('inventario', 'editar', $ctx);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); exit; }
        if (!is_string($_POST['admin_csrf'] ?? null) || !hash_equals(self::csrf($ctx), trim($_POST['admin_csrf']))) {
            http_response_code(403);
            echo 'La sesión del formulario venció. Recarga la página.';
            exit;
        }
        return $ctx;
    }

    private static function render(string $vista, string $ctx, array $datos): void
    {
        AdminController::render($vista, $datos + [
            'activeModule' => 'proveedores', 'topbarTitle' => 'Proveedores', 'topbarSection' => 'Almacenes · Proveedores',
            'ctx' => $ctx, 'nombreContexto' => Contextos::NOMBRES[$ctx], 'base' => self::base($ctx), 'baseAlmacenes' => AdminAlmacenesController::base($ctx),
            'puedeEditar' => Auth::puede('inventario', 'editar', $ctx), 'csrf' => self::csrf($ctx),
            'styles' => ['/build/css/admin/inventario.css'], 'scripts' => ['/build/js/admin/inventario.js'],
        ]);
    }

    private static function servicio(): DirectorioProveedores
    {
        return new DirectorioProveedores(ContextoNegocio::db(), ContextoNegocio::actorId(), ContextoNegocio::nivelAuth());
    }

    /** El mismo secreto CSRF del panel, vinculado a esta superficie y su ámbito. */
    private static function csrf(string $ctx): string { return hash_hmac('sha256', 'proveedores:' . $ctx, AdminCsrfService::token()); }

    private static function flash(string $ctx): array
    {
        $f = $_SESSION['_proveedores_flash'][$ctx] ?? [];
        unset($_SESSION['_proveedores_flash'][$ctx]);
        return $f;
    }

    private static function avisar(string $ctx, string $tipo, string $mensaje): void { $_SESSION['_proveedores_flash'][$ctx][$tipo][] = $mensaje; }

    private static function volver(string $ctx, int $id, string $vista = 'articulos', bool $editar = false): void
    {
        $query = ['id' => $id, 'vista' => $vista];
        if ($editar) $query['editar'] = 1;
        self::redirigir(self::base($ctx) . '/ficha?' . http_build_query($query));
    }

    private static function redirigir(string $url): void { header('Location: ' . $url, true, 303); exit; }

    private static function idOpcional(mixed $v): ?int
    {
        if ($v === '' || $v === null) return null;
        if (!is_string($v) || !preg_match('/^[1-9][0-9]{0,17}$/D', $v)) return null;
        return (int)$v;
    }

    private static function estado(\Throwable $e): int
    {
        if (!$e instanceof InventarioError) return 500;
        return in_array($e->codigo, ['PERMISO_DENEGADO', 'AMBITO_DENEGADO'], true) ? 403 : ($e->codigo === 'RECURSO_NO_ENCONTRADO' ? 404 : 422);
    }

    private static function mensaje(\Throwable $e, string $respaldo): string
    {
        if ($e instanceof InventarioError) return $e->getMessage();
        error_log('AdminProveedoresController - ' . $e->getMessage());
        return $respaldo;
    }
}
