<?php

namespace Services;

final class AnuncioConfig
{
    public const DURACION_VISIBLE_MS = 8000;

    public const TIPO_PREDETERMINADO = 'evento';

    /*
     * `acento` alimenta --announcement-accent, que usan tanto la vista previa
     * del admin como el anuncio real del landing.
     *
     * Los cuatro acentos anteriores eran variaciones de beige (#9fc2c5,
     * #e0c184, #c9ab78, #b8b39f): un aviso operativo y una promoción se veían
     * iguales. Ahora cada tipo toma un color de la paleta de marca (ver
     * CLAUDE.md), que es justo el caso para el que existe.
     */
    /*
     * `placeholder` es una frase modelo, no una instrucción: el campo vacío ya
     * enseña cómo se escribe un anuncio de ese tipo, en la voz de la casa y con
     * el dato que nunca hay que olvidar (el cuándo). Antes decía "Describe el
     * evento, la fecha y el horario", que obligaba a escribir algo cualquiera
     * para ver cómo quedaba.
     */
    public const TIPOS = [
        'evento' => [
            'etiqueta' => 'Evento',
            'descripcion' => 'Actividades, música en vivo, celebraciones o experiencias especiales.',
            'ejemplo' => 'Este sábado tendremos música en vivo a partir de las 19:00 h.',
            'placeholder' => 'El viernes 12 recibimos al Trío Pestalozzi desde las 20:00 h.',
            'texto_enlace' => 'Reservar mesa',
            'acento' => '#aa2296',
            'icono' => '<path d="M8 3v3M16 3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v13H4V6a1 1 0 0 1 1-1Z"/><path d="M8 13h3M8 16h6"/>',
        ],
        'promocion' => [
            'etiqueta' => 'Promoción',
            'descripcion' => 'Descuentos, paquetes u ofertas disponibles por tiempo limitado.',
            'ejemplo' => 'Disfruta nuestra promoción especial durante todo julio.',
            'placeholder' => 'Todos los martes de agosto, 2×1 en café de olla hasta las 13:00 h.',
            'texto_enlace' => 'Conocer promoción',
            'acento' => '#fc6722',
            'icono' => '<path d="M20 12 12 20 4 12V4h8l8 8Z"/><circle cx="8.5" cy="8.5" r="1.25"/>',
        ],
        'novedad_menu' => [
            'etiqueta' => 'Novedad del menú',
            'descripcion' => 'Nuevos platillos, actualizaciones de la carta o menús de temporada.',
            'ejemplo' => 'Descubre nuestros nuevos platillos de temporada, disponibles por tiempo limitado.',
            'placeholder' => 'Ya está en la carta el mole de temporada, servido hasta fin de mes.',
            'texto_enlace' => 'Ver menú',
            'acento' => '#34a853',
            'icono' => '<path d="M6 3v8M3 3v5a3 3 0 0 0 6 0V3M6 11v10M16 3c2 2 3 5 3 8v2h-5V8c0-2 1-4 2-5ZM16 13v8"/>',
        ],
        'aviso_operativo' => [
            'etiqueta' => 'Aviso operativo',
            'descripcion' => 'Información sobre accesos, estacionamiento, mantenimiento o disponibilidad de áreas.',
            'ejemplo' => 'Por trabajos de mantenimiento, nuestra terraza permanecerá cerrada temporalmente. El servicio continuará con normalidad en las áreas interiores.',
            'placeholder' => 'La terraza estará cerrada por mantenimiento; el salón atiende con normalidad.',
            'texto_enlace' => '',
            'acento' => '#f5b400',
            'icono' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        ],
    ];

    public static function tipoValido(string $tipo): bool
    {
        return array_key_exists($tipo, self::TIPOS);
    }

    public static function tipo(string $tipo): array
    {
        return self::TIPOS[self::tipoValido($tipo) ? $tipo : self::TIPO_PREDETERMINADO];
    }
}
