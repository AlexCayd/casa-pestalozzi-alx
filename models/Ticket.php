<?php
namespace Model;

class Ticket extends ActiveRecord {

    protected static $tabla = 'tickets';

    // hora_apertura usa DEFAULT CURRENT_TIMESTAMP — no incluir para que el DB lo maneje.
    // mesa_secundaria_id y reservacion_id son nullable FKs — se asignan vía UPDATE post-INSERT.
    protected static $columnasDB = ['id', 'mesa_id', 'comensales', 'estado'];

    public $id;
    public $mesa_id;
    public $mesa_secundaria_id = null;
    public $nombre             = null;
    public $comensales         = 1;
    public $hora_apertura      = null;
    public $estado             = 'abierto';
    public $reservacion_id     = null;
}
