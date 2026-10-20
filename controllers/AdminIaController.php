<?php
/**
 * Asistente IA del panel: una conversación con los datos del restaurante.
 *
 * Quien responde es el motor de consultas rápidas (GET /admin/api/consultas →
 * Services\Analytics\ConsultasRapidas). No hay modelo de lenguaje detrás: la
 * conversación es la forma, y cada respuesta sale de una consulta a la base.
 * La integración con n8n que se previó al principio se descartó.
 *
 * Tampoco hay texto libre: el cliente lo retiró porque dejaba preguntar cosas
 * que el motor no sabe contestar. Cada pregunta es una de las preguntas base
 * del motor, armada en el compositor con sus variables como huecos que se
 * eligen de listas («Ventas de [Cappuccino ▾] · [Ayer ▾]»).
 *
 * Este controlador sólo pinta la pantalla: el saludo, el catálogo de lo que se
 * puede preguntar —que es el del propio motor, ConsultasRapidas::capacidades()—
 * con el borrador de cada pregunta, y la ruta de la API. Las preguntas las hace
 * el navegador.
 */

namespace Controllers;

use MVC\Router;
use Services\Analytics\ConsultasRapidas;

class AdminIaController
{
    private const MODULE_CSS = '/build/css/admin/ia.css';
    private const MODULE_JS = '/build/js/admin/ia.js';

    /** La ruta que contesta. Vive bajo /admin/, así que exige rol admin por prefijo. */
    private const API = '/admin/api/consultas';

    /**
     * Conversaciones que guarda el navegador. Al abrir una más se borra la más
     * antigua. Lo leen ia.js (data-ia-max-conversaciones) y la línea del pie
     * del historial, para que el número que se dice sea el que se aplica.
     */
    private const MAX_CONVERSACIONES = 10;

    /**
     * Cómo se agrupan en pantalla las intenciones del motor, y en qué orden:
     * por familia de pregunta y, dentro de cada una, lo que más se pregunta
     * primero. Es sólo presentación; qué intenciones existen lo dice el motor.
     * La primera de la primera familia es la pregunta con la que nace el
     * compositor.
     */
    private const TEMAS = [
        ['tema' => 'Ventas', 'intenciones' => ['ventas_total', 'ventas_producto', 'ventas_categoria', 'top', 'ticket_promedio', 'sin_venta']],
        ['tema' => 'Equipo y servicio', 'intenciones' => ['meseros', 'horas_pico', 'cancelaciones']],
        ['tema' => 'Inventario', 'intenciones' => ['stock', 'consumo', 'bajo_stock']],
    ];

    /**
     * Un icono del catálogo por pregunta, para reconocerla sin leerla: el
     * mismo dibujo que su módulo en el sidebar cuando lo hay.
     */
    private const ICONOS = [
        'ventas_total' => 'finanzas',
        'ventas_producto' => 'products',
        'ventas_categoria' => 'categories',
        'top' => 'estrella',
        'ticket_promedio' => 'tickets',
        'sin_venta' => 'menu',
        'meseros' => 'grupo',
        'horas_pico' => 'reloj',
        'cancelaciones' => 'error',
        'stock' => 'inventario',
        'consumo' => 'gota',
        'bajo_stock' => 'alerta',
    ];

    public static function index(Router $router): void
    {
        $catalogo = self::catalogo();

        AdminController::render('ia/index', [
            'activeModule' => 'ia',
            'title' => 'Asistente IA',
            'iaApi' => self::API,
            'iaNombre' => self::nombreDePila(),
            'iaSaludo' => self::saludo((int) date('G')),
            'iaMaxConversaciones' => self::MAX_CONVERSACIONES,
            'iaCatalogo' => $catalogo['grupos'],
            'iaOpcionesPeriodo' => $catalogo['opcionesPeriodo'],
            'styles' => [self::MODULE_CSS],
            'scripts' => [self::MODULE_JS],
        ]);
    }

    /**
     * El catálogo COMPLETO de lo que el motor responde, agrupado por familia
     * de pregunta, y los periodos que entiende, para el hueco de periodo del
     * compositor.
     *
     * @return array{grupos: list<array<string, mixed>>, opcionesPeriodo: list<array<string, mixed>>}
     */
    private static function catalogo(): array
    {
        try {
            $capacidades = ConsultasRapidas::capacidades();
        } catch (\Throwable $e) {
            error_log('AdminIaController::catalogo - ' . $e->getMessage());

            return ['grupos' => [], 'opcionesPeriodo' => []];
        }

        $opcionesPeriodo = array_values(array_filter(
            is_array($capacidades['periodos'] ?? null) ? $capacidades['periodos'] : [],
            static fn ($p): bool => is_array($p) && is_string($p['valor'] ?? null) && is_string($p['etiqueta'] ?? null)
        ));
        $etiquetaPeriodo = [];
        foreach ($opcionesPeriodo as $p) {
            $etiquetaPeriodo[$p['valor']] = $p['etiqueta'];
        }

        $porIntencion = [];
        foreach ($capacidades['intenciones'] ?? [] as $intencion) {
            if (is_array($intencion) && is_string($intencion['intencion'] ?? null)) {
                $porIntencion[$intencion['intencion']] = $intencion;
            }
        }

        $fila = static fn (array $i): array => self::fila($i, $etiquetaPeriodo);

        $grupos = [];
        foreach (self::TEMAS as $tema) {
            $consultas = [];
            foreach ($tema['intenciones'] as $clave) {
                if (isset($porIntencion[$clave])) {
                    $consultas[] = $fila($porIntencion[$clave]);
                    unset($porIntencion[$clave]);
                }
            }
            if ($consultas !== []) {
                $grupos[] = ['tema' => $tema['tema'], 'consultas' => $consultas];
            }
        }

        // Una intención que el motor aprenda y que aquí no tenga tema se
        // enseña igual: el catálogo es la lista entera, no una selección.
        if ($porIntencion !== []) {
            $grupos[] = ['tema' => 'Más preguntas', 'consultas' => array_map($fila, array_values($porIntencion))];
        }

        return ['grupos' => $grupos, 'opcionesPeriodo' => $opcionesPeriodo];
    }

    /**
     * Una pregunta base: su título, su icono y los BORRADORES que se cargan en
     * el compositor al elegirla —la plantilla del motor con los valores de un
     * ejemplo ya puestos («Ventas de [Cappuccino] · [Ayer]») y la consulta
     * estructurada que dispararía—. Elegirla no consulta nada: se manda al
     * enviar, con lo que se haya cambiado.
     *
     * Hay un borrador por cada ejemplo del motor (varias cosas y periodos
     * reales) y `ia.js` arma uno al azar cada vez: con un solo ejemplo, la
     * pregunta salía siempre con el mismo platillo y parecía que no sabía de
     * otra cosa. `borrador` es el de la primera impresión, también al azar.
     *
     * @param array<string, mixed>  $intencion
     * @param array<string, string> $etiquetaPeriodo  clave → etiqueta
     * @return array{intencion: string, titulo: string, descripcion: string, icono: string, borrador: array, borradores: list<array>}
     */
    private static function fila(array $intencion, array $etiquetaPeriodo): array
    {
        $clave = (string) $intencion['intencion'];
        $ejemplos = array_values(array_filter(
            is_array($intencion['ejemplos'] ?? null) ? $intencion['ejemplos'] : [],
            'is_array'
        ));
        if ($ejemplos === [] && is_array($intencion['ejemplo'] ?? null)) {
            $ejemplos = [$intencion['ejemplo']];
        }
        // Sin ningún ejemplo (una base sin datos) queda un borrador con el
        // hueco vacío, que hay que llenar antes de enviar.
        $borradores = $ejemplos === []
            ? [self::borradorDe($clave, [], $intencion, $etiquetaPeriodo)]
            : array_map(static fn (array $e): array => self::borradorDe($clave, $e, $intencion, $etiquetaPeriodo), $ejemplos);

        return [
            'intencion' => $clave,
            'titulo' => (string) ($intencion['titulo'] ?? ''),
            'descripcion' => (string) ($intencion['descripcion'] ?? ''),
            'icono' => self::ICONOS[$clave] ?? 'ia',
            'borrador' => $borradores[array_rand($borradores)],
            'borradores' => $borradores,
        ];
    }

    /**
     * El borrador de UN ejemplo: la plantilla de la pregunta con sus huecos
     * llenos con los valores del ejemplo.
     *
     * @param array<string, mixed>  $ejemplo
     * @param array<string, mixed>  $intencion
     * @param array<string, string> $etiquetaPeriodo
     * @return array{consulta: array<string, mixed>, partes: list<array<string, mixed>>}
     */
    private static function borradorDe(string $clave, array $ejemplo, array $intencion, array $etiquetaPeriodo): array
    {
        $consulta = is_array($ejemplo['consulta'] ?? null)
            ? $ejemplo['consulta']
            : ['intencion' => $clave, 'id' => null, 'opcion' => null, 'periodo' => 'hoy'];

        // El nombre de la cosa del ejemplo es su primera parte variable:
        // ConsultasRapidas::partes() pone la cosa antes que el periodo. Las
        // ventas totales no tienen cosa (su primer hueco es el periodo).
        $nombre = null;
        if ($clave !== 'ventas_total') {
            foreach (is_array($ejemplo['partes'] ?? null) ? $ejemplo['partes'] : [] as $parte) {
                if (!empty($parte['hueco'])) {
                    $nombre = (string) $parte['texto'];
                    break;
                }
            }
        }

        $partes = [];
        foreach (is_array($intencion['plantilla'] ?? null) ? $intencion['plantilla'] : [] as $p) {
            if (($p['tipo'] ?? '') !== 'hueco') {
                $partes[] = ['tipo' => 'texto', 'texto' => (string) ($p['texto'] ?? '')];
                continue;
            }
            if (($p['campo'] ?? '') === 'periodo') {
                $periodo = (string) ($consulta['periodo'] ?? 'hoy');
                $partes[] = [
                    'tipo' => 'hueco',
                    'campo' => 'periodo',
                    'rotulo' => (string) ($p['rotulo'] ?? 'Periodo'),
                    'valor' => $periodo,
                    'etiqueta' => $etiquetaPeriodo[$periodo] ?? $periodo,
                ];
                continue;
            }
            // Hueco de cosa. El valor tiene la forma de las opciones del
            // catálogo (`ventas_producto:197`, `stock:6`, `ventas_total`) para
            // que el selector marque la vigente: los ingredientes van como
            // `stock:` aunque la pregunta sea de consumo, porque es la lista de
            // la que salen. Sin ejemplo (una base sin datos), el hueco nace
            // vacío y hay que elegir antes de enviar.
            $catalogo = (string) ($p['catalogo'] ?? 'ventas');
            $total = $clave === 'ventas_total';
            $id = $consulta['id'] ?? null;
            $lleno = $total || ($id !== null && $nombre !== null);
            $prefijo = $catalogo === 'ingredientes' ? 'stock' : $clave;
            $hueco = [
                'tipo' => 'hueco',
                'campo' => 'entidad',
                'rotulo' => (string) ($p['rotulo'] ?? ''),
                'catalogo' => $catalogo,
                'valor' => $lleno ? ($total ? 'ventas_total' : $prefijo . ':' . (int) $id) : '',
                'etiqueta' => $lleno ? ($total ? 'todo el restaurante' : $nombre) : ($catalogo === 'ingredientes' ? 'elige un ingrediente' : 'elige un platillo'),
            ];
            if (is_string($p['intencion'] ?? null)) {
                $hueco['intencion'] = $p['intencion'];
            }
            $partes[] = $hueco;
        }

        return ['consulta' => $consulta, 'partes' => $partes];
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
}
