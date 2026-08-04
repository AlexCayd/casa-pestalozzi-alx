<?php
namespace Model;

class Ticket extends ActiveRecord {

    protected static $tabla = 'tickets';

    // hora_apertura usa DEFAULT CURRENT_TIMESTAMP — no incluir para que el DB lo maneje.
    // reservacion_id y mesero_id son nullable FKs.
    protected static $columnasDB = ['id', 'comensales', 'estado'];

    public $id;
    public $nombre             = null;
    public $comensales         = 1;
    public $hora_apertura      = null;
    public $closed_at          = null;
    public $hora_cierre        = null;
    public $estado             = 'abierto';
    public $reservacion_id     = null;
    public $mesero_id          = null;
    public $mesa_ids           = '';

    // Campos extra de JOINs (no en $columnasDB): crearObjeto() sólo asigna
    // columnas cuya propiedad existe, así que los alias de las consultas de
    // impresión (comanda/cuenta) deben declararse aquí — igual que TicketItem.
    public $cliente;      // t.nombre AS cliente
    public $mesa;         // m.nombre AS mesa
    public $mesa_numero;  // m.numero AS mesa_numero
    public $mesa_nombre;  // m.nombre AS mesa_nombre
    public $mesero;       // u.nombre AS mesero

    /** Ticket mas reciente ligado a una reservacion, abierto o cerrado. */
    public static function buscarPorReservacion(int $reservacionId): ?array
    {
        if ($reservacionId < 1) {
            return null;
        }
        $resultado = self::getDB()->query(
            "SELECT t.id, t.nombre, t.comensales, t.hora_apertura, t.closed_at,
                    t.hora_cierre, t.estado, t.reservacion_id
             FROM tickets t
             WHERE t.reservacion_id = {$reservacionId}
             ORDER BY t.id DESC
             LIMIT 1"
        );
        if ($resultado === false) {
            throw new \RuntimeException(self::getDB()->error);
        }
        $fila = $resultado->fetch_assoc() ?: null;
        $resultado->free();
        if (!$fila) {
            return null;
        }
        $fila['id'] = (int)$fila['id'];
        $fila['reservacion_id'] = (int)$fila['reservacion_id'];
        $fila['mesa_ids'] = TicketMesa::idsPorTicket((int)$fila['id']);
        return $fila;
    }
}
