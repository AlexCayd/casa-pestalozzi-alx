<?php

/** Datos ilustrativos: no cargar en el catálogo operativo. IDs estables. */
return [
    'version' => 1,
    'articulos' => [
        'harina' => ['nombre' => 'Harina de trigo', 'unidad' => 'kg', 'existencia' => 20, 'minimo' => 5],
        'leche' => ['nombre' => 'Leche entera', 'unidad' => 'l', 'existencia' => 8, 'minimo' => 10],
        'mantequilla' => ['nombre' => 'Mantequilla', 'unidad' => 'kg', 'existencia' => 4, 'minimo' => 2],
        'azucar' => ['nombre' => 'Azúcar', 'unidad' => 'kg', 'existencia' => 10, 'minimo' => 3],
        'masa-madre' => ['nombre' => 'Masa madre', 'unidad' => 'kg', 'existencia' => 3, 'minimo' => 1],
        'rol-terminado' => ['nombre' => 'Rol de canela terminado', 'unidad' => 'pza', 'existencia' => 24, 'minimo' => 12],
        'cafe' => ['nombre' => 'Café en grano', 'unidad' => 'kg', 'existencia' => 2, 'minimo' => 1],
        'canela' => ['nombre' => 'Canela', 'unidad' => 'kg', 'existencia' => 0.2, 'minimo' => 0.1],
        'agua' => ['nombre' => 'Agua', 'unidad' => 'l', 'existencia' => 30, 'minimo' => 10],
        'sal' => ['nombre' => 'Sal', 'unidad' => 'kg', 'existencia' => 1, 'minimo' => 0.5],
        'masa-pan' => ['nombre' => 'Masa de pan fermentada', 'unidad' => 'kg', 'existencia' => 0, 'minimo' => 2],
        'pan-terminado' => ['nombre' => 'Pan de masa madre terminado', 'unidad' => 'pza', 'existencia' => 0, 'minimo' => 4],
    ],
    'productos' => [
        'rol-canela' => ['nombre' => 'Rol de canela', 'categoria' => 'Pan dulce', 'precio' => 48, 'disponible' => true, 'preparacion' => 'Producción previa', 'articulo' => 'rol-terminado'],
        'croissant' => ['nombre' => 'Croissant', 'categoria' => 'Pan dulce', 'precio' => 45, 'disponible' => false, 'preparacion' => 'Producción previa'],
        'pan-masa-madre' => ['nombre' => 'Pan de masa madre', 'categoria' => 'Pan artesanal', 'precio' => 95, 'disponible' => false, 'preparacion' => 'Preparación intermedia', 'articulo' => 'pan-terminado'],
        'capuchino' => ['nombre' => 'Capuchino', 'categoria' => 'Café', 'precio' => 55, 'disponible' => true, 'preparacion' => 'Preparación directa'],
        'espresso' => ['nombre' => 'Espresso', 'categoria' => 'Café', 'precio' => 35, 'disponible' => true, 'preparacion' => 'Preparación directa'],
    ],
    'recetas' => [
        'capuchino' => ['nombre' => 'Capuchino', 'producto' => 'capuchino', 'tipo' => 'directa', 'detalle' => 'Al vender, consumirá café y leche. No se realizan descuentos en esta demostración.', 'rendimiento' => ['cantidad' => 1, 'unidad' => 'pza'], 'ingredientes' => [
            ['articulo' => 'cafe', 'cantidad' => 0.018, 'unidad' => 'kg'], ['articulo' => 'leche', 'cantidad' => 0.18, 'unidad' => 'l'],
        ]],
        'rol-canela' => ['nombre' => 'Rol de canela', 'producto' => 'rol-canela', 'tipo' => 'produccion', 'detalle' => 'Se elaborará por lote y se venderá como pieza terminada.', 'salida' => 'rol-terminado', 'rendimiento' => ['cantidad' => 12, 'unidad' => 'pza'], 'ingredientes' => [
            ['articulo' => 'harina', 'cantidad' => 1, 'unidad' => 'kg'], ['articulo' => 'leche', 'cantidad' => 0.5, 'unidad' => 'l'], ['articulo' => 'mantequilla', 'cantidad' => 0.2, 'unidad' => 'kg'], ['articulo' => 'azucar', 'cantidad' => 0.25, 'unidad' => 'kg'], ['articulo' => 'canela', 'cantidad' => 0.02, 'unidad' => 'kg'],
        ]],
        'masa-fermentada' => ['nombre' => 'Masa de pan fermentada', 'tipo' => 'intermedia', 'detalle' => 'Preparación intermedia para elaborar pan de masa madre.', 'salida' => 'masa-pan', 'rendimiento' => ['cantidad' => 1.8, 'unidad' => 'kg'], 'ingredientes' => [
            ['articulo' => 'harina', 'cantidad' => 1, 'unidad' => 'kg'], ['articulo' => 'agua', 'cantidad' => 0.6, 'unidad' => 'l'], ['articulo' => 'masa-madre', 'cantidad' => 0.18, 'unidad' => 'kg'], ['articulo' => 'sal', 'cantidad' => 0.02, 'unidad' => 'kg'],
        ]],
        'pan-masa-madre' => ['nombre' => 'Pan de masa madre', 'producto' => 'pan-masa-madre', 'tipo' => 'produccion', 'detalle' => 'Usará la masa fermentada de la preparación intermedia. El motor de producción está pendiente.', 'salida' => 'pan-terminado', 'rendimiento' => ['cantidad' => 3, 'unidad' => 'pza'], 'ingredientes' => [
            ['articulo' => 'masa-pan', 'receta' => 'masa-fermentada', 'cantidad' => 1.8, 'unidad' => 'kg'],
        ]],
    ],
];
