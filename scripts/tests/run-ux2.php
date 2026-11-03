<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use Services\Security\RetornoAdministrativo as R;
use Services\Security\InicioAdministrativo as I;
use Services\Security\PermisosUsuario as P;
$n=0;$a=static function(bool $ok,string $m)use(&$n){if(!$ok)throw new RuntimeException($m);$n++;};
$si=static fn()=>true;
foreach(['global','restaurante','panaderia'] as $ctx){
 $base=$ctx==='restaurante'?'/admin':'/admin/'.$ctx;
 $ruta=$base.'/recetas/articulos?q=Harina+integral&pagina=2';
 $a(R::validar($ruta,$ctx,$si)===$ruta,'Conservar filtro y página '.$ctx);
 $a(R::validar($base.($ctx==='global'?'/productos':'/menu').'/presentaciones?id=1003'.($ctx==='global'?'':'&presentacion=7').'#origenes',$ctx,$si)!==null,'Conservar presentación y sección');
 $a(R::validar($ruta,$ctx,static fn()=>false)===null,'Revalidar permisos');
 $perfil=['activo'=>true,'ambitos'=>[$ctx=>['permisos'=>['productos'=>'ver'],'facultades'=>[]]]];
 $inicio=I::preparar($perfil,$ctx,'contrasena');$cards=[];
 foreach($inicio['seccionesInicio'] as $section)$cards=array_merge($cards,$section['tarjetas']);
 $a(count($cards)===1&&$cards[0]['titulo']==='Artículos y preparaciones'&&$cards[0]['ruta']===$base.'/recetas/articulos'&&$cards[0]['icono']==='products','Identidad de Inicio en '.$ctx);
 $a(P::catalogo($ctx)['productos']['ruta']===$base.'/recetas/articulos','Conservar clave técnica productos');
 $perfil['ambitos'][$ctx]['permisos']=[];$inicio=I::preparar($perfil,$ctx,'contrasena');
 foreach($inicio['seccionesInicio'] as $section)$a($section['tarjetas']===[],'Sin tarjetas no autorizadas');
}
foreach(['https://example.com/admin/menu','//example.com/admin/menu','/admin/menu%0d%0aLocation:x',"/admin/menu\r\n",'/admin/../admin/menu','/admin/panaderia/menu','/admin/menu?id[]=1','/admin/menu?id=0','/admin/menu?id=-1','/admin/menu?otro=1','/admin/menu?q=%0a','/admin/menu#otro',[]] as $bad)$a(R::validar($bad,'restaurante',$si)===null,'Rechazar retorno no permitido');
$a(R::validar('/admin/menu/edit?id=7','restaurante',static fn($p,$q)=>($q['id']??'')!=='7')===null,'Rechazar ID ajeno mediante autorizador');
$r='/admin/menu?q=Pan';for($i=0;$i<4;$i++)$r='/admin/menu/edit?'.http_build_query(['id'=>1003,'volver'=>$r]);
$a(R::validar($r,'restaurante',$si)!==null,'Cadena acotada de retorno independiente de sesión');
$r='/admin/menu/edit?'.http_build_query(['id'=>1003,'volver'=>$r]);$a(R::validar($r,'restaurante',$si)===null,'Limitar profundidad de retorno');
echo "UX2: {$n} comprobaciones OK.\n";
