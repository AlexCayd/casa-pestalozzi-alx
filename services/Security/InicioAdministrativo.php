<?php
declare(strict_types=1);
namespace Services\Security;

/** Proyecta accesos reales desde el perfil vigente; no concede permisos. */
final class InicioAdministrativo
{
    public static function preparar(array $perfil, string $ctx, string $nivel, array $documentos = []): array
    {
        $puede = static fn(string $slug, string $mod): bool => PermisosUsuario::decidir($perfil, $slug, $mod, 'ver', $nivel)['ok'];
        $administracion = [];
        $base = $ctx === 'restaurante' ? '/admin' : '/admin/'.$ctx;
        $defs = [
            'inventario'=>['Almacenes','Consulta existencias y registra operaciones físicas.','inventario',$base.'/almacenes'],
            'menu'=>[$ctx==='global'?'Productos comerciales':'Menú',$ctx==='global'?'Administra identidades compartidas y consulta su uso.':'Organiza productos, precios y disponibilidad propios.','menu',$ctx==='global'?$base.'/productos':$base.'/menu'],
            'transferencias'=>['Transferencias','Consulta envíos, recepciones e incidencias.','transferencias',$base.'/almacenes?vista=transferencias'],
            'users'=>['Usuarios','Consulta el equipo y sus accesos autorizados.','users',$base.'/usuarios'],
        ];
        foreach ($defs as $mod=>$d) {
            if (!$puede($ctx,$mod==='transferencias'?'inventario':$mod)) continue;
            if ($mod==='users' && !in_array('usuarios.gestionar',$perfil['ambitos'][$ctx]['facultades']??[],true)) continue;
            $administracion[]=['titulo'=>$d[0],'descripcion'=>$d[1],'icono'=>$d[2],'ruta'=>$d[3],'accion'=>'Abrir'];
        }
        $secciones=[];
        if ($ctx==='global') {
            $negocios=[];
            foreach (['restaurante'=>'products','panaderia'=>'inventario'] as $slug=>$icono) {
                if (!$puede($slug,'menu') && !$puede($slug,'inventario') && !$puede($slug,'users')) continue;
                $negocios[]=['titulo'=>Contextos::NOMBRES[$slug],'descripcion'=>'Cambiar al ámbito de '.Contextos::NOMBRES[$slug].'.','icono'=>$icono,'ruta'=>'/admin/contexto?contexto='.$slug,'accion'=>'Cambiar contexto'];
            }
            $secciones[]=['titulo'=>'Negocios','tarjetas'=>$negocios,'vacio'=>'No tienes negocios con módulos autorizados.'];
        }
        $secciones[]=['titulo'=>$ctx==='global'?'Administración':'Tus módulos','tarjetas'=>$administracion,'vacio'=>'No hay módulos disponibles con tus permisos actuales.'];
        if ($ctx==='global') $secciones[]=['titulo'=>'Menús','tarjetas'=>$documentos,'vacio'=>'No hay cartas PDF disponibles para tus negocios autorizados.'];
        return ['inicioContexto'=>$ctx,'seccionesInicio'=>$secciones];
    }
}
