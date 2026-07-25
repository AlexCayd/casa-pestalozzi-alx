<?php

namespace Services;

final class AnuncioConfig
{
    public const DURACION_VISIBLE_MS = 8000;

    public const TIPO_PREDETERMINADO = 'evento';

    public const TIPOS = [
        'evento' => [
            'etiqueta' => 'Evento',
            'descripcion' => 'Actividades, música en vivo, celebraciones o experiencias especiales.',
            'ejemplo' => 'Este sábado tendremos música en vivo a partir de las 19:00 h.',
            'placeholder' => 'Describe el evento, la fecha y el horario.',
            'texto_enlace' => 'Reservar mesa',
            'acento' => '#9fc2c5',
            'icono' => '<path d="M8 3v3M16 3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v13H4V6a1 1 0 0 1 1-1Z"/><path d="M8 13h3M8 16h6"/>',
        ],
        'promocion' => [
            'etiqueta' => 'Promoción',
            'descripcion' => 'Descuentos, paquetes u ofertas disponibles por tiempo limitado.',
            'ejemplo' => 'Disfruta nuestra promoción especial durante todo julio.',
            'placeholder' => 'Describe la promoción y el periodo en que estará disponible.',
            'texto_enlace' => 'Conocer promoción',
            'acento' => '#e0c184',
            'icono' => '<path d="M20 12 12 20 4 12V4h8l8 8Z"/><circle cx="8.5" cy="8.5" r="1.25"/>',
        ],
        'novedad_menu' => [
            'etiqueta' => 'Novedad del menú',
            'descripcion' => 'Nuevos platillos, actualizaciones de la carta o menús de temporada.',
            'ejemplo' => 'Descubre nuestros nuevos platillos de temporada, disponibles por tiempo limitado.',
            'placeholder' => 'Presenta el nuevo platillo, menú o actualización de la carta.',
            'texto_enlace' => 'Ver menú',
            'acento' => '#c9ab78',
            'icono' => '<path d="M6 3v8M3 3v5a3 3 0 0 0 6 0V3M6 11v10M16 3c2 2 3 5 3 8v2h-5V8c0-2 1-4 2-5ZM16 13v8"/>',
        ],
        'aviso_operativo' => [
            'etiqueta' => 'Aviso operativo',
            'descripcion' => 'Información sobre accesos, estacionamiento, mantenimiento o disponibilidad de áreas.',
            'ejemplo' => 'Por trabajos de mantenimiento, nuestra terraza permanecerá cerrada temporalmente. El servicio continuará con normalidad en las áreas interiores.',
            'placeholder' => 'Explica el cambio operativo y cómo continuará el servicio.',
            'texto_enlace' => '',
            'acento' => '#b8b39f',
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
