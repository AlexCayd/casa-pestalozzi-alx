<?php
declare(strict_types=1);

// Prueba de presentación aislada: los permisos llegan del borde de autenticación,
// sin conectar a MySQL ni sustituir decisiones de autorización productivas.
namespace Classes {
    final class Auth {
        public static bool $edita = false;
        public static function puede(string $modulo, string $nivel = 'ver', ?string $ctx = null): bool {
            return $modulo === 'inventario' && ($nivel === 'ver' || self::$edita);
        }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') exit(1);
    $root = dirname(__DIR__, 2);
    require $root . '/vendor/autoload.php';
    $count = 0;
    $assert = static function (bool $ok, string $message) use (&$count): void {
        if (!$ok) throw new \RuntimeException($message);
        $count++;
    };
    $render = static function (bool $edita, string $pendiente = '') use ($root): string {
        \Classes\Auth::$edita = $edita;
        $nuevo = true;
        $ing = (object)['id'=>1,'nombre'=>'Harina <especial>','unidad'=>'g','activo'=>1,
            'stock'=>'100.000','stock_minimo'=>'10.000','costo'=>'0.1000'];
        $ingredientes = $ingredientesBajos = $ingredientesSinProveedor = [$ing];
        $ingredientesActivos = $totalIngredientes = 1;
        $existenciasPorArticulo = [1=>[
            ['almacen_id'=>1,'nombre'=>'Cocina <principal>','saldo'=>'0.000','minimo'=>'10.000',
                'activo'=>true,'asociacion_activa'=>true,'alerta'=>true],
            ['almacen_id'=>2,'nombre'=>'Reserva','saldo'=>'100.000','minimo'=>'0.000',
                'activo'=>true,'asociacion_activa'=>true,'alerta'=>false],
        ]];
        $almacenesLocales = $existenciasPorArticulo[1];
        $consumo = ['valor'=>5,'movimientos'=>1];
        ob_start();
        include $root . '/views/admin/inventario/index.php';
        return (string)ob_get_clean();
    };
    $reader = $render(false);
    $assert(!preg_match('/<form\b[^>]*method="POST"/i',$reader), 'Solo consulta no ofrece escrituras de inventario.');
    $assert(!str_contains($reader,'href="/admin/inventario/create"') && !str_contains($reader,'data-row-href='), 'Solo consulta no enlaza formularios de edición.');
    $assert(!str_contains($reader,'href="/admin/recetas"'), 'No se ofrece Recetas sin su permiso.');
    $assert(str_contains($reader,'al menos un almacén está por debajo de su mínimo'), 'El exceso de otro almacén no oculta una alerta física.');
    $assert(str_contains($reader,'Harina &lt;especial&gt;'), 'El nombre del artículo se escapa.');
    $assert(str_contains($reader,'al costo del movimiento') && !str_contains($reader,'ajustes · a costo actual'), 'El costo histórico se describe correctamente.');
    $pending = $render(true,'stock');
    $assert(str_contains($pending,'Cocina &lt;principal&gt;'), 'El pendiente identifica el almacén deficitario y escapa su nombre.');
    $assert(str_contains($pending,'faltan 10 g') && !str_contains($pending,'Justo en el mínimo'), 'El faltante usa el saldo físico, no el agregado.');
    $assert(str_contains($pending,'action="/admin/inventario/entrada"') && str_contains($pending,'name="almacen_id" required'), 'Editar permite recibir con almacén explícito.');
    $assert(str_contains($render(true),'action="/admin/inventario/ajustar"'), 'Editar conserva el ajuste rápido.');
    echo "Inventario 3D: {$count} comprobaciones de presentación OK\n";
}
