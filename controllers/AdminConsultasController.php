<?php

namespace Controllers;

use MVC\Router;
use Services\Analytics\ConsultasRapidas;
use Services\Security\ContextoNegocio;

/**
 * El motor de consultas rápidas, que es lo que responde en el Asistente IA
 * (/admin/ia). Sólo lectura: GET y JSON. /admin/* ya exige rol admin por
 * prefijo en Auth::proteger(), así que no hay guardia propia que añadir.
 */
class AdminConsultasController
{
    /**
     * GET /admin/api/consultas
     *   ?intencion=<INTENCIONES>&id=<entidad>&opcion=<variante>&periodo=<clave>
     *   ?q=<frase>&ambito=ventas|productos|todo&periodo=<clave>
     *
     * La estructurada es la que manda el Asistente —su compositor arma cada
     * pregunta con huecos elegidos de listas— y con ella `q` se ignora: el
     * resultado no depende de que el intérprete adivine una frase. La frase
     * se conserva para lo guardado antes de retirar el texto libre.
     */
    public static function consultar(Router $router): void
    {
        self::cabeceras();

        $periodo = self::texto('periodo');
        $intencion = self::texto('intencion');

        try {
            self::exigirConsultaNueva();
            if ($intencion !== '') {
                $respuesta = ConsultasRapidas::responderEstructurada(
                    $intencion,
                    self::entero('id'),
                    self::entero('opcion'),
                    $periodo !== '' ? $periodo : null
                );
                if (!($respuesta['ok'] ?? false)) {
                    http_response_code(400);
                }
            } else {
                $respuesta = ConsultasRapidas::responder(
                    self::texto('q'),
                    self::texto('ambito') ?: 'todo',
                    $periodo !== '' ? $periodo : null
                );
            }
            echo json_encode($respuesta + ['periodos' => ConsultasRapidas::PERIODOS], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            self::fallo('consultar', $e, 'No fue posible responder la consulta.');
        }
    }

    /**
     * GET /admin/api/consultas/catalogo — lo que se puede elegir en un hueco
     * de entidad: platillos y categorías (catálogo `ventas`) e ingredientes.
     */
    public static function catalogo(Router $router): void
    {
        self::cabeceras();
        try {
            self::exigirConsultaNueva();
            echo json_encode(['ok' => true] + ConsultasRapidas::catalogo(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            self::fallo('catalogo', $e, 'No fue posible cargar la lista.');
        }
    }

    /**
     * GET /admin/api/consultas/capacidades — todo lo que el motor sabe
     * responder, con la plantilla de cada pregunta y un ejemplo real.
     */
    public static function capacidades(Router $router): void
    {
        self::cabeceras();
        try {
            self::exigirConsultaNueva();
            echo json_encode(['ok' => true] + ConsultasRapidas::capacidades(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            self::fallo('capacidades', $e, 'No fue posible cargar las consultas disponibles.');
        }
    }

    private static function cabeceras(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    private static function exigirConsultaNueva(): void
    {
        if (!ContextoNegocio::nuevo()) return;
        $ctx = ContextoNegocio::slug();
        if ($ctx !== 'restaurante') throw new \Services\Inventory\InventarioError('AMBITO_NO_DISPONIBLE', 'Las consultas del POS están disponibles para Restaurante.');
        foreach (['analytics','ia','menu','inventario','tickets'] as $modulo) {
            try { ContextoNegocio::exigir($modulo, 'ver', $ctx); return; }
            catch (\Services\Inventory\InventarioError $e) { if (!in_array($e->codigo, ['PERMISO_DENEGADO','MODULO_NO_DISPONIBLE'], true)) throw $e; }
        }
        throw new \Services\Inventory\InventarioError('PERMISO_DENEGADO', 'No tienes permiso para consultar datos del restaurante.');
    }

    private static function texto(string $clave): string
    {
        return is_string($_GET[$clave] ?? null) ? trim((string) $_GET[$clave]) : '';
    }

    private static function entero(string $clave): ?int
    {
        $v = filter_var($_GET[$clave] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $v === false ? null : $v;
    }

    private static function fallo(string $metodo, \Throwable $e, string $mensaje): void
    {
        error_log('AdminConsultasController::' . $metodo . ' - ' . $e->getMessage());
        http_response_code($e instanceof \Services\Inventory\InventarioError && in_array($e->codigo, ['PERMISO_DENEGADO','AMBITO_DENEGADO','AMBITO_NO_DISPONIBLE'], true) ? 403 : 500);
        echo json_encode(['ok' => false, 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE);
    }
}
