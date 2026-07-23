<?php

namespace Model;

class HorarioOperacion extends ActiveRecord
{
    protected static $tabla = 'horarios_operacion';
    protected static $columnasDB = [
        'id',
        'dia_semana',
        'abierto',
        'hora_apertura',
        'hora_cierre',
        'updated_by',
        'updated_at',
    ];

    public $id = null;
    public $dia_semana = 0;
    public $abierto = 1;
    public $hora_apertura = '08:00:00';
    public $hora_cierre = '22:00:00';
    public $updated_by = null;
    public $updated_at = null;

    public function __construct(array $args = [])
    {
        foreach ($args as $propiedad => $valor) {
            if (property_exists($this, $propiedad)) {
                $this->$propiedad = $valor;
            }
        }
    }

    public function validar(): array
    {
        static::$alertas = [];
        $dia = filter_var($this->dia_semana, FILTER_VALIDATE_INT);

        if ($dia === false || $dia < 0 || $dia > 6) {
            static::setAlerta('error', 'El día de la semana no es válido.');
        }

        if ((int) $this->abierto === 1) {
            if (!$this->hora_apertura) {
                static::setAlerta('error', 'La hora de apertura es obligatoria.');
            }
            if (!$this->hora_cierre) {
                static::setAlerta('error', 'La hora de cierre es obligatoria.');
            }
        }

        return static::$alertas;
    }

    public static function todosOrdenados(): array
    {
        return static::consultarSQL(
            'SELECT * FROM ' . static::$tabla . ' ORDER BY dia_semana ASC'
        );
    }

    public static function buscarPorDia(int $diaSemana): ?self
    {
        $stmt = self::getDB()->prepare(
            'SELECT * FROM ' . static::$tabla . ' WHERE dia_semana = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la consulta de horario.');
        }

        $stmt->bind_param('i', $diaSemana);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $fila = $resultado->fetch_assoc() ?: null;
        $stmt->close();

        return $fila ? static::crearObjeto($fila) : null;
    }

    /**
     * Inserta o actualiza por dia_semana; nunca usa el ID como identidad del día.
     */
    public function guardarPorDia(): bool
    {
        $stmt = self::getDB()->prepare(
            'INSERT INTO ' . static::$tabla . '
                (dia_semana, abierto, hora_apertura, hora_cierre, updated_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                abierto = VALUES(abierto),
                hora_apertura = VALUES(hora_apertura),
                hora_cierre = VALUES(hora_cierre),
                updated_by = VALUES(updated_by)'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el guardado del horario.');
        }

        $dia = (int) $this->dia_semana;
        $abierto = (int) $this->abierto;
        $horaApertura = $this->hora_apertura;
        $horaCierre = $this->hora_cierre;
        $usuarioId = $this->updated_by;
        $stmt->bind_param('iissi', $dia, $abierto, $horaApertura, $horaCierre, $usuarioId);
        $resultado = $stmt->execute();
        $stmt->close();

        return $resultado;
    }
}
