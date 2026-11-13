<?php

/**
 * ┌───────────────────────────────────────────────────────────────┐
 * │  PANADERÍA — sólo la landing /panaderia lee esto.             │
 * └───────────────────────────────────────────────────────────────┘
 *
 * Los datos públicos de la panadería: dirección, teléfono, mapa, WhatsApp,
 * horario y los casos de encargo.
 *
 * Es el gemelo de Services\Configuration\SitioConfig, que guarda los del
 * restaurante, y existe porque son dos casas en dos calles: el restaurante en
 * José Enrique Pestalozzi 1250 y la panadería en Calle San Borja 1026. Ninguna
 * vista de la panadería lee la dirección, el teléfono ni el mapa de
 * SitioConfig (scripts/tests/run-frontera-panaderia.php lo vigila); de
 * SitioConfig sólo toma lo que es del SITIO: url() e instagramUrl().
 *
 * Igual que SitioConfig, cada valor de despliegue es una variable de entorno
 * con respaldo, y todas empiezan por PANADERIA_.
 */

namespace Services\Panaderia;

class PanaderiaConfig
{
    public static function nombre(): string
    {
        // El de su ficha de Google Maps, que es como la busca la gente.
        return 'Panadería Casa Pestalozzi';
    }

    public static function direccion(): string
    {
        return self::env('PANADERIA_DIRECCION', 'Calle San Borja 1026, Del Valle Centro, 03100, CDMX');
    }

    /** Versión corta, para la portada y las columnas estrechas. */
    public static function direccionCorta(): string
    {
        return self::env('PANADERIA_DIRECCION_CORTA', 'San Borja 1026, Del Valle');
    }

    /** Para los datos estructurados, que piden la dirección por partes. */
    public const DIRECCION_POSTAL = [
        'streetAddress' => 'Calle San Borja 1026',
        'addressLocality' => 'Del Valle Centro',
        'postalCode' => '03100',
        'addressRegion' => 'Ciudad de México',
        'addressCountry' => 'MX',
    ];

    public static function telefonoVisible(): string
    {
        return self::env('PANADERIA_TELEFONO', '55 1229 9648');
    }

    /** El mismo teléfono en formato tel: (+52 y diez dígitos). */
    public static function telefonoTel(): string
    {
        $digitos = preg_replace('/\D+/', '', self::telefonoVisible()) ?? '';
        if (strlen($digitos) === 10) {
            $digitos = '52' . $digitos;
        }

        return '+' . $digitos;
    }

    /**
     * Respaldo: una búsqueda por nombre y dirección, que abre la ficha de la
     * panadería en Google Maps. Cuando haya enlace corto de la ficha, va en
     * PANADERIA_MAPS_URL.
     */
    public static function mapsUrl(): string
    {
        return self::env(
            'PANADERIA_MAPS_URL',
            'https://www.google.com/maps/search/?api=1&query='
                . rawurlencode('Panadería Casa Pestalozzi, Calle San Borja 1026, Del Valle Centro, CDMX')
        );
    }

    /**
     * WhatsApp de la panadería. Por defecto, su teléfono. NO es el de
     * reservaciones ni el de eventos del restaurante: ahí contesta otra gente,
     * en otra dirección.
     */
    public static function whatsapp(): string
    {
        $numero = preg_replace('/\D+/', '', self::env('PANADERIA_WHATSAPP', '')) ?? '';
        if ($numero === '') {
            $numero = ltrim(self::telefonoTel(), '+');
        }

        return $numero;
    }

    public static function whatsappUrl(string $mensaje = ''): string
    {
        $url = 'https://wa.me/' . self::whatsapp();

        return $mensaje === '' ? $url : $url . '?text=' . rawurlencode($mensaje);
    }

    public const MENSAJE_GENERICO = 'Hola! Quiero hacer un encargo en la panadería';
    public const MENSAJE_VITRINA = 'Hola! Quiero apartar pan de la vitrina';

    /** Instagram y WhatsApp de la panadería, para views/components/marca/redes.php. */
    public static function redes(): array
    {
        return [
            ['id' => 'instagram', 'nombre' => 'Instagram', 'url' => \Services\Configuration\SitioConfig::instagramUrl()],
            ['id' => 'whatsapp', 'nombre' => 'WhatsApp', 'url' => self::whatsappUrl(self::MENSAJE_GENERICO)],
        ];
    }

    /**
     * Horario de la semana, por día de la semana de PHP (0 = domingo).
     * null = cerrado.
     *
     * ⚠️ DE EJEMPLO. Lo único confirmado es que cierra a las 7 p.m. entre semana
     * (su ficha de Google). Hay que pedir el horario completo a la panadería.
     * Cuando llegue la integración multiempresa, pasa a horarios_operacion con
     * su unidad y gana las excepciones del panel.
     */
    public const HORARIO = [
        1 => ['07:30', '19:00'],
        2 => ['07:30', '19:00'],
        3 => ['07:30', '19:00'],
        4 => ['07:30', '19:00'],
        5 => ['07:30', '19:00'],
        6 => ['08:00', '19:00'],
        0 => ['08:00', '15:00'],
    ];

    private const DIAS = [0 => 'Domingo', 1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado'];

    /**
     * La semana de lunes a domingo, con el día de hoy marcado.
     * [['dia_semana' => int, 'nombre' => …, 'abre' => ?string, 'cierra' => ?string, 'hoy' => bool], …]
     */
    public static function semana(?\DateTimeImmutable $ahora = null): array
    {
        $hoy = (int) ($ahora ?? self::ahora())->format('w');
        $filas = [];
        foreach (self::HORARIO as $dia => $horas) {
            $filas[] = [
                'dia_semana' => $dia,
                'nombre' => self::DIAS[$dia],
                'abre' => $horas[0] ?? null,
                'cierra' => $horas[1] ?? null,
                'hoy' => $dia === $hoy,
            ];
        }

        return $filas;
    }

    /**
     * La línea de hoy, en palabras: «Hoy abrimos de 7:30 a 19:00» o «Hoy no
     * abrimos». Es la pregunta que más se hace antes de salir de casa.
     */
    public static function lineaDeHoy(?\DateTimeImmutable $ahora = null): string
    {
        $ahora ??= self::ahora();
        $horas = self::HORARIO[(int) $ahora->format('w')] ?? null;
        if ($horas === null) {
            return 'Hoy no abrimos';
        }

        return 'Hoy abrimos de ' . self::hora($horas[0]) . ' a ' . self::hora($horas[1]);
    }

    /** «07:30» → «7:30»: en un renglón de texto el cero delante estorba. */
    public static function hora(string $hhmm): string
    {
        return ltrim(substr($hhmm, 0, 2), '0') . substr($hhmm, 2);
    }

    /**
     * Los casos de encargo, por grupo. Cada uno va con su anticipación mínima
     * y su frase para WhatsApp: la frase NO se arma concatenando, porque el
     * artículo cambia con cada caso —«un coffee break», «una canasta»,
     * «unas roscas»— y en español no se puede deducir del texto. Mismo patrón
     * que SitioConfig::OCASIONES_EVENTO.
     *
     * Anticipaciones de ejemplo: confirmarlas con la panadería.
     *
     * [grupo => [[caso, anticipación, frase], …]]
     */
    public const ENCARGOS = [
        'Para la oficina' => [
            ['Coffee break para una junta', '24 h', 'Hola! Quiero encargar un coffee break para una junta'],
            ['Caja de bollería para el equipo', '24 h', 'Hola! Quiero encargar una caja de bollería para mi equipo'],
            ['Pan para el comedor, cada semana', 'Se acuerda', 'Hola! Me interesa un pedido semanal de pan para el comedor de mi oficina'],
        ],
        'Para celebrar' => [
            ['Pastel de cumpleaños', '48 h', 'Hola! Quiero encargar un pastel de cumpleaños'],
            ['Bautizo, comunión o baby shower', '1 semana', 'Hola! Quiero encargar pan y postres para una celebración familiar'],
            ['Mesa de postres para boda o XV años', '2 semanas', 'Hola! Quiero cotizar una mesa de postres para una boda o XV años'],
        ],
        'Para regalar' => [
            ['Panettone en caja', '3 días', 'Hola! Quiero encargar panettone en caja para regalar'],
            ['Canasta de temporada', '1 semana', 'Hola! Quiero encargar una canasta de temporada'],
            ['Regalos de fin de año para clientes', '2 semanas', 'Hola! Quiero cotizar regalos de fin de año para mis clientes'],
        ],
        'Para tu negocio' => [
            ['Pan para tu cafetería o restaurante', 'Se acuerda', 'Hola! Me interesa surtir pan a mi cafetería o restaurante'],
            ['Pedido diario de hogazas y baguettes', 'Se acuerda', 'Hola! Me interesa un pedido diario de hogazas y baguettes'],
        ],
        'En temporada' => [
            ['Pan de muerto por docena', '2 días', 'Hola! Quiero encargar pan de muerto por docena'],
            ['Rosca de Reyes para la oficina', '3 días', 'Hola! Quiero encargar una rosca de Reyes para la oficina'],
        ],
        'Para el fin de semana' => [
            ['Aparta tu hogaza del sábado', '1 día', 'Hola! Quiero apartar una hogaza para el sábado'],
            ['Desayuno para llevar a casa', '1 día', 'Hola! Quiero encargar un desayuno para llevar a casa'],
        ],
    ];

    /**
     * Platillos de la carta del restaurante que llevan pan de la panadería, con
     * el pan que llevan. La sección «El restaurante» sólo enseña los que siguen
     * activos en `productos` (casa por nombre, que es UNIQUE): si cocina cambia
     * la carta, el que ya no exista desaparece solo en vez de quedarse escrito.
     *
     * ⚠️ Confirmar con cocina que estos panes salen de la panadería.
     */
    public const EN_LA_CARTA = [
        'Hamburguesa de la Casa' => 'Brioche',
        'Pan Francés Estilo C.P.' => 'Brioche',
        'Molletes' => 'Baguette',
        'Croissant con Huevo y Estragón' => 'Croissant',
        'Cazuela Cascabel' => 'Hogaza',
    ];

    public static function ahora(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City'));
    }

    private static function env(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? getenv($key);

        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        return trim($value);
    }
}
