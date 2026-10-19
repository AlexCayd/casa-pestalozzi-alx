<?php
/**
 * Asistente IA del panel: una conversación con los datos del restaurante.
 *
 * Hoy sólo existe la VISTA. La respuesta la dará un flujo de n8n que todavía no
 * está construido, y por eso este controlador no consulta nada ni hace de
 * proxy: entrega la URL del webhook (si la hay) y el navegador le habla
 * directamente. Sin URL, el front no toca la red y lo dice en pantalla en vez
 * de inventar una respuesta.
 *
 * El contrato con n8n está en docs/config.md («Asistente IA»).
 */

namespace Controllers;

use MVC\Router;

class AdminIaController
{
    private const MODULE_CSS = '/build/css/admin/ia.css';
    private const MODULE_JS = '/build/js/admin/ia.js';

    /** Tope de caracteres de una pregunta. Lo aplica el textarea y lo repite el JS. */
    private const MAX_CARACTERES = 4000;

    public static function index(Router $router): void
    {
        AdminController::render('ia/index', [
            'activeModule' => 'ia',
            'title' => 'Asistente IA',
            'iaEndpoint' => self::endpoint(),
            'iaNombre' => self::nombreDePila(),
            'iaSaludo' => self::saludo((int) date('G')),
            'iaMaxCaracteres' => self::MAX_CARACTERES,
            'iaSugerencias' => self::sugerencias(),
            'styles' => [self::MODULE_CSS],
            'scripts' => [self::MODULE_JS],
        ]);
    }

    /**
     * URL del webhook de n8n, o cadena vacía si no está configurada.
     *
     * Se valida aquí y no en el front: una URL mal escrita en .env haría que el
     * panel prometiera una conexión que nunca va a responder. Sólo http(s), y
     * nada de credenciales incrustadas (`https://user:pass@…`), que acabarían
     * impresas en el HTML de la página.
     */
    private static function endpoint(): string
    {
        $url = $_ENV['IA_N8N_WEBHOOK_URL'] ?? getenv('IA_N8N_WEBHOOK_URL');
        $url = is_string($url) ? trim($url) : '';
        if ($url === '') {
            return '';
        }

        $partes = parse_url($url);
        $esquema = strtolower((string) ($partes['scheme'] ?? ''));
        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array($esquema, ['http', 'https'], true)
            || isset($partes['user'])
            || isset($partes['pass'])
        ) {
            error_log('AdminIaController: IA_N8N_WEBHOOK_URL no es una URL http(s) válida; el asistente queda sin conexión.');
            return '';
        }

        return $url;
    }

    /** Primer nombre de quien tiene la sesión, para el saludo. */
    private static function nombreDePila(): string
    {
        $nombre = trim((string) ($_SESSION['nombre'] ?? ''));
        if ($nombre === '') {
            return '';
        }
        $palabras = preg_split('/\s+/u', $nombre) ?: [];

        return (string) ($palabras[0] ?? '');
    }

    /**
     * Saludo por franja horaria, con el reloj del servidor.
     *
     * El servidor corre en la hora del restaurante (includes/app.php), que es la
     * misma del administrador: no hace falta esperar al JS para pintarlo.
     */
    private static function saludo(int $hora): string
    {
        if ($hora >= 5 && $hora < 12) {
            return 'Buenos días';
        }
        if ($hora >= 12 && $hora < 19) {
            return 'Buenas tardes';
        }

        return 'Buenas noches';
    }

    /**
     * Preguntas de arranque, agrupadas por la parte del negocio que tocan.
     *
     * Dos por tema y no más: el estado vacío tiene que poder leerse de un
     * vistazo. Son preguntas que el restaurante se hace de verdad, con su
     * vocabulario —capuchinos, mínimo de inventario, el sábado—, porque una
     * sugerencia genérica («Analiza mis ventas») no enseña qué se puede pedir.
     * El `icono` es la clave del catálogo del módulo correspondiente, para que
     * el tema se reconozca con la misma forma que tiene en el sidebar.
     *
     * @return array<int, array{tema: string, icono: string, preguntas: string[]}>
     */
    private static function sugerencias(): array
    {
        return [
            [
                'tema' => 'Ventas',
                'icono' => 'analytics',
                'preguntas' => [
                    '¿Cuántos capuchinos se vendieron ayer?',
                    '¿Cómo van las ventas de esta semana contra la anterior?',
                ],
            ],
            [
                'tema' => 'Inventario',
                'icono' => 'inventario',
                'preguntas' => [
                    '¿Qué ingredientes están por debajo del mínimo?',
                    '¿Qué insumos conviene reabastecer antes del fin de semana?',
                ],
            ],
            [
                'tema' => 'Reservaciones',
                'icono' => 'reservations',
                'preguntas' => [
                    '¿Cuántas reservaciones hay el sábado?',
                    '¿Hay grupos grandes reservados esta semana?',
                ],
            ],
            [
                'tema' => 'Menú',
                'icono' => 'menu',
                'preguntas' => [
                    '¿Cuál fue el platillo más vendido esta semana?',
                    '¿Qué platillos dejan menos margen?',
                ],
            ],
        ];
    }
}
