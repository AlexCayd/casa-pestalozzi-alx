<?php

/**
 * Las cuatro estaciones de producción (`areas_produccion`) y su color.
 *
 * El color de una estación llena la barra superior de su tablero KDS y tiñe
 * su fondo (los estados de las bandas van en rojo, amarillo y verde, aparte),
 * pinta su tarjeta en el
 * selector de estación y en Producción del panel, tiñe su fila en Impresoras y
 * el encabezado de su grupo al confirmar una comanda en el POS. Se elige en
 * Configuración → Colores de las áreas, y SÓLO entre los diez de la paleta
 * funcional: es dato de negocio, pero un hex libre rompería la regla de que
 * todo color que no es de marca sale de esa paleta (CLAUDE.md), que además está
 * validada para daltonismo. Dos estaciones no pueden compartir color: es lo
 * único que las distingue en Impresoras y en el POS.
 *
 * Antes cada tablero llevaba sus colores escritos a mano (AreaController y
 * AdminAreaController) y el del piso ni siquiera eran los de la base: café,
 * mostaza, ladrillo y azul marino de una versión vieja. Ahora los dos leen de
 * aquí, que lee de la base, y lo de abajo es sólo el respaldo si la base no
 * responde.
 */

namespace Services\Configuration;

use Model\ActiveRecord;
use Services\Security\ContextoNegocio;

class AreasProduccion
{
    /**
     * Espejo en PHP de la paleta funcional (--c-* en _reset.scss y --admin-c-*
     * en _globals.scss), en orden de matiz para que la fila de muestras se lea
     * como un círculo cromático y no como una lista. La base guarda el hex
     * porque es lo que consumen el KDS, el POS y las impresoras;
     * scripts/tests/run-areas-produccion.php falla si este espejo se separa de
     * los tokens.
     */
    public const PALETA = [
        'ambar'    => ['nombre' => 'Ámbar',    'hex' => '#f5b400'],
        'naranja'  => ['nombre' => 'Naranja',  'hex' => '#fc6722'],
        'rojo'     => ['nombre' => 'Rojo',     'hex' => '#e51022'],
        'rosa'     => ['nombre' => 'Rosa',     'hex' => '#ea075a'],
        'magenta'  => ['nombre' => 'Magenta',  'hex' => '#aa2296'],
        'indigo'   => ['nombre' => 'Índigo',   'hex' => '#4267ac'],
        'azul'     => ['nombre' => 'Azul',     'hex' => '#3a86ff'],
        'turquesa' => ['nombre' => 'Turquesa', 'hex' => '#46bdc6'],
        'verde'    => ['nombre' => 'Verde',    'hex' => '#34a853'],
        'lima'     => ['nombre' => 'Lima',     'hex' => '#8ac926'],
    ];

    /**
     * Los dos colores con que el KDS marca la hora de una comanda atrasada (a
     * los 5 y a los 10 minutos). Se pueden elegir —el color de la estación sólo
     * va en la barra superior del tablero, fuera de la comanda y lejos de la
     * hora—, pero la pantalla lo avisa: si algún día el color de estación vuelve
     * a pintar algo dentro de la comanda, chocarían.
     */
    public const DE_ESPERA = ['ambar', 'rojo'];

    /**
     * Los colores de los estados en el lomo de las bandas del KDS: Enviados,
     * En preparación y Listos, en ese orden (src/scss/area/_area.scss). Se
     * pueden elegir para una estación —la estación sólo llena la barra—, pero
     * esa estación compartirá color con una banda, y la pantalla lo avisa.
     */
    public const DE_ESTADO = ['rojo', 'ambar', 'verde'];

    /** Respaldo si la base no responde: los valores de deploy.sql. */
    private const RESPALDO = [
        'cafe'   => ['id' => 1, 'nombre' => 'Barra de Café',    'color' => '#46bdc6'],
        'jugos'  => ['id' => 2, 'nombre' => 'Barra de Jugos',   'color' => '#f5b400'],
        'cocina' => ['id' => 3, 'nombre' => 'Cocina',           'color' => '#e51022'],
        'horno'  => ['id' => 4, 'nombre' => 'Horno Napolitano', 'color' => '#4267ac'],
    ];

    /** El nombre corto de cada estación, para pestañas y tarjetas. */
    private const ETIQUETAS = ['cafe' => 'Café', 'jugos' => 'Jugos', 'cocina' => 'Cocina', 'horno' => 'Horno'];

    private static ?array $cache = null;

    /**
     * Las estaciones por slug, en el orden de la base:
     * [slug => ['id', 'slug', 'nombre', 'label', 'color', 'clave', 'path']].
     * `color` es siempre un hex válido (si el de la base no lo es, el de
     * respaldo); `clave` es su color de la paleta, o null si está fuera de ella.
     */
    public static function todas(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $filas = [];
        try {
            $db = ActiveRecord::getDB();
            $unidad = self::unidadRestaurante();
            $sql = $unidad === null
                ? 'SELECT id, nombre, slug, color FROM areas_produccion ORDER BY id ASC'
                : 'SELECT id, nombre, slug, color FROM areas_produccion WHERE unidad_id = ' . $unidad . ' ORDER BY orden, id';
            $res = $db ? $db->query($sql) : false;
            while ($res && ($fila = $res->fetch_assoc())) {
                $filas[] = $fila;
            }
            if ($res) {
                $res->free();
            }
        } catch (\Throwable $e) {
            error_log('AreasProduccion::todas - ' . $e->getMessage());
        }

        if ($filas === []) {
            foreach (self::RESPALDO as $slug => $area) {
                $filas[] = $area + ['slug' => $slug];
            }
        }

        $areas = [];
        foreach ($filas as $fila) {
            $slug = (string) $fila['slug'];
            $color = self::normalizar($fila['color'] ?? null)
                ?? (self::RESPALDO[$slug]['color'] ?? self::PALETA['indigo']['hex']);
            $areas[$slug] = [
                'id' => (int) $fila['id'],
                'slug' => $slug,
                'nombre' => (string) $fila['nombre'],
                'label' => self::ETIQUETAS[$slug] ?? (string) $fila['nombre'],
                'color' => $color,
                'clave' => self::clave($color),
                'path' => '/area/' . $slug,
            ];
        }

        return self::$cache = $areas;
    }

    /**
     * En el esquema multinegocio cada casa tiene sus estaciones (la Panadería,
     * su horno), y ésta es la pantalla de las del Restaurante: las del KDS de
     * /area y las del POS. null en el esquema legado, que sólo tiene unas.
     */
    private static function unidadRestaurante(): ?int
    {
        try {
            return ContextoNegocio::nuevo() ? ContextoNegocio::unidad('restaurante') : null;
        } catch (\Throwable $e) {
            error_log('AreasProduccion::unidadRestaurante - ' . $e->getMessage());
            return null;
        }
    }

    public static function porSlug(string $slug): ?array
    {
        return self::todas()[$slug] ?? null;
    }

    /** La clave de la paleta de un hex ('#E51022' → 'rojo'), o null si no es de la paleta. */
    public static function clave(?string $hex): ?string
    {
        $hex = self::normalizar($hex);
        foreach (self::PALETA as $clave => $color) {
            if ($color['hex'] === $hex) {
                return $clave;
            }
        }

        return null;
    }

    /**
     * Valida lo que llega del formulario ([slug => clave de la paleta]):
     * toda estación con un color de la paleta, y ninguno repetido.
     *
     * @return array{claves: array<string, ?string>, colores: array<string, string>, errores: list<string>}
     */
    public static function validar(array $entrada): array
    {
        $claves = [];
        $colores = [];
        $errores = [];

        foreach (self::todas() as $slug => $area) {
            $clave = is_string($entrada[$slug] ?? null) ? $entrada[$slug] : null;
            $claves[$slug] = $clave;
            if ($clave === null || !isset(self::PALETA[$clave])) {
                $errores[] = 'Elige un color de la paleta para ' . $area['nombre'] . '.';
                continue;
            }
            $colores[$slug] = self::PALETA[$clave]['hex'];
        }

        $porColor = [];
        foreach ($claves as $slug => $clave) {
            if ($clave !== null && isset(self::PALETA[$clave])) {
                $porColor[$clave][] = self::todas()[$slug]['nombre'];
            }
        }
        foreach ($porColor as $clave => $nombres) {
            if (count($nombres) > 1) {
                $errores[] = self::enumerar($nombres) . ' tienen el mismo color (' . self::PALETA[$clave]['nombre']
                    . '). Cada estación necesita el suyo: es lo que las distingue en Impresoras y en el POS.';
            }
        }

        return ['claves' => $claves, 'colores' => $colores, 'errores' => $errores];
    }

    /**
     * Guarda los colores ([slug => hex], ya validados) en una sola transacción:
     * o cambian todos o ninguno, para que nunca queden dos estaciones con el
     * mismo color a medio guardar.
     */
    public static function guardar(array $colores): bool
    {
        $db = ActiveRecord::getDB();
        if (!$db) {
            return false;
        }

        $db->begin_transaction();
        try {
            // El slug sólo es único dentro de su negocio (uq_area_slug): sin la
            // unidad, cambiar «cocina» pintaría también la de otra casa.
            $unidad = self::unidadRestaurante();
            $stmt = $unidad === null
                ? $db->prepare('UPDATE areas_produccion SET color = ? WHERE slug = ?')
                : $db->prepare('UPDATE areas_produccion SET color = ? WHERE slug = ? AND unidad_id = ?');
            foreach ($colores as $slug => $hex) {
                $hex = (string) $hex;
                $slug = (string) $slug;
                if ($unidad === null) {
                    $stmt->bind_param('ss', $hex, $slug);
                } else {
                    $stmt->bind_param('ssi', $hex, $slug, $unidad);
                }
                $stmt->execute();
            }
            $stmt->close();
            $db->commit();
            self::$cache = null;

            return true;
        } catch (\Throwable $e) {
            $db->rollback();
            error_log('AreasProduccion::guardar - ' . $e->getMessage());

            return false;
        }
    }

    /**
     * 'ink' o 'paper': la de las dos anclas del panel que más contrasta con un
     * color, por luminancia relativa de WCAG (no por el promedio «percibido»,
     * que se equivoca en los tonos medios: al naranja le daba papel a 2.7:1).
     * Los dos valores son los de --admin-ink (#0b0b0c) y --admin-paper
     * (#fbfbfa) en _globals.scss, ya convertidos a luminancia.
     *
     * La pide todo lo que va RELLENO del color de una estación: la fila de su
     * área en Impresoras y la barra superior del KDS.
     */
    public static function tintaSobre(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return 'ink';
        }

        $canal = static function (string $par): float {
            $c = hexdec($par) / 255;
            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $canal(substr($hex, 0, 2))
            + 0.7152 * $canal(substr($hex, 2, 2))
            + 0.0722 * $canal(substr($hex, 4, 2));

        $lInk = 0.0037;
        $lPaper = 0.9640;
        $contraInk = ($l + 0.05) / ($lInk + 0.05);
        $contraPaper = ($lPaper + 0.05) / ($l + 0.05);

        return $contraInk >= $contraPaper ? 'ink' : 'paper';
    }

    /** '#F5B400' / 'f5b400' → '#f5b400'; null si no es un hex de seis cifras. */
    public static function normalizar($valor): ?string
    {
        if (!is_string($valor)) {
            return null;
        }
        $valor = strtolower(trim($valor));
        if ($valor !== '' && $valor[0] !== '#') {
            $valor = '#' . $valor;
        }

        return preg_match('/^#[0-9a-f]{6}$/', $valor) === 1 ? $valor : null;
    }

    /** «A», «A y B», «A, B y C». */
    private static function enumerar(array $nombres): string
    {
        $ultimo = array_pop($nombres);

        return $nombres === [] ? $ultimo : implode(', ', $nombres) . ' y ' . $ultimo;
    }
}
