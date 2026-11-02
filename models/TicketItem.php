<?php
namespace Model;

class TicketItem extends RegistroRestaurante {

    protected static $tabla = 'ticket_items';

    protected static $columnasDB = [
        'id', 'ticket_id', 'nombre', 'precio', 'categoria',
        'area_id', 'comensal', 'cantidad', 'nota', 'variante_ids', 'variante', 'estado'
    ];

    public $id;
    public $ticket_id;
    public $oferta_id;
    public $producto_id;
    public $receta_id;
    public $categoria_id;
    public $costo_unitario;
    public $nombre;
    public $precio;
    public $categoria;
    public $area_id;
    public $comensal   = null;
    public $cantidad   = 1;
    public $nota       = null;
    // Opciones elegidas en el POS (ver producto_variantes): ids y rótulo congelado.
    public $variante_ids = null;
    public $variante     = null;
    public $estado     = 'enviado';
    public $created_at = null;

    // Campos extra del JOIN con areas_produccion (no en $columnasDB)
    public $area_nombre;
    public $area_slug;
    public $area_color;

    // Campos extra del JOIN con tickets + mesas (KDS queries)
    public $ticket_nombre;
    public $mesa_nombre;
    public $mesa_numero;
}
