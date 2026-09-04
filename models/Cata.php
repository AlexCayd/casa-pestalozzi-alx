<?php

namespace Model;

/**
 * Una cata dirigida: fecha, duración, precio y un interruptor de cupo.
 *
 * El modelo sólo valida y transporta. Lo que decide si una cata sale en la
 * landing —únicamente que no haya ocurrido todavía— vive en
 * Services\CataService, porque depende del reloj y no de la fila.
 *
 * Tuvo cupo numérico, estado de cinco valores y una tabla de inscripciones
 * colgando. Se retiraron con el formulario público: el lugar se aparta por
 * WhatsApp, y un contador que no ve esas conversaciones miente en cuanto
 * alguien llama. Lo que queda es un sí/no que se declara a mano.
 */
class Cata extends ActiveRecord
{
    protected static $tabla = 'catas';

    protected static $columnasDB = [
        'id', 'titulo', 'descripcion', 'fecha', 'hora', 'duracion_min',
        'precio', 'disponible', 'created_at', 'updated_at'
    ];

    public $id;
    public $titulo;
    public $descripcion = null;
    public $fecha;
    public $hora;
    public $duracion_min = 90;
    public $precio = 0;
    /* Nace CON cupo: una cata recién programada admite gente, y se cierra el día
       que se llena. Nacía apagada cuando el interruptor decidía además si se
       veía en la landing; ahora eso lo decide el reloj, así que arrancar en 0
       publicaría toda cata nueva como agotada. */
    public $disponible = 1;
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

        $duracion = filter_var($this->duracion_min, FILTER_VALIDATE_INT);
        if ($duracion === false || $duracion < 1) {
            static::setAlerta('error', 'La duración debe ser un número de minutos mayor que cero');
        }

        if (!is_numeric($this->precio) || (float)$this->precio < 0) {
            static::setAlerta('error', 'El precio no puede ser negativo');
        }

        return static::$alertas;
    }

    /** ¿Le quedan lugares? No tiene nada que ver con si se publica. */
    public function estaDisponible(): bool
    {
        return (int)$this->disponible === 1;
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
