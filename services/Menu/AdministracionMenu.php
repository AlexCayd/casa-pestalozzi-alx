<?php
declare(strict_types=1);
namespace Services\Menu;

use Classes\Auth;
use Model\ActiveRecord;
use Model\CategoriasMenu;
use Services\Security\ContextoNegocio;

/** Configuración y frontera HTTP de la única superficie administrativa de menú. */
final class AdministracionMenu
{
    public static function base(): string
    {
        return match (ContextoNegocio::slug()) {
            'panaderia' => '/admin/panaderia/menu',
            default => '/admin/menu',
        };
    }

    public static function token(): string
    {
        Auth::start();
        $ctx = ContextoNegocio::slug();
        return $_SESSION['_menu_csrf'][$ctx] ??= bin2hex(random_bytes(32));
    }

    public static function autorizar(): void
    {
        $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        ContextoNegocio::exigir('menu', $post ? 'editar' : 'ver');
        if ($post && (!is_string($_POST['menu_csrf'] ?? null) || !hash_equals(self::token(), $_POST['menu_csrf']))) {
            http_response_code(419);
            exit('La sesión del formulario venció. Recarga la página.');
        }
        if ($post) {
            foreach (['nombre','descripcion','precio','categoria_id','area_id','orden','activo','id','producto_id','carta','quitar_imagen','imagen_pendiente','revision','huella','clave','accion','rendimiento','unidad','tipo','receta_version_id','tipo_venta'] as $campo) {
                if (isset($_POST[$campo]) && !is_scalar($_POST[$campo])) {
                    http_response_code(422);
                    exit('El formulario contiene un dato con formato inválido.');
                }
            }
        }
    }

    public static function vista(): array
    {
        $ctx = ContextoNegocio::slug();
        $cartas = CategoriasMenu::CARTAS;
        if ($ctx === 'panaderia') {
            $s = ActiveRecord::getDB()->prepare('SELECT slug,nombre FROM cartas WHERE unidad_id=? AND activo=1 ORDER BY orden,slug');
            $s->execute([ContextoNegocio::vendedora()]);
            $cartas = [];
            foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $c) $cartas[$c['slug']] = ['titulo'=>$c['nombre'],'corto'=>$c['nombre'],'ayuda'=>'Carta propia de Panadería; PDF administrativo disponible.'];
        }
        return [
            'menuBase' => self::base(), 'menuContexto' => $ctx,
            'menuCsrf' => self::token(), 'menuCartas' => $cartas,
            'menuPuedeEditar' => Auth::puede('menu', 'editar', $ctx),
            'menuRecetas' => Auth::puede('productos', 'ver', $ctx),
            'menuVariantes' => in_array($ctx,['restaurante','panaderia'],true),
            'menuSubtitulo' => $ctx === 'panaderia'
                ? 'Productos, precios, presentaciones y elaboraciones de Panadería. PDF propio disponible; el punto de venta y la producción física permanecen pendientes.'
                : 'Los productos de la carta, el PDF y el punto de venta se administran aquí. Configura su receta y sus presentaciones desde la ficha del producto.',
        ];
    }
}
