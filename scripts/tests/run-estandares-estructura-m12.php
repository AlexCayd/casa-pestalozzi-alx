<?php
require __DIR__.'/soporte-m12.php';
m12Pruebas(static function(mysqli $db,string $root,callable $a){
    // El bloque M1.2 vive sólo en ddl.sql (database/ lleva ddl, deploy y
    // development; sin migraciones aparte): la base temporal ya lo trae.
    $a(!is_dir($root.'/database/actualizaciones'),'Sin migraciones aparte: el esquema M1.2 está en ddl.sql');
    $a((int)$db->query('SELECT COUNT(*) FROM producto_estandares WHERE producto_id<1000')->fetch_row()[0]===0,'Ningún producto original queda estandarizado');
    $a((int)$db->query('SELECT COUNT(*) FROM productos_oferta v JOIN ofertas_producto o ON o.id=v.oferta_id JOIN productos p ON p.id=o.producto_id WHERE p.id<1000 AND NOT(v.nombre<=>p.nombre AND v.descripcion<=>p.descripcion AND v.img<=>p.img)')->fetch_row()[0]===0,'Proyección inicial idéntica para los 112 originales');
    foreach(['producto_publicaciones','producto_estandares','oferta_personalizaciones','recetas_casa','receta_casa_versiones','receta_casa_componentes','receta_origen_casa','producto_cambios_locales'] as $t)$a((bool)$db->query('SHOW CREATE TABLE '.$t)->fetch_row(),'Entidad aditiva '.$t);
    $a((int)$db->query('SELECT COUNT(*) FROM producto_estandares')->fetch_row()[0]===6,'Adhesión exclusivamente explícita de seis fixtures');
    $a((int)$db->query('SELECT COUNT(*) FROM receta_casa_versiones WHERE publicada=1')->fetch_row()[0]===4,'Cuatro recetas estándar de fixtures publicadas');
    $a((int)$db->query('SELECT COUNT(*) FROM receta_origen_casa')->fetch_row()[0]===8,'Dos ofertas siguen cada receta estándar de fixture');
    $a($db->query('SELECT img FROM productos_oferta WHERE oferta_id=1207')->fetch_row()[0]!==$db->query('SELECT img FROM productos_oferta WHERE oferta_id=1404')->fetch_row()[0],'Focaccia permite imágenes propias sin duplicar identidad');
    $a((int)$db->query('SELECT COUNT(*) FROM movimientos_inventario')->fetch_row()[0]===51,'Movimientos físicos preservados');
});
