<?php

namespace Model;

use InvalidArgumentException;
use Services\ContactoService;
use Services\ReservacionConfig;

/**
 * Inscripción de una persona (o grupo) a una cata.
 *
 * `personas` es la unidad que descuenta cupo: una inscripción de cuatro ocupa
 * cuatro lugares, no uno.
 */
class CataInscripcion extends ActiveRecord
{
    protected static $tabla = 'cata_inscripciones';

    protected static $columnasDB = [
        'id', 'cata_id', 'nombre', 'contacto_tipo', 'contacto', 'personas',
        'nota', 'estado', 'created_at', 'updated_at'
    ];

    public const ESTADOS = ['pendiente', 'confirmada', 'cancelada', 'asistio', 'no_show'];

    /** Estados que siguen ocupando un lugar de la cata. */
    public const ESTADOS_QUE_OCUPAN = ['pendiente', 'confirmada', 'asistio'];

    public const MAX_PERSONAS = 10;

    public $id;
    public $cata_id;
    public $nombre;
    public $contacto_tipo = 'email';
    public $contacto;
    public $personas = 1;
    public $nota = null;
    public $estado = 'pendiente';
    public $created_at;
    public $updated_at;

    public function validar()
    {
        static::$alertas = [];

        if (!filter_var($this->cata_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
            static::setAlerta('error', 'La cata seleccionada no es válida');
        }

        $nombre = trim((string)$this->nombre);
        if ($nombre === '') {
            static::setAlerta('error', 'El nombre es obligatorio');
        } elseif (mb_strlen($nombre) > ReservacionConfig::NOMBRE_MAX_CARACTERES) {
            static::setAlerta(
                'error',
                'El nombre no puede pasar de ' . ReservacionConfig::NOMBRE_MAX_CARACTERES . ' caracteres'
            );
        }

        if (!in_array($this->contacto_tipo, ContactoService::TIPOS, true)) {
            static::setAlerta('error', 'Elige correo o teléfono como forma de contacto');
        } else {
            // Se valida con la misma regla que reservaciones para que un mismo
            // cliente sea comparable entre módulos.
            try {
                ContactoService::normalizar($this->contacto_tipo, (string)$this->contacto);
            } catch (InvalidArgumentException $e) {
                static::setAlerta('error', $e->getMessage());
            }
        }

        $personas = filter_var($this->personas, FILTER_VALIDATE_INT);
        if ($personas === false || $personas < 1) {
            static::setAlerta('error', 'Indica cuántas personas asistirán');
        } elseif ($personas > self::MAX_PERSONAS) {
            static::setAlerta(
                'error',
                'Para grupos de más de ' . self::MAX_PERSONAS . ' personas escríbenos directamente'
            );
        }

        if ($this->nota !== null && mb_strlen((string)$this->nota) > ReservacionConfig::NOTA_MAX_CARACTERES) {
            static::setAlerta(
                'error',
                'La nota no puede pasar de ' . ReservacionConfig::NOTA_MAX_CARACTERES . ' caracteres'
            );
        }

        if (!in_array($this->estado, self::ESTADOS, true)) {
            static::setAlerta('error', 'El estado de la inscripción no es válido');
        }

        return static::$alertas;
    }
}
