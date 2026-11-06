<?php
declare(strict_types=1);

namespace Controllers;

use MVC\Router;
use Services\Menu\VitrinaCaja;
use Services\Security\AdminCsrfService;
use Services\Security\ContextoNegocio;

/**
 * /admin/panaderia/menu/vitrina — cómo se vende cada pan en la caja: sus
 * opciones y la pieza de vitrina que descuenta. Página propia de Panadería
 * dentro de Menú; las reglas viven en Services\Menu\VitrinaCaja.
 */
final class AdminVitrinaController
{
    public static function index(Router $router): void
    {
        try {
            $vitrina = VitrinaCaja::vista();
        } catch (\Throwable $e) {
            http_response_code(403);
            header('Location: /admin/panaderia');
            exit;
        }
        AdminController::render('vitrina/index', [
            'title' => 'Vitrina de la caja',
            'topbarSection' => 'Menú / Vitrina de la caja',
            'activeModule' => 'menu',
            'vitrinaJson' => json_encode($vitrina, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'vitrinaPuedeEditar' => (bool)$vitrina['puedeEditar'],
            'adminCsrfToken' => AdminCsrfService::token(),
            'styles' => ['/build/css/admin/vitrina.css'],
            'scripts' => ['/build/js/admin/vitrina.js'],
        ]);
    }

    /** POST JSON: guarda opciones y piezas de un pan en una sola transacción. */
    public static function guardar(Router $router): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $d = json_decode((string)file_get_contents('php://input'), true);
        $d = is_array($d) ? $d : [];
        if (!AdminCsrfService::validar(is_string($d['admin_csrf'] ?? null) ? $d['admin_csrf'] : null)) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'mensaje' => 'La sesión venció. Recarga la página; tus cambios siguen en pantalla.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $oferta = filter_var($d['oferta_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $pieza = array_key_exists('pieza', $d) && is_array($d['pieza']) ? $d['pieza'] : null;
        $grupos = array_key_exists('grupos', $d) && is_array($d['grupos']) ? $d['grupos'] : null;
        $r = $oferta ? VitrinaCaja::guardar((int)$oferta, $pieza, $grupos) : ['ok' => false, 'mensaje' => 'El pan no es válido.'];
        if (empty($r['ok'])) http_response_code(in_array($r['codigo'] ?? '', ['PERMISO_DENEGADO', 'AMBITO_DENEGADO', 'NIVEL_AUTH_REQUERIDO'], true) ? 403 : 422);
        echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
