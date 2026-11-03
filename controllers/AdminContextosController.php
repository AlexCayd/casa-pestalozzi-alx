<?php

namespace Controllers;

use Classes\Auth;
use MVC\Router;
use Services\Security\Contextos;

final class AdminContextosController
{
    public static function cambiar(Router $router): void
    {
        $contexto = $_GET['contexto'] ?? '';
        if (!is_string($contexto) || !in_array($contexto, Auth::contextos(), true)) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            $destinoContexto = Auth::destinoPorRol();
            require __DIR__ . '/../views/auth/contexto-denegado.php';
            return;
        }
        header('Location: ' . Contextos::inicio($contexto), true, 302);
    }

    public static function navegacion(string $contexto, array $restaurante): array
    {
        if (Auth::multinegocio()) {
            if ($contexto === 'restaurante') {
                $habilitados=\Services\Security\ContextoNegocio::modulosHabilitados($contexto);
                return ['inicio'=>['title'=>'Inicio','path'=>'/admin']] + array_filter($restaurante,static fn(string $clave): bool => in_array($clave,$habilitados,true),ARRAY_FILTER_USE_KEY);
            }
            $base='/admin/'.$contexto;
            $entradas=[];
            if (Auth::puede('inventario','ver',$contexto)) $entradas['inventario']=['title'=>'Almacenes','path'=>$base.'/almacenes'];
            if ($contexto!=='global' && Auth::puede('menu','ver',$contexto)) $entradas['menu']=['title'=>'Menú','path'=>$base.'/menu'];
            if (Auth::puede('productos','ver',$contexto)) $entradas['productos']=['title'=>'Artículos y preparaciones','path'=>$base.'/recetas/articulos'];
            if ($contexto==='global' && (Auth::puede('inventario','ver',$contexto)||Auth::puede('users','ver',$contexto)||Auth::puede('productos','ver',$contexto))) $entradas['menus']=['title'=>'Menús PDF','path'=>$base.'/menus'];
            if (Auth::puede('users','ver',$contexto)) $entradas['users']=['title'=>'Usuarios','path'=>$base.'/usuarios'];
            return $entradas;
        }
        if ($contexto === 'restaurante') {
            return ['inicio'=>['title'=>'Inicio','path'=>'/admin']] + $restaurante;
        }
        if ($contexto === 'global') {
            $entradas = ['inicio' => 'Negocios', 'catalogo' => 'Catálogo maestro', 'inventario' => 'Inventario consolidado', 'usuarios' => 'Usuarios', 'configuracion' => 'Configuración general'];
        } else {
            $entradas = ['inicio' => 'Inicio', 'pdv' => 'Punto de venta', 'menu' => 'Menú', 'productos' => 'Recetas', 'inventario' => 'Inventarios', 'transferencias' => 'Transferencias', 'area' => 'Producción'];
        }
        $modulos = [];
        foreach ($entradas as $clave => $titulo) {
            if ($contexto === 'panaderia' && in_array($clave, ['menu', 'productos', 'inventario'], true) && !Auth::puede($clave)) {
                continue;
            }
            $pendiente = $contexto === 'panaderia' && in_array($clave, ['pdv', 'transferencias', 'area'], true);
            $segmento = $clave === 'productos' ? 'recetas' : $clave;
            $modulos[$clave] = ['title' => $titulo, 'path' => '/admin/' . $contexto . ($clave === 'inicio' ? '' : '/' . $segmento), 'pendiente' => $pendiente];
        }
        return $modulos;
    }

    public static function pagina(Router $router): void
    {
        if (Auth::multinegocio()) {
            if(str_ends_with((string)($_SERVER['PATH_INFO']??''),'/recetas')){
                $ctx=\Services\Security\ContextoNegocio::slug();\Services\Security\ContextoNegocio::exigir('productos','ver',$ctx);
                header('Location: '.\Services\Inventory\Articulos::base($ctx),true,302);return;
            }
            self::inicioSeguro();
            return;
        }
        header('Cache-Control: private, no-store');
        $ruta = $_SERVER['PATH_INFO'] ?? '';
        $contexto = Contextos::deRuta($ruta);
        $seccion = basename($ruta);
        $clave = $seccion === $contexto ? 'inicio' : ($seccion === 'recetas' ? 'productos' : $seccion);
        $modulos = self::navegacion($contexto, []);
        $demo = $contexto === 'panaderia' && Contextos::demoHabilitada();
        $fixture = $demo ? require __DIR__ . '/../database/fixtures/panaderia.php' : null;
        $q = trim(is_string($_GET['q'] ?? '') ? ($_GET['q'] ?? '') : '');
        if ($fixture && $q !== '') {
            $coleccion = $clave === 'menu' ? 'productos' : ($clave === 'productos' ? 'recetas' : 'articulos');
            $fixture[$coleccion] = array_filter($fixture[$coleccion], static fn($fila) => mb_stripos($fila['nombre'], $q) !== false);
        }
        AdminController::render('contextos/index', [
            'activeModule' => $clave,
            'title' => $modulos[$clave]['title'] ?? 'Inicio',
            'contexto' => $contexto,
            'seccion' => $clave,
            'demo' => $demo,
            'fixture' => $fixture,
            'q' => $q,
            'styles' => ['/build/css/admin/' . ($clave === 'productos' ? 'recetas' : ($clave === 'menu' ? 'menu' : 'inventario')) . '.css'],
        ]);
    }

    public static function inicioSeguro(): void
    {
        header('Cache-Control: no-store, private');
        $ctx=\Services\Security\ContextoNegocio::slug();
        $perfil=\Services\Security\PermisosUsuario::cargar(\Services\Security\ContextoNegocio::actorId());
        $documentos=[];
        if ($ctx==='global') {
            try { $documentos=\Services\Menu\MenuPdf::biblioteca(); }
            catch (\Services\Inventory\InventarioError) { /* Sin módulos globales autorizados no hay biblioteca. */ }
        }
        $inicio=\Services\Security\InicioAdministrativo::preparar($perfil,$ctx,\Services\Security\ContextoNegocio::nivelAuth(),$documentos);
        AdminController::render('contextos/inicio', array_merge($inicio, [
            'nombreInicio'=>Contextos::NOMBRES[$ctx],
            'activeModule' => 'inicio',
            'title' => 'Inicio',
            'topbarSection' => 'Inicio',
        ]));
    }
}
