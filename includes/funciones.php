<?php

function debuguear($variable) : string {
    echo "<pre>";
    var_dump($variable);
    echo "</pre>";
    exit;
}
function s($html) : string {
    $s = htmlspecialchars($html);
    return $s;
}

/**
 * Añade a un asset local la marca de tiempo de su archivo como `?v=`.
 *
 * Los layouts versionaban a mano el bundle compartido (`admin.css?v=pulido-v2`)
 * y emitían los de módulo **sin nada**, así que al recompilar un SCSS de módulo
 * el navegador seguía sirviendo el suyo de caché. El caso que lo puso a la
 * vista: `finanzas.css` dejó de declarar alto para el Sankey y `sankey.js` pasó
 * a ponerlo en píxeles; con la hoja vieja en caché la caja seguía midiendo
 * `clamp(380px, 40vw, 480px)` y bajo el diagrama quedaba una franja muerta.
 *
 * Se SOBRESCRIBE el parámetro `v` en vez de respetarlo: los que estaban
 * escritos a mano (`?v=pos-settings-v1`) hay que acordarse de subirlos, y es
 * justo lo que no pasa. Dos `v` en la misma URL además es indefinido. El resto
 * de parámetros se conservan.
 *
 * Sólo se toca lo que es local y existe: una URL absoluta o un archivo que no
 * está sale igual que entró.
 */
function recursoVersionado(string $ruta) : string {
    static $cache = [];

    if ($ruta === '' || $ruta[0] !== '/' || str_starts_with($ruta, '//') || str_contains($ruta, '://')) {
        return $ruta;
    }
    if (isset($cache[$ruta])) {
        return $cache[$ruta];
    }

    $partes  = explode('?', $ruta, 2);
    $camino  = $partes[0];
    $archivo = dirname(__DIR__) . '/public' . $camino;

    // is_file antes de filemtime: con display_errors encendido el warning se
    // imprimiría dentro del <head>.
    if (!is_file($archivo)) {
        return $cache[$ruta] = $ruta;
    }

    $params = [];
    if (isset($partes[1]) && $partes[1] !== '') {
        parse_str($partes[1], $params);
    }
    $params['v'] = (string)filemtime($archivo);

    return $cache[$ruta] = $camino . '?' . http_build_query($params);
}
