<?php

/**
 * Paleta de la landing editable desde el panel (/admin/configuracion/paleta).
 *
 * Lo único que se puede cambiar son los cuatro colores de la capa 1 de
 * src/scss/layout/_reset.scss. Todo lo demás —escalas, roles, alias, vidrio—
 * se deriva de ellos con color-mix en el navegador, así que basta con
 * redeclarar esas cuatro propiedades en :root, DESPUÉS de app.css, para que la
 * landing entera y las páginas sueltas de marca cambien a la vez.
 * El oro no está: la landing no lo consume.
 *
 * MARCA repite a mano los hex de la capa 1 —es el único sitio fuera de los dos
 * archivos de tokens que lo hace— porque el formulario necesita enseñar el
 * valor del manual y el PDF de la carta, que Dompdf pinta sin custom
 * properties, necesita resolverlos en PHP. run-paleta-landing.php comprueba
 * que coincidan con el SCSS: si se cambia uno, falla hasta cambiar el otro.
 */

namespace Services\Configuration;

use Model\ConfiguracionPaleta;

final class PaletaLanding
{
    /** Espejo de la capa 1 de _reset.scss. */
    public const MARCA = [
        'verde' => '#225036',
        'cafe' => '#4a2f21',
        'beige' => '#e3d5bb',
        'crema' => '#f5f1e8',
    ];

    /** Lo que el panel cuenta de cada color: dónde se ve, en palabras. */
    public const COLORES = [
        'verde' => [
            'etiqueta' => 'Verde',
            'token' => '--brand-verde',
            'uso' => 'Texto de las secciones claras y fondo de las secciones verdes.',
        ],
        'cafe' => [
            'etiqueta' => 'Café',
            'token' => '--brand-cafe',
            'uso' => 'Botones, líneas y titulares sobre crema; fondo de las secciones café.',
        ],
        'beige' => [
            'etiqueta' => 'Beige',
            'token' => '--brand-beige',
            'uso' => 'Fondo de las secciones de lino y acento sobre verde y café.',
        ],
        'crema' => [
            'etiqueta' => 'Crema',
            'token' => '--brand-crema',
            'uso' => 'Fondo principal de la página y texto sobre los fondos oscuros.',
        ],
    ];

    /** AA para texto normal: la casa no admite menos en ningún ámbito. */
    public const AA_TEXTO = 4.5;

    /*
     * Expresiones de color: una clave de la paleta, 'negro', 'blanco' o una
     * mezcla [a, porcentaje de a, b] que reproduce
     * `color-mix(in srgb, a N%, b)`. Viven aquí, y el JS del panel las recibe
     * por JSON, para que la vista previa y el servidor midan lo mismo.
     */
    private const VERDE_PROFUNDO = ['verde', 72, 'negro'];
    private const CAFE_PROFUNDO = ['cafe', 74, 'negro'];

    /**
     * Las parejas que deciden si la landing se puede leer. Cada una es el rol
     * MÁS tenue de su ámbito: si el texto secundario llega al 4.5:1, el
     * principal —más cargado contra el mismo fondo— también. Los porcentajes
     * son los de _reset.scss; si cambian allí, cambian aquí.
     */
    public const COMPROBACIONES = [
        [
            'id' => 'crema-texto',
            'rotulo' => 'Texto secundario sobre crema',
            'texto' => [self::VERDE_PROFUNDO, 69, 'crema'],
            'fondo' => 'crema',
        ],
        [
            'id' => 'beige-texto',
            'rotulo' => 'Texto secundario sobre beige',
            'texto' => [self::VERDE_PROFUNDO, 74, 'beige'],
            'fondo' => 'beige',
        ],
        [
            'id' => 'verde-texto',
            'rotulo' => 'Texto secundario sobre verde',
            'texto' => ['crema', 65, 'verde'],
            'fondo' => 'verde',
        ],
        [
            'id' => 'verde-acento',
            'rotulo' => 'Acento beige sobre verde',
            'texto' => 'beige',
            'fondo' => 'verde',
        ],
        [
            'id' => 'cafe-texto',
            'rotulo' => 'Texto secundario sobre café',
            'texto' => ['crema', 52, self::CAFE_PROFUNDO],
            'fondo' => self::CAFE_PROFUNDO,
        ],
        [
            'id' => 'cafe-acento',
            'rotulo' => 'Acento beige sobre café',
            'texto' => 'beige',
            'fondo' => self::CAFE_PROFUNDO,
        ],
        [
            'id' => 'boton',
            'rotulo' => 'Botón principal: crema sobre café',
            'texto' => 'crema',
            'fondo' => 'cafe',
        ],
    ];

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /** @return array<string, string> */
    public static function marca(): array
    {
        return self::MARCA;
    }

    /**
     * Los cuatro colores vigentes: el guardado o, si no hay, el de marca.
     *
     * Lectura tolerante: la llama cada
     * página pública, y una base sin la tabla no puede tumbar la portada. Ante
     * cualquier fallo, la landing se pinta con su CSS, que es la marca.
     *
     * @return array<string, string>
     */
    public static function vigente(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $paleta = self::MARCA;
        foreach (self::guardados() as $clave => $valor) {
            if ($valor !== null) {
                $paleta[$clave] = $valor;
            }
        }

        return self::$cache = $paleta;
    }

    /**
     * Lo que hay en la base, color por color; null donde rige la marca.
     *
     * @return array<string, ?string>
     */
    public static function guardados(): array
    {
        $vacio = array_fill_keys(array_keys(self::MARCA), null);
        try {
            $fila = ConfiguracionPaleta::getDB() ? ConfiguracionPaleta::obtener() : null;
        } catch (\Throwable $e) {
            error_log('PaletaLanding::guardados - ' . $e->getMessage());
            return $vacio;
        }
        if ($fila === null) {
            return $vacio;
        }

        $guardados = $vacio;
        foreach (array_keys(self::MARCA) as $clave) {
            // Se revalida al LEER y no sólo al escribir: el valor acaba dentro
            // de un <style>, y una fila editada a mano con `</style>` dentro
            // sería marcado inyectado en cada página pública.
            $guardados[$clave] = self::normalizar($fila->$clave ?? null);
        }

        return $guardados;
    }

    public static function limpiarCache(): void
    {
        self::$cache = null;
    }

    /**
     * `#rrggbb` en minúsculas, o null si no es un color. Admite la forma
     * corta y el valor sin almohadilla, que es como se pega desde Figma o
     * desde un manual en PDF.
     */
    public static function normalizar($valor): ?string
    {
        if (!is_scalar($valor)) {
            return null;
        }
        $hex = ltrim(strtolower(trim((string) $valor)), '#');
        if (preg_match('/^[0-9a-f]{3}$/', $hex) === 1) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return preg_match('/^[0-9a-f]{6}$/', $hex) === 1 ? '#' . $hex : null;
    }

    /**
     * Valida lo que llega del formulario.
     *
     * Devuelve dos vistas del mismo envío: `paleta`, los cuatro hex tal como
     * se verían (para repintar el formulario y medir el contraste), y
     * `guardar`, lo que va a la base: null donde el color coincide con el de
     * marca, para que siga al manual si la capa 1 cambia algún día.
     *
     * @return array{paleta: array<string, string>, guardar: array<string, ?string>, errores: list<string>}
     */
    public static function validar(array $entrada): array
    {
        $paleta = [];
        $guardar = [];
        $errores = [];
        foreach (self::MARCA as $clave => $deMarca) {
            $crudo = $entrada[$clave] ?? '';
            $hex = self::normalizar($crudo);
            if ($hex === null) {
                $errores[] = sprintf(
                    '«%s» no es un color válido. Escríbelo en hexadecimal, por ejemplo %s.',
                    self::COLORES[$clave]['etiqueta'],
                    $deMarca
                );
                $paleta[$clave] = is_scalar($crudo) ? trim((string) $crudo) : '';
                $guardar[$clave] = null;
                continue;
            }
            $paleta[$clave] = $hex;
            $guardar[$clave] = $hex === $deMarca ? null : $hex;
        }

        return ['paleta' => $paleta, 'guardar' => $guardar, 'errores' => $errores];
    }

    /**
     * Mide las COMPROBACIONES contra una paleta.
     *
     * @param array<string, string> $paleta
     * @return list<array{id: string, rotulo: string, ratio: float, minimo: float, ok: bool}>
     */
    public static function contraste(array $paleta): array
    {
        $resultado = [];
        foreach (self::COMPROBACIONES as $comprobacion) {
            $ratio = self::ratio(
                self::resolver($comprobacion['texto'], $paleta),
                self::resolver($comprobacion['fondo'], $paleta)
            );
            $resultado[] = [
                'id' => $comprobacion['id'],
                'rotulo' => $comprobacion['rotulo'],
                'ratio' => round($ratio, 2),
                'minimo' => self::AA_TEXTO,
                'ok' => $ratio >= self::AA_TEXTO,
            ];
        }

        return $resultado;
    }

    /**
     * La redeclaración de la capa 1 para el <head> de una página de marca.
     * Va DESPUÉS del <link> de app.css: misma especificidad que el :root de la
     * hoja, así que gana por orden. Sólo emite los colores que se apartan de
     * la marca, y nada si no se aparta ninguno.
     */
    public static function estiloEnLinea(): string
    {
        $declaraciones = '';
        foreach (self::vigente() as $clave => $hex) {
            if ($hex !== self::MARCA[$clave]) {
                $declaraciones .= self::COLORES[$clave]['token'] . ':' . $hex . ';';
            }
        }

        return $declaraciones === ''
            ? ''
            : '<style id="paleta-landing">:root{' . $declaraciones . '}</style>';
    }

    /**
     * Los roles que pinta el PDF de la carta (views/admin/menu/items-pdf.php),
     * ya resueltos: Dompdf no entiende custom properties ni color-mix. Son las
     * mismas mezclas que hace el navegador en :root y en [data-tono="verde"];
     * las líneas van aplanadas contra el fondo porque el soporte de alpha en
     * Dompdf es irregular según la propiedad.
     *
     * @return array<string, string>
     */
    public static function paraPdf(?array $paleta = null): array
    {
        $paleta = $paleta ?? self::vigente();
        $roles = [
            'verde' => 'verde',
            'cafe' => 'cafe',
            'beige' => 'beige',
            'crema' => 'crema',

            // :root, papel crema
            'bg' => 'crema',
            'txt' => self::VERDE_PROFUNDO,
            'txt_strong' => self::CAFE_PROFUNDO,
            'txt_mute' => [self::VERDE_PROFUNDO, 80, 'crema'],
            'txt_faint' => [self::VERDE_PROFUNDO, 69, 'crema'],
            'accent' => 'cafe',
            'line_soft' => ['cafe', 30, 'crema'],
            'line_strong' => ['cafe', 78, 'crema'],

            // [data-tono="verde"], la banda de cabecera
            'v_bg' => 'verde',
            'v_txt' => 'crema',
            'v_txt_mute' => ['crema', 78, 'verde'],
            'v_accent' => 'beige',
        ];

        $resueltos = [];
        foreach ($roles as $rol => $expresion) {
            $resueltos[$rol] = self::aHex(self::resolver($expresion, $paleta));
        }

        return $resueltos;
    }

    /**
     * @param string|array $expresion
     * @param array<string, string> $paleta
     * @return array{0: float, 1: float, 2: float}
     */
    public static function resolver($expresion, array $paleta): array
    {
        if (is_array($expresion)) {
            [$a, $porcentaje, $b] = $expresion;
            $p = ((float) $porcentaje) / 100;
            $ca = self::resolver($a, $paleta);
            $cb = self::resolver($b, $paleta);
            // color-mix(in srgb) interpola los canales ya codificados en
            // gamma, sin linealizar: es una media ponderada directa.
            return [
                $ca[0] * $p + $cb[0] * (1 - $p),
                $ca[1] * $p + $cb[1] * (1 - $p),
                $ca[2] * $p + $cb[2] * (1 - $p),
            ];
        }
        if ($expresion === 'negro') {
            return [0.0, 0.0, 0.0];
        }
        if ($expresion === 'blanco') {
            return [255.0, 255.0, 255.0];
        }

        $hex = self::normalizar($paleta[$expresion] ?? null) ?? self::MARCA[$expresion];

        return [
            (float) hexdec(substr($hex, 1, 2)),
            (float) hexdec(substr($hex, 3, 2)),
            (float) hexdec(substr($hex, 5, 2)),
        ];
    }

    /** Razón de contraste WCAG 2.x entre dos colores RGB 0-255. */
    public static function ratio(array $a, array $b): float
    {
        $la = self::luminancia($a);
        $lb = self::luminancia($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminancia(array $rgb): float
    {
        $canal = static function (float $v): float {
            $v /= 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $canal($rgb[0]) + 0.7152 * $canal($rgb[1]) + 0.0722 * $canal($rgb[2]);
    }

    private static function aHex(array $rgb): string
    {
        return sprintf(
            '#%02x%02x%02x',
            max(0, min(255, (int) round($rgb[0]))),
            max(0, min(255, (int) round($rgb[1]))),
            max(0, min(255, (int) round($rgb[2])))
        );
    }
}
