<?php

/**
 * Módulo de Catering del panel: bandeja de solicitudes de cotización.
 *
 * No hay alta manual — las solicitudes nacen en la landing—, así que el módulo
 * es lectura, cambio de estado y comentario interno. Mismo POST-redirect-GET
 * con `?aviso=` que AdminCataController.
 */

namespace Controllers;

use Model\CateringSolicitud;
use MVC\Router;
use Services\AdminCsrfService;
use Services\CateringService;

class AdminCateringController
{
    private const RUTA = '/admin/catering';
    private const CSS = '/build/css/admin/catering.css';
    private const JS = '/build/js/admin/catering.js';

    private const AVISOS = [
        'estado-actualizado' => ['exito', 'Estado de la solicitud actualizado'],
        'comentario-guardado' => ['exito', 'Comentario guardado'],
        'no-existe' => ['error', 'La solicitud no existe'],
        'id-invalido' => ['error', 'Identificador no válido'],
        'sesion-expirada' => ['error', 'La sesión expiró. Vuelve a intentarlo.'],
        'error-estado' => ['error', 'No se pudo actualizar la solicitud'],
        'error-comentario' => ['error', 'No se pudo guardar el comentario'],
    ];

    public static function index(Router $router): void
    {
        // Sin filtro explícito se muestran las que siguen vivas: la bandeja es
        // una lista de pendientes, no un archivo histórico.
        $estado = isset($_GET['estado']) ? (string)$_GET['estado'] : 'abiertas';
        $busqueda = (string)($_GET['q'] ?? '');

        self::render('catering/index', [
            'title' => 'Catering',
            'topbarSection' => 'Catering',
            'solicitudes' => CateringService::bandeja($estado, $busqueda),
            'conteo' => CateringService::conteoPorEstado(),
            'estadoActivo' => $estado,
            'busqueda' => $busqueda,
            'estados' => CateringSolicitud::ESTADOS,
            'adminCsrfToken' => AdminCsrfService::token(),
            'alertas' => self::avisos(),
        ]);
    }

    public static function show(Router $router): void
    {
        $solicitud = CateringSolicitud::find(self::validarId($_GET['id'] ?? null));

        if (!$solicitud) {
            self::redirect(self::RUTA . '?aviso=no-existe');
        }

        self::render('catering/show', [
            'title' => 'Solicitud de ' . $solicitud->nombre,
            'topbarSection' => 'Catering / ' . $solicitud->nombre,
            'solicitud' => $solicitud,
            'estados' => CateringSolicitud::ESTADOS,
            'adminCsrfToken' => AdminCsrfService::token(),
            'alertas' => self::avisos(),
        ]);
    }

    public static function estado(Router $router): void
    {
        self::exigirPost();

        $id = self::validarId($_POST['id'] ?? null);
        // Volver al detalle o a la bandeja según desde dónde se cambió.
        $destino = ((string)($_POST['origen'] ?? '') === 'detalle')
            ? self::RUTA . '/detalle?id=' . $id . '&aviso='
            : self::RUTA . '?aviso=';

        if (!AdminCsrfService::validar($_POST['admin_csrf'] ?? null)) {
            self::redirect($destino . 'sesion-expirada');
        }

        $resultado = CateringService::cambiarEstado($id, (string)($_POST['estado'] ?? ''));
        self::redirect($destino . ($resultado['ok'] ? 'estado-actualizado' : 'error-estado'));
    }

    public static function comentario(Router $router): void
    {
        self::exigirPost();

        $id = self::validarId($_POST['id'] ?? null);
        $destino = self::RUTA . '/detalle?id=' . $id . '&aviso=';

        if (!AdminCsrfService::validar($_POST['admin_csrf'] ?? null)) {
            self::redirect($destino . 'sesion-expirada');
        }

        $resultado = CateringService::guardarComentario($id, (string)($_POST['comentario_admin'] ?? ''));
        self::redirect($destino . ($resultado['ok'] ? 'comentario-guardado' : 'error-comentario'));
    }

    /** @return array<string, array<int, string>> */
    private static function avisos(): array
    {
        $clave = (string)($_GET['aviso'] ?? '');

        if (!isset(self::AVISOS[$clave])) {
            return [];
        }

        [$tipo, $mensaje] = self::AVISOS[$clave];
        return [$tipo => [$mensaje]];
    }

    private static function render(string $vista, array $datos = []): void
    {
        AdminController::render($vista, array_merge([
            'activeModule' => 'catering',
            'styles' => [self::CSS],
            'scripts' => [self::JS],
        ], $datos));
    }

    private static function exigirPost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::RUTA);
        }
    }

    private static function validarId(mixed $id): int
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (!$id) {
            self::redirect(self::RUTA . '?aviso=id-invalido');
        }

        return (int)$id;
    }

    /** `never` es lo que permite dar por buenas las comprobaciones previas. */
    private static function redirect(string $url): never
    {
        header('Location: ' . $url, true, 302);
        exit;
    }
}
