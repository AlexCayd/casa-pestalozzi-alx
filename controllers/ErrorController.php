<?php
namespace Controllers;

use MVC\Router;

/**
 * Respuesta para una URL que no está en el mapa de rutas.
 *
 * Se registra con Router::notFound() en public/index.php, así que cubre los
 * tres verbos. Antes el router hacía `echo "Página No Encontrada..."`: texto
 * plano, sin charset y con 200 OK.
 *
 * Dos matices:
 *
 * - /api/* y /admin/api/* salen en JSON. CLAUDE.md lo dice: una ruta /api/ que
 *   no esté en ninguna allowlist de Auth queda pública, así que la petición
 *   llega hasta aquí; devolverle el documento HTML a un fetch() lo hace fallar
 *   al parsear y el error que enseña no habla de la ruta.
 * - Un admin autenticado que teclee mal una URL bajo /admin/ ve esta misma
 *   página pública. Auth::proteger ya rebotó a /login o a su destino por rol a
 *   todo el que no sea admin, así que lo único que llega ahí es una errata con
 *   sesión abierta, y el enlace a / lo saca. Un 404 propio del panel sería una
 *   segunda página en el otro sistema visual.
 */
class ErrorController {

    public static function noEncontrado(Router $router, string $url = '') {
        http_response_code(404);

        $url = $url !== '' ? $url : (string)($_SERVER['PATH_INFO'] ?? '/');

        if (str_starts_with($url, '/api/') || str_starts_with($url, '/admin/api/')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'codigo' => 'RUTA_NO_ENCONTRADA']);
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        include_once __DIR__ . '/../views/errors/404.php';
    }
}
