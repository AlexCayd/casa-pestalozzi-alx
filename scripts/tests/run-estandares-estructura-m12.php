<?php
require __DIR__.'/soporte-m12.php';
m12Pruebas(static function(mysqli $db,string $root,callable $a){
    $snapshot=static fn()=>hash('sha256',json_encode($db->query('SELECT * FROM productos ORDER BY id')->fetch_all(MYSQLI_ASSOC)));
    $antes=$snapshot();
    Multinegocio\SqlArchivo::ejecutar($db,$root.'/database/actualizaciones/m12-estandares.sql');
    Multinegocio\SqlArchivo::ejecutar($db,$root.'/database/actualizaciones/m12-estandares.sql');
    $a($antes===$snapshot(),'Migración repetida conserva todos los productos');
    $a((int)$db->query('SELECT COUNT(*) FROM producto_estandares')->fetch_row()[0]===0,'Ningún producto previo queda estandarizado');
    $a((int)$db->query('SELECT COUNT(*) FROM productos_oferta v JOIN ofertas_producto o ON o.id=v.oferta_id JOIN productos p ON p.id=o.producto_id WHERE NOT(v.nombre<=>p.nombre AND v.descripcion<=>p.descripcion AND v.img<=>p.img)')->fetch_row()[0]===0,'Proyección inicial idéntica al catálogo previo');
    foreach(['producto_publicaciones','producto_estandares','oferta_personalizaciones','recetas_casa','receta_casa_versiones','receta_casa_componentes','receta_origen_casa'] as $t)$a((bool)$db->query('SHOW CREATE TABLE '.$t)->fetch_row(),'Entidad aditiva '.$t);
});
