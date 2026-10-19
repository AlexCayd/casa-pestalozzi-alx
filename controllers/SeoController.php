<?php

namespace Controllers;

use Services\Configuration\SitioConfig;

/**
 * robots.txt y sitemap.xml.
 *
 * Son rutas y no archivos en public/ porque los dos llevan la URL absoluta del
 * sitio, y ésa sale de SitioConfig::url() (SITIO_URL en el .env): con archivos
 * estáticos había que acordarse de editarlos al cambiar de dominio, y en este
 * proyecto el dominio ya aparece de dos maneras (.com en las metaetiquetas,
 * .mx en el correo). Como no existen en disco, el .htaccess las manda a
 * index.php como cualquier otra ruta.
 */
class SeoController
{
    /**
     * Lo único que se ofrece a los buscadores es la portada y las dos cartas.
     * Todo lo demás es operación (panel, POS, áreas), una API, o una página
     * con token de un solo comensal (gestionar o mover su reservación), que
     * además lleva `noindex` en su propia cabecera.
     */
    public static function robots($router): void
    {
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: public, max-age=86400');

        $lineas = [
            '# Casa Pestalozzi',
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /api/',
            'Disallow: /login',
            'Disallow: /logout',
            'Disallow: /punto-de-venta',
            'Disallow: /area',
            'Disallow: /feedback',
            'Disallow: /reservaciones/gestionar',
            'Disallow: /reservaciones/cambio-horario',
            // El JSON con que la landing pinta la carta: la carta indexable es
            // la propia portada y el PDF. `$` cierra la regla a esa ruta exacta
            // para no bloquear /menu/pdf.
            'Disallow: /menu$',
            '',
            'Sitemap: ' . SitioConfig::url() . '/sitemap.xml',
        ];

        echo implode("\n", $lineas) . "\n";
    }

    /**
     * Sin `lastmod` a propósito: no hay una fecha honesta que poner (la carta
     * cambia cuando el panel la edita y la portada con cada despliegue), y un
     * `lastmod` que no se corresponde con la realidad hace que el buscador
     * deje de fiarse del archivo entero.
     */
    public static function sitemap($router): void
    {
        header('Content-Type: application/xml; charset=UTF-8');
        header('Cache-Control: public, max-age=86400');

        $base = SitioConfig::url();
        $urls = [$base . '/', $base . '/menu/pdf', $base . '/maridaje/pdf'];

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $url) {
            echo '  <url><loc>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc></url>' . "\n";
        }
        echo '</urlset>' . "\n";
    }
}
