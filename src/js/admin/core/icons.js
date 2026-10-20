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
 * También viaja en bundle.min.js (gulpfile.js, `paths.js`): los componentes
 * compartidos —aviso y diálogo de confirmación— lo piden en la landing.
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
        // El aviso de error (components/toast.js). Hace pareja con `alerta`:
        // error y advertencia se separan por FORMA —círculo con aspa frente a
        // triángulo—, no sólo por el rojo y el ámbar.
        error: '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6"/><path d="m15 9-6 6"/>',
        // Lo que se gasta de un ingrediente («Consumo» en el Asistente).
        // Pareja de _icons.php.
        gota: '<path d="M12 3.5c3.2 3.9 6 7.3 6 10.6a6 6 0 0 1-12 0c0-3.3 2.8-6.7 6-10.6Z"/><path d="M9.2 14.6a2.9 2.9 0 0 0 2.4 2.6"/>',

        // Asistente IA. `ia` es la marca del asistente (pareja de _icons.php,
        // que la usa en el sidebar); `basura` es el mismo trazo que allí. Los
        // otros dos sólo los pinta el JS de la conversación: `reintentar` es
        // el espejo de `ahora` sin las manecillas, para que las dos flechas
        // circulares del catálogo sean de la misma familia.
        ia: '<path d="M11 6Q12 12 18 13Q12 14 11 20Q10 14 4 13Q10 12 11 6Z"/><path d="M19 3v4"/><path d="M17 5h4"/>',
        copiar: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2"/>',
        reintentar: '<path d="M20.5 12a8.5 8.5 0 1 1-2.6-6.1"/><path d="M20.5 4.5V10H15"/>',
        basura: '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5"/><path d="M14 11v5"/>',

        // Variantes de platillo (editor del Menú) y consultas rápidas. Pareja
        // de _icons.php: `variantes` son tres deslizadores, el gesto de
        // «ajustar» que el mesero reconoce del selector del POS.
        variantes: '<path d="M4 7h10"/><path d="M18 7h2"/><circle cx="16" cy="7" r="2"/><path d="M4 17h4"/><path d="M12 17h8"/><circle cx="10" cy="17" r="2"/><path d="M4 12h2"/><path d="M10 12h10"/><circle cx="8" cy="12" r="2"/>',
        mas: '<path d="M12 5v14"/><path d="M5 12h14"/>',
        buscar: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        editar: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        // Rubros de las consultas rápidas: almacén y ventas. Mismo trazo que
        // las entradas del sidebar en _icons.php.
        inventario: '<path d="M3 7l9-4 9 4-9 4-9-4Z"/><path d="M3 7v10l9 4 9-4V7"/><path d="M12 11v10"/>',
        tickets: '<path d="M6 3h12v18l-2-1-2 1-2-1-2 1-2-1-2 1V3Z"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h4"/>',

        // Estados de mesa del POS: el mismo círculo y una marca distinta
        // dentro. La forma, no el color, es lo que separa verde de rojo con
        // daltonismo. Pareja de _icons.php.
        'estado-libre': '<circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5.5"/>',
        'estado-ocupada': '<circle cx="12" cy="12" r="9"/><path d="M8 12h8"/>',
        'estado-reservada': '<circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3 2"/>',
        'estado-sin-llegar': '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5"/><path d="M12 16.2h.01"/>',
        // Reservaciones en el POS: barra de contexto, cajón y ficha.
        'chevron-izquierda': '<path d="m15 6-6 6 6 6"/>',
        'chevron-derecha': '<path d="m9 6 6 6-6 6"/>',
        // Los huecos de las consultas rápidas: «esto se despliega». No es
        // `baja`, que es la tendencia de una cifra: el mismo trazo diciendo
        // «bajó» y «ábreme» en la misma tarjeta se leería como dato.
        'chevron-abajo': '<path d="m6 9 6 6 6-6"/>',
        ahora: '<path d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1"/><path d="M3.5 4.5V10H9"/><path d="M12 8v4.5l3 1.8"/>',
        reloj: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.2l3.2 1.9"/>',
        plano: '<rect x="3" y="4" width="7" height="7" rx="1"/><rect x="14" y="4" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        grupo: '<path d="M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        reubicar: '<path d="M4 8h13l-3.5-3.5"/><path d="M20 16H7l3.5 3.5"/>',
        nota: '<path d="M5 4h14v11l-5 5H5z"/><path d="M14 20v-5h5"/><path d="M8 9h8M8 12.5h5"/>',
        ayuda: '<circle cx="12" cy="12" r="9"/><path d="M9.7 9a2.4 2.4 0 1 1 3.8 1.9c-1 .7-1.5 1.1-1.5 2.6M12 17h.01"/>',
        // Fichas del mapa: la bolsa de Llevar y el recibo del corte de caja.
        bolsa: '<path d="M5 8h14l-1 12H6Z"/><path d="M9 8V7a3 3 0 0 1 6 0v1"/>',
        caja: '<path d="M6 3h12v18l-2-1-2 1-2-1-2 1-2-1-2 1V3Z"/><path d="M9 8h6"/><path d="M9 12h6"/>',

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
