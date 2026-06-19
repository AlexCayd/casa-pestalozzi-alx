<?php
namespace Model;

class Reservacion extends ActiveRecord {
    protected static $tabla = 'reservaciones';
    protected static $columnasDB = ['id', 'nombre', 'email', 'fecha', 'hora', 'comensales', 'nota', 'estado'];

    public $id;
    public $nombre;
    public $email;
    public $fecha;
    public $hora;
    public $comensales = 2;
    public $nota;
    public $estado = 'pendiente';
    // Asignación de mesas — no están en $columnasDB para no incluirlos en INSERTs
    public $mesa_id            = null;
    public $mesa_secundaria_id = null;

    public function validar() {
        static::$alertas = [];

        if (!$this->nombre) {
            static::setAlerta('error', 'El nombre es obligatorio');
        }
        if (!$this->email) {
            static::setAlerta('error', 'El correo es obligatorio');
        } elseif (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            static::setAlerta('error', 'El correo no tiene un formato válido');
        }
        if (!$this->fecha) {
            static::setAlerta('error', 'La fecha es obligatoria');
        }
        if (!$this->hora) {
            static::setAlerta('error', 'La hora es obligatoria');
        }
        if (!$this->comensales || $this->comensales < 1) {
            static::setAlerta('error', 'El número de comensales es obligatorio');
        }

        return static::$alertas;
    }
}
