<?php
/**
 * Iconos del panel de administración.
 *
 * Un único catálogo de trazos y una función que los envuelve. Antes cada vista
 * pegaba su propio `<svg viewBox="0 0 24 24">…` en el marcado —cincuenta y
 * tantos repartidos por dieciocho archivos— y el sidebar tenía además su
 * array local: el mismo icono de basura estaba escrito cinco veces con tres
 * juegos de atributos distintos, y cambiar el grosor del trazo obligaba a
 * buscarlos uno por uno.
 *
 * También es el reemplazo de los emojis y glifos sueltos que quedaban
 * (⭐ 🐎 ❓ 🐕 ★ ☆ ✓ → ▲ ▼ ↗). Un emoji lo pinta la fuente del sistema: no
 * hereda `currentColor`, cambia de forma entre plataformas y se sale de la caja
 * tipográfica. Es la misma regla que ya cumple el POS con SVG_PATHS/svgIcon()
 * en src/js/modules/punto-de-venta.js.
 *
 * Todos los trazos están dibujados sobre una caja de 24×24 y sin relleno, para
 * que el conjunto se lea como una familia. Al añadir uno nuevo, respetar las
 * dos cosas.
 *
 * Uso:
 *   <?php require_once __DIR__ . '/_icons.php'; ?>
 *   <?php echo admin_icon('basura'); ?>
 *   <?php echo admin_icon('check', 14, 'admin-btn__icon'); ?>
 */

if (!function_exists('admin_icon_paths')) {
    /**
     * Catálogo de trazos, indexado por nombre.
     *
     * @return array<string, string>
     */
    function admin_icon_paths(): array
    {
        static $iconos = null;
        if ($iconos !== null) {
            return $iconos;
        }

        $iconos = [
            // ── Módulos del panel (los consume el sidebar) ────────────
            'analytics' => '<path d="M4 19V5"/><path d="M4 19h16"/><path d="m7 15 3-4 3 2 4-6"/>',
            'menu' => '<path d="M5 4.5h8a3 3 0 0 1 3 3v12a2 2 0 0 0-2-2H5z"/><path d="M16 7.5h3v12a2 2 0 0 0-2-2h-1"/><path d="M8 8h4"/><path d="M8 11h4"/><path d="M8 14h3"/>',
            'pdv' => '<path d="M9 18 3 21V6l6-3 6 3 6-3v15l-6 3-6-3Z"/><path d="M9 3v15"/><path d="M15 6v15"/>',
            'area' => '<path d="M4 7h16"/><path d="M7 7v10a3 3 0 0 0 3 3h4a3 3 0 0 0 3-3V7"/><path d="M9 3v4"/><path d="M15 3v4"/><path d="M9 12h6"/>',
            'reservations' => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4"/><path d="M16 3v4"/><path d="M4 10h16"/>',
            // Copa de vino: catas dirigidas.
            'catas' => '<path d="M7 3h10l-.8 6a4.2 4.2 0 0 1-8.4 0Z"/><path d="M12 15v6"/><path d="M8.5 21h7"/>',
            'feedback' => '<path d="M21 15a2 2 0 0 1-2 2H8l-4 4V5a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2Z"/><path d="m12 7 1.2 2.5 2.8.4-2 2 .5 2.7L12 15.4 9.5 16.6l.5-2.7-2-2 2.8-.4Z"/>',
            'tables' => '<rect x="5" y="5" width="14" height="10" rx="2"/><path d="M8 15v4"/><path d="M16 15v4"/><path d="M5 19h14"/>',
            'products' => '<path d="M6 3v8a4 4 0 0 0 8 0V3"/><path d="M10 3v18"/><path d="M18 3v18"/>',
            'inventario' => '<path d="M3 7l9-4 9 4-9 4-9-4Z"/><path d="M3 7v10l9 4 9-4V7"/><path d="M12 11v10"/>',
            'finanzas' => '<path d="M3 3v18h18"/><path d="M7 15l3-4 3 2 4-6"/><circle cx="7" cy="15" r="0.6"/>',
            'categories' => '<path d="M20 12 12 20 4 12l8-8 8 8Z"/><path d="M12 8h.01"/>',
            'tickets' => '<path d="M6 3h12v18l-2-1-2 1-2-1-2 1-2-1-2 1V3Z"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h4"/>',
            'printers' => '<path d="M7 8V3h10v5"/><path d="M7 17H5a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-2"/><path d="M7 14h10v7H7z"/>',
            'users' => '<path d="M16 21v-2a4 4 0 0 0-8 0v2"/><circle cx="12" cy="7" r="4"/><path d="M20 21v-2a3 3 0 0 0-2-2.8"/>',
            'configuration' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3V2.8h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/>',

            // ── Acciones ──────────────────────────────────────────────
            'check' => '<path d="M20 6 9 17l-5-5"/>',
            'basura' => '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5"/><path d="M14 11v5"/>',
            'editar' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
            'imprimir' => '<path d="M6 9V3h12v6"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v7H6z"/>',
            'mas' => '<path d="M12 5v14"/><path d="M5 12h14"/>',
            'cerrar' => '<path d="M6 6l12 12"/><path d="M18 6 6 18"/>',
            'buscar' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
            'descargar' => '<path d="M12 3v12"/><path d="m7 11 5 5 5-5"/><path d="M4 20h16"/>',
            // Flecha diagonal: "abre en otra pantalla". Sustituye a ↗ y a →.
            'externo' => '<path d="M7 17 17 7"/><path d="M7 7h10v10"/>',
            'flecha-derecha' => '<path d="M5 12h13"/><path d="m12 6 6 6-6 6"/>',
            // Espejo exacto de la anterior, no una variante: las dos aparecen
            // juntas en el tablero de producción (devolver / avanzar) y un asta
            // más corta en una se leería como dos iconos de familias distintas.
            'flecha-izquierda' => '<path d="M19 12H6"/><path d="m12 6-6 6 6 6"/>',

            // ── Estado y variación ────────────────────────────────────
            // Sustituyen a ▲ y ▼ en el histórico de precios.
            'sube' => '<path d="m6 15 6-7 6 7"/>',
            'baja' => '<path d="m6 9 6 7 6-7"/>',
            'estrella' => '<path d="m12 3 2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.9 6.1 21l1.2-6.5L2.5 9.9 9.1 9Z"/>',
            'alerta' => '<path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>',
            'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',

            // ── Cuadrantes de ingeniería de menú (analíticas) ─────────
            //
            // Sustituyen a ⭐ 🐎 ❓ 🐕. Los cuatro son metáforas del modelo de
            // Kasavana-Smith, así que el dibujo dice la POSICIÓN y no ilustra
            // el animal: la misma rejilla de 2×2 en los cuatro, con la celda
            // que le toca rellena. Es literalmente el mapa de dispersión de la
            // pestaña —popularidad en horizontal, margen en vertical—, así que
            // el icono enseña a leer la gráfica en vez de pedir que se aprenda
            // un símbolo más.
            //
            //   estrella  = mucha demanda y buen margen  → arriba a la derecha
            //   vaca      = mucha demanda, margen flojo  → abajo a la derecha
            //   incognita = poca demanda, buen margen    → arriba a la izquierda
            //   perro     = poca demanda y margen flojo  → abajo a la izquierda
            //
            // Son los únicos iconos del catálogo con un relleno (la celda): es
            // lo que los hace distinguibles entre sí a 14 px, donde un trazo
            // de más o de menos no se ve.
            'cuadrante-estrella' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 3v18"/><path d="M3 12h18"/><path d="M12 3h7a2 2 0 0 1 2 2v7h-9Z" fill="currentColor"/>',
            'cuadrante-vaca' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 3v18"/><path d="M3 12h18"/><path d="M12 12h9v7a2 2 0 0 1-2 2h-7Z" fill="currentColor"/>',
            'cuadrante-incognita' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 3v18"/><path d="M3 12h18"/><path d="M12 3H5a2 2 0 0 0-2 2v7h9Z" fill="currentColor"/>',
            'cuadrante-perro' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 3v18"/><path d="M3 12h18"/><path d="M12 12H3v7a2 2 0 0 0 2 2h7Z" fill="currentColor"/>',
        ];

        return $iconos;
    }
}

if (!function_exists('admin_icon')) {
    /**
     * Devuelve el marcado de un icono del panel.
     *
     * El `stroke-width` no es un parámetro a propósito: el grosor lo fija el CSS
     * del contexto (el sidebar, el topbar y los botones tienen el suyo), y
     * dejarlo pasar por aquí habría reproducido el problema que este archivo
     * resuelve — el mismo icono con tres grosores según quién lo escribiera.
     *
     * Un nombre desconocido devuelve cadena vacía en vez de un icono de relleno:
     * un hueco se ve en la primera pasada; un icono equivocado se queda años.
     *
     * @param string $nombre Clave del catálogo (ver admin_icon_paths()).
     * @param int    $tamano Lado de la caja en px.
     * @param string $clase  Clases CSS extra para el <svg>.
     */
    function admin_icon(string $nombre, int $tamano = 18, string $clase = ''): string
    {
        $paths = admin_icon_paths()[$nombre] ?? '';
        if ($paths === '') {
            return '';
        }

        $atributoClase = $clase !== ''
            ? ' class="' . htmlspecialchars($clase, ENT_QUOTES, 'UTF-8') . '"'
            : '';

        return '<svg' . $atributoClase . ' viewBox="0 0 24 24" width="' . $tamano . '" height="' . $tamano . '"'
            . ' fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"'
            . ' aria-hidden="true" focusable="false">' . $paths . '</svg>';
    }
}
