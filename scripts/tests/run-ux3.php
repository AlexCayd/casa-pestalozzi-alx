<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use Services\Security\RetornoAdministrativo as R;
$n=0;$a=static function(bool $ok,string $m)use(&$n){if(!$ok)throw new RuntimeException($m);$n++;};$si=static fn()=>true;
foreach(['restaurante','panaderia','global'] as $ctx){$base=$ctx==='restaurante'?'/admin':'/admin/'.$ctx;$url=$base.'/recetas/articulos/editar?id=4&version=7&comparar=8';$a(R::validar($url,$ctx,$si)===$url,'Conservar consulta y comparación '.$ctx);$a(R::validar($url,$ctx,static fn($p,$q)=>!isset($q['version']))===null,'Revalidar autorización de versión');}
$url='/admin/global/productos/presentaciones?id=5&referencia=pieza_estandar';$a(R::validar($url,'global',$si)===$url,'Referencia oficial estable');
foreach(['/admin/menu/variantes?id=5&version=7','/admin/recetas/articulos/editar?id=4&comparar=8','/admin/global/productos/presentaciones?id=5&referencia[]=x','/admin/global/productos/presentaciones?id=5&referencia=%0a','/admin/global/productos/presentaciones?id=5&referencia=Clave-Invalida','/admin/global/productos/presentaciones?id=5&version=2'] as $bad)$a(R::validar($bad,str_starts_with($bad,'/admin/global/')?'global':'restaurante',$si)===null,'Rechazar parámetro ajeno al tipo de pantalla');
$origen='/admin/panaderia/menu/presentaciones?id=4&presentacion=7';$url='/admin/panaderia/menu/variantes?'.http_build_query(['id'=>4,'volver'=>$origen]);$a(R::validar($url,'panaderia',$si)===$url,'Conservar presentación al visitar variantes');$a(R::validar($url,'restaurante',$si)===null,'No cruzar negocios por retorno');
echo "UX3 retornos: {$n} comprobaciones OK.\n";
