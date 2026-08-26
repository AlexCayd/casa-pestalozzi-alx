<?php

/**
 * Datos públicos de la casa: dirección, contacto, redes y el WhatsApp de
 * eventos.
 *
 * Existe porque estaban repetidos a mano en las vistas —el teléfono en tres
 * sitios, el shortlink de Maps en dos, el Instagram sólo dentro del JSON-LD— y
 * los dos iconos de redes de Ubicación apuntaban a href="#". No hay tabla de
 * configuración general en la BD (sólo configuracion_anuncio y
 * configuracion_pos) y no se va a crear una para esto: el patrón de la casa
 * para valores de despliegue es variable de entorno con respaldo, igual que
 * Services\ReservacionConfig.
 *
 * El WhatsApp de eventos NO es el mismo número que el de reservaciones: quien
 * cotiza un evento habla con otra persona, así que vive aquí y no en
 * ReservacionConfig.
 */

namespace Services;

class SitioConfig
{
    public static function direccion(): string
    {
        return self::env('SITIO_DIRECCION', 'José Enrique Pestalozzi 1250, Del Valle, CDMX');
    }

    /** Versión corta, para columnas estrechas como la del pie. */
    public static function direccionCorta(): string
    {
        return self::env('SITIO_DIRECCION_CORTA', 'Pestalozzi 1250, CDMX');
    }

    public static function telefonoVisible(): string
    {
        return ReservacionConfig::telefonoVisible();
    }

    public static function telefonoTel(): string
    {
        return ReservacionConfig::telefonoTel();
    }

    public static function correo(): string
    {
        return self::env('SITIO_CORREO', 'hola@casapestalozzi.mx');
    }

    public static function mapsUrl(): string
    {
        return self::env('SITIO_MAPS_URL', 'https://maps.app.goo.gl/NwDmN5Tjbz3Etf7r7');
    }

    public static function instagramUrl(): string
    {
        return self::env('SITIO_INSTAGRAM', 'https://www.instagram.com/casapestalozzi');
    }

    /** WhatsApp de eventos y catering. */
    public static function whatsappEventos(): string
    {
        return self::normalizarWhatsapp(self::env('SITIO_WHATSAPP_EVENTOS', '525637185620'));
    }

    public static function whatsappEventosUrl(string $mensaje = ''): string
    {
        $url = 'https://wa.me/' . self::whatsappEventos();

        return $mensaje === '' ? $url : $url . '?text=' . rawurlencode($mensaje);
    }

    /**
     * Directorio de redes, en el orden en que se pinta.
     *
     * Facebook se retiró: el marcado tenía el icono con href="#" y un enlace
     * que no lleva a ninguna parte es peor que no tenerlo. Vuelve en cuanto
     * haya URL.
     */
    public static function redes(): array
    {
        return [
            [
                'id' => 'instagram',
                'nombre' => 'Instagram',
                'url' => self::instagramUrl(),
            ],
            [
                'id' => 'whatsapp',
                'nombre' => 'WhatsApp',
                'url' => self::whatsappEventosUrl(),
            ],
        ];
    }

    private static function env(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? getenv($key);

        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        return trim($value);
    }

    private static function normalizarWhatsapp(string $numero): string
    {
        return preg_replace('/\D+/', '', $numero) ?? '';
    }
}
