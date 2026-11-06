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

        // Variantes de platillo (estado vacío del editor). Pareja de
        // _icons.php: dos opciones, una elegida (llena) y otra no, que es lo
        // que son las variantes (se elige una por grupo) y lo que dice el
        // listado («3 opciones»). Los aros mandan sobre las líneas para que se
        // lea «elige una» y no «lista con viñetas», y miden r=3.25 para que el
        // vacío siga hueco a 13 px.
        variantes: '<circle cx="7" cy="6.5" r="3.25" fill="currentColor"/><path d="M14 6.5h7"/><circle cx="7" cy="17.5" r="3.25"/><path d="M14 17.5h7"/>',
        // Ajustar la receta de UNA opción (el resumen «Salsa verde → Salsa
        // roja» de cada fila del editor). Son los deslizadores que antes eran
        // `variantes`: ahí sí dicen lo que hace el botón, y el icono de
        // opciones, al lado de la columna de radios «Base», se leía como otro
        // radio. Sólo JS: ninguna vista PHP lo pinta.
        ajustes: '<path d="M4 7h10"/><path d="M18 7h2"/><circle cx="16" cy="7" r="2"/><path d="M4 17h4"/><path d="M12 17h8"/><circle cx="10" cy="17" r="2"/><path d="M4 12h2"/><path d="M10 12h10"/><circle cx="8" cy="12" r="2"/>',
        // Opciones de variante (op-*): el POS las pinta en cada opción del
        // paso y el editor de Menú las ofrece al elegir icono. Pareja exacta
        // de _icons.php y de Services\Menu\Variantes::ICONOS (la lista
        // permitida del servidor); run-variantes-iconos.php vigila las tres.
        'op-taza': '<path d="M5 9h11v5a5 5 0 0 1-5 5h-1a5 5 0 0 1-5-5Z"/><path d="M16 11h1.5a2.5 2.5 0 0 1 0 5H16"/><path d="M4 21h14"/>',
        'op-caliente': '<path d="M5 11h11v3a5 5 0 0 1-5 5h-1a5 5 0 0 1-5-5Z"/><path d="M16 12.5h1.5a2.25 2.25 0 0 1 0 4.5H16"/><path d="M8 3.5c-.9 1 .9 2 0 3.5"/><path d="M11.5 3.5c-.9 1 .9 2 0 3.5"/><path d="M4 21h14"/>',
        'op-frio': '<path d="M12 3v18"/><path d="m4.2 7.5 15.6 9"/><path d="m4.2 16.5 15.6-9"/><path d="m9.5 4.5 2.5 2 2.5-2"/><path d="m9.5 19.5 2.5-2 2.5 2"/>',
        'op-tamano-chico': '<rect x="8" y="11.5" width="8" height="2" rx="1"/><path d="M9 13.5h6l-.8 6.5H9.8Z"/>',
        'op-tamano-mediano': '<rect x="7" y="7.5" width="10" height="2" rx="1"/><path d="M8 9.5h8l-1.1 10.5H9.1Z"/>',
        'op-tamano-grande': '<rect x="6" y="3.5" width="12" height="2" rx="1"/><path d="M7 5.5h10l-1.4 14.5H8.4Z"/>',
        'op-leche': '<path d="M7 9.5h10V20a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1Z"/><path d="M7 9.5 9.5 5h5L17 9.5"/><path d="M9.5 5V3h5v2"/><path d="M10 14.5h4"/>',
        'op-planta': '<path d="M5 19c0-8 5-13 14-14-.5 9-5.5 14-14 14Z"/><path d="M5 19l8-8"/>',
        'op-grano': '<ellipse cx="12" cy="12" rx="5.5" ry="8.5" transform="rotate(35 12 12)"/><path d="M15.2 6.3c-3.4 1.4-2.8 4.4-3.2 5.7-.4 1.3-1.6 3.7-3.2 5.7"/>',
        'op-sin': '<circle cx="12" cy="12" r="8.5"/><path d="m6 6 12 12"/>',
        'op-extra': '<circle cx="12" cy="12" r="8.5"/><path d="M12 8v8"/><path d="M8 12h8"/>',
        'op-doble': '<circle cx="9" cy="12" r="5.5"/><circle cx="15" cy="12" r="5.5"/>',
        'op-mitad': '<circle cx="12" cy="12" r="8.5"/><path d="M12 3.5a8.5 8.5 0 0 0 0 17Z" fill="currentColor"/>',
        'op-plato': '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="5"/>',
        'op-termino-rojo': '<path d="M9.5 14.2V5a2.5 2.5 0 0 1 5 0v9.2a4 4 0 1 1-5 0Z"/><circle cx="12" cy="17.3" r="2.6" fill="currentColor"/>',
        'op-termino-medio': '<path d="M9.5 14.2V5a2.5 2.5 0 0 1 5 0v9.2a4 4 0 1 1-5 0Z"/><circle cx="12" cy="17.3" r="2.6" fill="currentColor"/><rect x="10.6" y="10.5" width="2.8" height="6.5" rx="1.4" fill="currentColor"/>',
        'op-termino-tres-cuartos': '<path d="M9.5 14.2V5a2.5 2.5 0 0 1 5 0v9.2a4 4 0 1 1-5 0Z"/><circle cx="12" cy="17.3" r="2.6" fill="currentColor"/><rect x="10.6" y="7.5" width="2.8" height="9.5" rx="1.4" fill="currentColor"/>',
        'op-termino-bien': '<path d="M9.5 14.2V5a2.5 2.5 0 0 1 5 0v9.2a4 4 0 1 1-5 0Z"/><circle cx="12" cy="17.3" r="2.6" fill="currentColor"/><rect x="10.6" y="4" width="2.8" height="13" rx="1.4" fill="currentColor"/>',
        'op-fuego': '<path d="M12 21a6 6 0 0 0 6-6c0-3.6-2.4-5.6-3.6-9-1.4 1.9-1.9 3.4-1.9 5-1.5-.8-2.6-2.3-2.6-4C7.6 9 6 11.6 6 15a6 6 0 0 0 6 6Z"/>',
        'op-picante': '<path d="M17.5 7.5C19 10 18 15 14 18s-8.5 3-10 2c3-1 5-3.5 6.5-6.5S13.5 8 17.5 7.5Z"/><path d="M17.5 7.5c0-2 1-3.5 2.5-4"/>',
        'op-dulce': '<circle cx="12" cy="12" r="4"/><path d="M8.5 10 4 7.5v9L8.5 14"/><path d="M15.5 10 20 7.5v9L15.5 14"/>',
        'op-queso': '<path d="M3 19v-7l12-7 6 4v10Z"/><path d="M3 12h18"/><circle cx="8.5" cy="15.5" r="1.7"/><circle cx="15.5" cy="14.6" r=".6" fill="currentColor"/><circle cx="13.2" cy="17.2" r=".5" fill="currentColor"/>',
        'op-huevo': '<path d="M12 3c-3.5 0-6.5 5.5-6.5 10a6.5 6.5 0 0 0 13 0C18.5 8.5 15.5 3 12 3Z"/>',
        'op-pan': '<path d="M5.5 20v-9a3.5 3.5 0 0 1-1-6.3C6 3.6 9 3 12 3s6 .6 7.5 1.7a3.5 3.5 0 0 1-1 6.3v9Z"/>',
        'op-carne': '<path d="M16 4c3 1 5 4 4.5 7.5-.5 4-4.5 8.5-10 8.5C6.5 20 4 18 4 15.5c0-3 2.5-4 4-6.5S12 3 16 4Z"/><circle cx="15" cy="9.5" r="2"/>',
        'op-pollo': '<g transform="rotate(45 12 12)"><path d="M10.5 16.5C9 14.5 6.5 12 6.5 8.5a5.5 5.5 0 0 1 11 0c0 3.5-2.5 6-4 8Z"/><path d="M12 16.5v3"/><circle cx="10.7" cy="20.4" r="1.4" fill="currentColor"/><circle cx="13.3" cy="20.4" r="1.4" fill="currentColor"/></g>',
        'op-pescado': '<path d="M6 12c2.5-3.5 5.5-5 8.5-5 3 0 5.5 2.5 6.5 5-1 2.5-3.5 5-6.5 5-3 0-6-1.5-8.5-5Z"/><path d="M6 12 2.5 8.5v7Z"/><circle cx="16.5" cy="11" r=".9" fill="currentColor"/>',
        'op-ensalada': '<path d="M3 12h18a9 9 0 0 1-18 0Z"/><path d="M7.5 12c0-3 2-5.5 5-5.5"/><path d="M12.5 12c0-3.5 2.5-6.5 6-6.5"/>',
        'op-fruta': '<path d="M12 7.5c-1.6-1-5-1.5-6.5 1.5S5 16 7 18.5s3.5 2 5 1.5c1.5.5 3 1 5-1.5s2.5-6.5 1-9.5S13.6 6.5 12 7.5Z"/><path d="M12 7.5c0-2 1-3.5 2.5-4.5"/>',
        'op-chocolate': '<rect x="6" y="3" width="12" height="18" rx="1.5"/><path d="M6 9h12"/><path d="M6 15h12"/><path d="M12 3v18"/>',
        'op-limon': '<path d="M3.5 8.5a8.5 8.5 0 0 0 17 0Z"/><path d="M6.5 8.5a5.5 5.5 0 0 0 11 0"/><path d="M12 8.5V14"/><path d="m12 8.5-4 4"/><path d="m12 8.5 4 4"/>',
        'op-copa': '<path d="M8 3h8l-.5 5a3.5 3.5 0 0 1-7 0Z"/><path d="M12 11.5V20"/><path d="M8.5 20h7"/>',
        'op-vaso': '<path d="M6 4h12l-1.5 15.5a1.5 1.5 0 0 1-1.5 1.5H9a1.5 1.5 0 0 1-1.5-1.5Z"/><path d="M6.7 10h10.6"/>',
        'op-botella': '<path d="M10 2.5h4"/><path d="M10.5 2.5v4L8.5 10v10a1.5 1.5 0 0 0 1.5 1.5h4a1.5 1.5 0 0 0 1.5-1.5V10l-2-3.5v-4"/><path d="M8.5 13.5h7"/>',
        'op-estrella': '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9Z"/>',
        'op-bolsa': '<path d="M5 8h14l-1 12H6Z"/><path d="M9 8V7a3 3 0 0 1 6 0v1"/>',
        // Los que separan opciones que antes compartían trazo: tres huevos
        // (estrellado con su yema, revuelto en la sartén, pochado en el agua),
        // almendra y avena (las dos eran `op-planta`), camarón (era el pez) y
        // la presentación individual o para compartir.
        'op-huevo-estrellado': '<path d="M8.6 4.6c2-1.4 4.6-.9 6.3.4 1.4 1 3.4 1 4.6 2.6 1.4 1.8.6 4 .9 5.8.4 2.3-.6 4.6-2.8 5.6-1.8.8-3.6.4-5.3 1-2 .7-4.4.4-5.8-1.2-1.3-1.5-1-3.3-1.9-4.9C3.6 12.3 3 10.2 4.2 8.4 5.3 6.7 7 5.7 8.6 4.6Z"/><circle cx="11.5" cy="11.8" r="3" fill="currentColor"/>',
        // Las motas sin trazo: con él engordaban, se tocaban y a 26 px la
        // sartén parecía llevar una mancha.
        'op-huevo-revuelto': '<circle cx="10" cy="13" r="7"/><path d="M17 13h4.5"/><g fill="currentColor" stroke="none"><circle cx="7.8" cy="11.2" r="1.4"/><circle cx="11.9" cy="10.6" r="1.1"/><circle cx="10.4" cy="14.6" r="1.6"/><circle cx="7.1" cy="15.1" r="1.1"/><circle cx="13.8" cy="14.3" r="1.1"/></g>',
        'op-huevo-pochado': '<path d="M12 3c-2.9 0-5 3.7-5 7.2a5 5 0 0 0 10 0C17 6.7 14.9 3 12 3Z"/><path d="M3 19.5c1.5-1.2 3-1.2 4.5 0s3 1.2 4.5 0 3-1.2 4.5 0 3 1.2 4.5 0"/>',
        'op-almendra': '<g transform="rotate(30 12 12)"><path d="M12 3c3.6 2.8 5.5 6.9 5.5 10.5a5.5 5.5 0 0 1-11 0C6.5 9.9 8.4 5.8 12 3Z"/><path d="M12 7c-1.4 2.2-1.8 5-1 8"/></g>',
        'op-avena': '<path d="M12 21V8.5"/><path d="M12 8.5c-1.1-1-1.3-3 0-5 1.3 2 1.1 4 0 5Z"/><path d="M12 13c-2.5 0-4.2-1.4-4.7-3.6 2.5 0 4.2 1.4 4.7 3.6Z"/><path d="M12 13c2.5 0 4.2-1.4 4.7-3.6-2.5 0-4.2 1.4-4.7 3.6Z"/><path d="M12 17.5c-2.5 0-4.2-1.4-4.7-3.6 2.5 0 4.2 1.4 4.7 3.6Z"/><path d="M12 17.5c2.5 0 4.2-1.4 4.7-3.6-2.5 0-4.2 1.4-4.7 3.6Z"/>',
        'op-camaron': '<path d="M4 9.5c0-2.5 2-4.5 4.5-4.5H14a6 6 0 0 1 0 12h-1.5"/><path d="M4 9.5c0 1.7 1.3 3 3 3h7a1.8 1.8 0 0 1 0 3.5"/><path d="M12.5 17l-2.5 3.5"/><path d="M12.5 17l1 3.8"/><path d="M8.5 5v7.5"/><path d="M12 5v7.5"/><path d="M15.5 5.3v7"/><path d="M4 9.5 2 7.5"/>',
        'op-individual': '<circle cx="12" cy="8" r="3.5"/><path d="M5.5 20a6.5 6.5 0 0 1 13 0"/>',
        'op-compartir': '<circle cx="8" cy="8.5" r="3"/><circle cx="16" cy="8.5" r="3"/><path d="M2.5 19.5a5.5 5.5 0 0 1 11 0"/><path d="M10.5 19.5a5.5 5.5 0 0 1 11 0"/>',
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
