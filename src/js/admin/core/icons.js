/**
 * Iconos del panel, para el JS.
 *
 * Pareja de views/admin/partials/_icons.php: los mismos trazos, para los
 * módulos que pintan su marcado con concatenación de cadenas y no pueden pedir
 * el helper de PHP.
 *
 * Existe por lo mismo que su gemelo: quedaban emojis y glifos sueltos haciendo
 * de icono (⭐ 🐎 ❓ 🐕 en la ingeniería de menú, ✓ en la operación, → en el
 * buzón, ▲▼ en el histórico de precios). Un emoji lo pinta la fuente del
 * sistema, así que no hereda `currentColor` —se queda del color que le dé la
 * plataforma aunque el texto de al lado cambie con el tema—, cambia de forma
 * entre Windows, Android e iOS y se sale de la caja tipográfica.
 *
 * Viaja en admin.js, que el layout carga ANTES que cualquier bundle de módulo
 * (views/admin/layout.php): los módulos pueden contar con window.AdminIcons.
 *
 * Al añadir un icono aquí, añadirlo también en _icons.php si alguna vista lo
 * necesita: son dos catálogos y no uno porque los consumidores viven en dos
 * lenguajes, no porque deban divergir.
 */
(function (window, document) {
    'use strict';

    var TRAZOS = {
        check: '<path d="M20 6 9 17l-5-5"/>',
        cerrar: '<path d="M6 6l12 12"/><path d="M18 6 6 18"/>',
        externo: '<path d="M7 17 17 7"/><path d="M7 7h10v10"/>',
        'flecha-derecha': '<path d="M5 12h13"/><path d="m12 6 6 6-6 6"/>',
        // Espejo exacto de la anterior, no una variante: las dos aparecen juntas
        // en el tablero de producción (devolver / avanzar) y un asta más corta
        // en una de ellas se leería como dos iconos de familias distintas.
        'flecha-izquierda': '<path d="M19 12H6"/><path d="m12 6-6 6 6 6"/>',
        sube: '<path d="m6 15 6-7 6 7"/>',
        baja: '<path d="m6 9 6 7 6-7"/>',
        alerta: '<path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>',
        info: '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',

        // Cuadrantes de la matriz de Kasavana-Smith: la misma rejilla de 2×2
        // con la celda que le toca rellena. Dice la POSICIÓN en el mapa de
        // dispersión (popularidad en horizontal, margen en vertical), no el
        // animal de la metáfora. Ver _icons.php.
        'cuadrante-estrella': '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 3v18"/><path d="M3 12h18"/><path d="M12 3h7a2 2 0 0 1 2 2v7h-9Z" fill="currentColor"/>',
        'cuadrante-vaca': '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 3v18"/><path d="M3 12h18"/><path d="M12 12h9v7a2 2 0 0 1-2 2h-7Z" fill="currentColor"/>',
        'cuadrante-incognita': '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 3v18"/><path d="M3 12h18"/><path d="M12 3H5a2 2 0 0 0-2 2v7h9Z" fill="currentColor"/>',
        'cuadrante-perro': '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 3v18"/><path d="M3 12h18"/><path d="M12 12H3v7a2 2 0 0 0 2 2h7Z" fill="currentColor"/>'
    };

    /**
     * Marcado de un icono.
     *
     * Un nombre desconocido devuelve cadena vacía, no un icono de relleno: un
     * hueco se detecta en la primera pasada, un icono equivocado dura años.
     *
     * El stroke-width no se parametriza: lo fija el CSS del contexto, que es lo
     * que evita que el mismo icono acabe con tres grosores según quién lo pinte.
     */
    function icono(nombre, tamano) {
        var trazos = TRAZOS[nombre];
        if (!trazos) return '';
        var lado = tamano || 16;
        return '<svg viewBox="0 0 24 24" width="' + lado + '" height="' + lado + '"' +
            ' fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"' +
            ' aria-hidden="true" focusable="false">' + trazos + '</svg>';
    }

    window.AdminIcons = {
        get: icono,
        trazos: TRAZOS
    };
}(window, document));
