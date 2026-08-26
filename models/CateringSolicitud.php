<?php

namespace Model;

use InvalidArgumentException;
use Services\ContactoService;
use Services\ReservacionConfig;

/**
 * Solicitud de cotización de catering nacida en la landing.
 *
 * Es una bandeja de seguimiento comercial: entra como 'nueva' y el panel la va
 * moviendo a mano hasta 'ganada' o 'perdida'.
 */
class CateringSolicitud extends ActiveRecord
{
    protected static $tabla = 'catering_solicitudes';

    protected static $columnasDB = [
        'id', 'nombre', 'contacto_tipo', 'contacto', 'tipo_evento', 'fecha_evento',
        'invitados', 'presupuesto', 'mensaje', 'comentario_admin', 'estado',
        'created_at', 'updated_at'
    ];

    public const ESTADOS = ['nueva', 'contactada', 'cotizada', 'ganada', 'perdida'];

    /** Estados en los que la solicitud sigue viva en la bandeja. */
    public const ESTADOS_ABIERTOS = ['nueva', 'contactada', 'cotizada'];

    /**
     * Tipos sugeridos en el formulario público. Se acepta texto libre además:
     * la validación sólo comprueba que no venga vacío y quepa en 80 caracteres.
     *
     * El valor es la frase con la que la rejilla de catering abre WhatsApp. No
     * se arma concatenando "Quiero cotizar un " + el tipo porque el artículo
     * cambia con cada nombre —"un coffee break", "una boda", "unos XV años"— y
     * en español no hay forma de deducirlo del texto. Van juntos en la misma
     * constante para que dar de alta un tipo obligue a escribir su frase.
     */
    public const TIPOS_EVENTO = [
        // Corporativo
        'Coffee break'              => 'Hola! Quiero cotizar un coffee break',
        'Desayuno de trabajo'       => 'Hola! Quiero cotizar un desayuno de trabajo',
        'Junta ejecutiva'           => 'Hola! Quiero cotizar una junta ejecutiva',
        'Evento empresarial'        => 'Hola! Quiero cotizar un evento empresarial',
        'Posada de oficina'         => 'Hola! Quiero cotizar una posada de oficina',
        'Comida de fin de año'      => 'Hola! Quiero cotizar una comida de fin de año',
        // Celebraciones sociales
        'XV años'                   => 'Hola! Quiero cotizar unos XV años',
        'Graduación'                => 'Hola! Quiero cotizar una graduación',
        'Cumpleaños'                => 'Hola! Quiero cotizar un cumpleaños',
        'Bautizo o primera comunión' => 'Hola! Quiero cotizar un bautizo o primera comunión',
        'Baby shower'               => 'Hola! Quiero cotizar un baby shower',
        // Bodas y ceremonias
        'Boda'                      => 'Hola! Quiero cotizar una boda',
        'Aniversario'               => 'Hola! Quiero cotizar un aniversario',
        'Pedida de mano'            => 'Hola! Quiero cotizar una pedida de mano',
        'Despedida de soltera'      => 'Hola! Quiero cotizar una despedida de soltera',
        // Institucional y cultural
        'Inauguración'              => 'Hola! Quiero cotizar una inauguración',
        'Presentación de producto'  => 'Hola! Quiero cotizar una presentación de producto',
        'Cena privada'              => 'Hola! Quiero cotizar una cena privada',
        'Otro'                      => 'Hola! Quiero cotizar un evento en Casa Pestalozzi',
    ];

    /** Sólo los nombres, para poblar el <select> del formulario. */
    public static function nombresEvento(): array
    {
        return array_keys(self::TIPOS_EVENTO);
    }

    public const MAX_INVITADOS = 2000;
    public const MENSAJE_MAX_CARACTERES = 1500;

    public $id;
    public $nombre;
    public $contacto_tipo = 'email';
    public $contacto;
    public $tipo_evento;
    public $fecha_evento = null;
    public $invitados = null;
    public $presupuesto = null;
    public $mensaje = null;
    public $comentario_admin = null;
    public $estado = 'nueva';
    public $created_at;
    public $updated_at;

    public function validar()
    {
        static::$alertas = [];

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
            try {
                ContactoService::normalizar($this->contacto_tipo, (string)$this->contacto);
            } catch (InvalidArgumentException $e) {
                static::setAlerta('error', $e->getMessage());
            }
        }

        $tipoEvento = trim((string)$this->tipo_evento);
        if ($tipoEvento === '') {
            static::setAlerta('error', 'Indica qué tipo de evento quieres celebrar');
        } elseif (mb_strlen($tipoEvento) > 80) {
            static::setAlerta('error', 'El tipo de evento no puede pasar de 80 caracteres');
        }

        // La fecha es opcional: mucha gente cotiza antes de tenerla cerrada.
        if ($this->fecha_evento !== null && trim((string)$this->fecha_evento) !== '') {
            $fecha = \DateTimeImmutable::createFromFormat('!Y-m-d', trim((string)$this->fecha_evento));
            if ($fecha === false || $fecha->format('Y-m-d') !== trim((string)$this->fecha_evento)) {
                static::setAlerta('error', 'La fecha del evento no es válida');
            }
        }

        if ($this->invitados !== null && trim((string)$this->invitados) !== '') {
            $invitados = filter_var($this->invitados, FILTER_VALIDATE_INT);
            if ($invitados === false || $invitados < 1) {
                static::setAlerta('error', 'El número de invitados debe ser mayor que cero');
            } elseif ($invitados > self::MAX_INVITADOS) {
                static::setAlerta('error', 'Para más de ' . self::MAX_INVITADOS . ' invitados llámanos, por favor');
            }
        }

        if ($this->presupuesto !== null && mb_strlen((string)$this->presupuesto) > 60) {
            static::setAlerta('error', 'El presupuesto no puede pasar de 60 caracteres');
        }

        if ($this->mensaje !== null && mb_strlen((string)$this->mensaje) > self::MENSAJE_MAX_CARACTERES) {
            static::setAlerta(
                'error',
                'El mensaje no puede pasar de ' . self::MENSAJE_MAX_CARACTERES . ' caracteres'
            );
        }

        if (
            $this->comentario_admin !== null
            && mb_strlen((string)$this->comentario_admin) > ReservacionConfig::COMENTARIO_ADMIN_MAX_CARACTERES
        ) {
            static::setAlerta('error', 'El comentario interno es demasiado largo');
        }

        if (!in_array($this->estado, self::ESTADOS, true)) {
            static::setAlerta('error', 'El estado de la solicitud no es válido');
        }

        return static::$alertas;
    }
}
