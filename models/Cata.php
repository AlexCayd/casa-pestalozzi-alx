<?php

namespace Model;

/**
 * Una cata dirigida: sesión con fecha, cupo y precio.
 *
 * El modelo sólo valida y transporta. El cupo disponible, la publicación y el
 * paso a 'agotada' viven en Services\CataService, porque dependen de las
 * inscripciones y no de la fila.
 */
class Cata extends ActiveRecord
{
    protected static $tabla = 'catas';

    protected static $columnasDB = [
        'id', 'titulo', 'descripcion', 'fecha', 'hora', 'duracion_min',
        'cupo', 'precio', 'imagen', 'estado', 'created_at', 'updated_at'
    ];

    public const ESTADOS = ['borrador', 'publicada', 'agotada', 'realizada', 'cancelada'];

    /** Estados en los que la cata sigue aceptando inscripciones. */
    public const ESTADOS_ABIERTOS = ['publicada'];

    public $id;
    public $titulo;
    public $descripcion = null;
    public $fecha;
    public $hora;
    public $duracion_min = 90;
    public $cupo = 12;
    public $precio = 0;
    public $imagen = null;
    public $estado = 'borrador';
    public $created_at;
    public $updated_at;

    public function validar()
    {
        static::$alertas = [];

        $titulo = trim((string)$this->titulo);
        if ($titulo === '') {
            static::setAlerta('error', 'El título de la cata es obligatorio');
        } elseif (mb_strlen($titulo) > 120) {
            static::setAlerta('error', 'El título no puede pasar de 120 caracteres');
        }

        if (!self::esFecha((string)$this->fecha)) {
            static::setAlerta('error', 'La fecha no es válida');
        }

        if (!self::esHora((string)$this->hora)) {
            static::setAlerta('error', 'La hora no es válida');
        }

        $cupo = filter_var($this->cupo, FILTER_VALIDATE_INT);
        if ($cupo === false || $cupo < 1) {
            static::setAlerta('error', 'El cupo debe ser un número mayor que cero');
        }

        $duracion = filter_var($this->duracion_min, FILTER_VALIDATE_INT);
        if ($duracion === false || $duracion < 1) {
            static::setAlerta('error', 'La duración debe ser un número de minutos mayor que cero');
        }

        if (!is_numeric($this->precio) || (float)$this->precio < 0) {
            static::setAlerta('error', 'El precio no puede ser negativo');
        }

        if (!in_array($this->estado, self::ESTADOS, true)) {
            static::setAlerta('error', 'El estado de la cata no es válido');
        }

        return static::$alertas;
    }

    /** Momento de inicio en la zona del restaurante, para ordenar y comparar. */
    public function inicio(): ?\DateTimeImmutable
    {
        $inicio = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            trim((string)$this->fecha) . ' ' . self::horaCompleta((string)$this->hora),
            new \DateTimeZone(\Services\ReservacionConfig::TIMEZONE)
        );

        return $inicio ?: null;
    }

    /**
     * Normaliza 'HH:MM' y 'HH:MM:SS' a la forma con segundos: el <input
     * type="time"> manda la primera y la BD devuelve la segunda.
     */
    public static function horaCompleta(string $hora): string
    {
        $hora = trim($hora);
        return strlen($hora) === 5 ? $hora . ':00' : $hora;
    }

    private static function esFecha(string $valor): bool
    {
        $fecha = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($valor));
        return $fecha !== false && $fecha->format('Y-m-d') === trim($valor);
    }

    private static function esHora(string $valor): bool
    {
        return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', trim($valor));
    }
}
