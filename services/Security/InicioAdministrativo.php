<?php
declare(strict_types=1);
namespace Services\Security;

/** Proyecta accesos reales desde el perfil vigente; no concede permisos. */
final class InicioAdministrativo
{
    /**
     * Grupos del inicio, en el orden en que se leen. Sólo ordenan la pantalla:
     * el primer grupo con módulos va en teselas grandes y el resto compactas.
     * No concede ni oculta nada; el acceso lo decide el perfil, como siempre.
     */
    public const GRUPOS = [
        'operacion' => 'Operación',
        'carta' => 'Carta y almacén',
        'clientes' => 'Clientes',
        'numeros' => 'Números',
        'sistema' => 'Equipo y sistema',
    ];

    /** Módulo → grupo del inicio (clave del catálogo de permisos). */
    public const GRUPO_MODULO = [
        'pdv' => 'operacion', 'area' => 'operacion', 'reservations' => 'operacion', 'tickets' => 'operacion',
        'menu' => 'carta', 'productos' => 'carta', 'inventario' => 'carta', 'transferencias' => 'carta',
        'catas' => 'clientes', 'feedback' => 'clientes',
        'finanzas' => 'numeros', 'analytics' => 'numeros', 'ia' => 'numeros',
        'users' => 'sistema', 'configuration' => 'sistema', 'printers' => 'sistema',
    ];

    public static function preparar(array $perfil, string $ctx, string $nivel, array $documentos = []): array
    {
        $puede = static fn(string $slug, string $mod): bool => $nivel==='contrasena' && PermisosUsuario::decidir($perfil, $slug, $mod, 'ver', $nivel)['ok'];
        if ($ctx==='restaurante') {
            return self::restaurante(static fn(string $mod):bool => $puede($ctx,$mod)
                && ($mod!=='users' || in_array('usuarios.gestionar',$perfil['ambitos'][$ctx]['facultades']??[],true)));
        }
        $administracion = [];
        $base = $ctx === 'restaurante' ? '/admin' : '/admin/'.$ctx;
        $defs = [
            'ia'=>['Asistente IA',$ctx==='global'?'Pregunta por los almacenes de la organización y las transferencias.':'Pregunta por las ventas en caja, los almacenes y el menú.','ia',$base.'/ia'],
            'analytics'=>['Análisis de datos',$ctx==='global'?'Compara ventas, tickets e indicadores por negocio.':'Ventas en caja, lo más vendido y las horas de más venta.','analytics',$base.'/analytics'],
            'finanzas'=>['Finanzas',$ctx==='global'?'Cómo van los dos negocios hoy y en el periodo, y el total de la organización.':'Ventas en caja, costos, merma y gastos fijos de Panadería.','finanzas',$base.'/finanzas'],
            'inventario'=>['Almacenes','Consulta existencias y registra operaciones físicas.','inventario',$base.'/almacenes'],
            'productos'=>['Artículos y preparaciones','Configura artículos, capacidades y recetas de preparación.','products',$base.'/recetas/articulos'],
            // La caja es piso: se abre desde aquí como Punto de venta en Restaurante.
            'pdv'=>['Caja','Vende en mostrador, cobra y registra horneadas.','pdv',Contextos::CAJA_PANADERIA],
            'vitrina'=>['Vitrina de la caja','Opciones de cada pan y la pieza que descuenta al venderse.','variantes',$base.'/menu/vitrina'],
            'transferencias'=>['Transferencias','Consulta envíos, recepciones e incidencias.','transferencias',$base.'/almacenes?vista=transferencias'],
            'users'=>['Usuarios','Consulta el equipo y sus accesos autorizados.','users',$base.'/usuarios'],
        ];
        if ($ctx !== 'global') $defs['menu']=['Menú','Organiza productos, precios y disponibilidad propios.','menu',$base.'/menu'];
        foreach ($defs as $mod=>$d) {
            if (in_array($mod,['pdv','vitrina'],true) && $ctx!=='panaderia') continue;
            if (!$puede($ctx,match($mod){'transferencias'=>'inventario','vitrina'=>'menu',default=>$mod})) continue;
            if ($mod==='users' && !in_array('usuarios.gestionar',$perfil['ambitos'][$ctx]['facultades']??[],true)) continue;
            // Global no se agrupa: su administración es un solo bloque.
            $administracion[]=['titulo'=>$d[0],'descripcion'=>$d[1],'icono'=>$d[2],'ruta'=>$d[3],'accion'=>'Abrir']
                +($ctx==='global'?[]:['grupo'=>self::GRUPO_MODULO[$mod]??'sistema']);
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

    /** El mismo componente de tarjetas; las rutas proceden del catálogo autorizado. */
    public static function restaurante(callable $puede): array
    {
        $tarjetas=[];
        foreach (PermisosUsuario::catalogo('restaurante') as $mod=>$def) {
            if ($mod==='reservations_map' || !$puede($mod)) continue;
            $tarjetas[]=['titulo'=>$mod==='inventario'?'Almacenes':$def['titulo'],
                'descripcion'=>$mod==='inventario'?'Consulta existencias y registra operaciones físicas.':$def['detalle'],
                'icono'=>$mod==='productos'?'products':$mod,'ruta'=>$def['ruta'],'accion'=>'Abrir',
                'grupo'=>self::GRUPO_MODULO[$mod]??'sistema'];
        }
        return ['inicioContexto'=>'restaurante','seccionesInicio'=>[['titulo'=>'Tus módulos','tarjetas'=>$tarjetas,'vacio'=>'No hay módulos disponibles con tus permisos actuales.']]];
    }
}
