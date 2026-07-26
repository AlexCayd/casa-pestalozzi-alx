<?php
namespace Model;

class DiaReservacion extends ActiveRecord {
    protected static $tabla = 'dias_reservacion';
    protected static $columnasDB = ['id', 'dia_semana', 'nombre', 'hora_apertura', 'hora_cierre', 'activo'];

    public $id;
    public $dia_semana;
    public $nombre;
    public $hora_apertura;
    public $hora_cierre;
    public $activo = 1;

    public static function buscarPorDiaSemana(int $diaSemana): ?self
    {
        if ($diaSemana < 0 || $diaSemana > 6) {
            throw new \InvalidArgumentException('El día de la semana no es válido.');
        }

        $stmt = self::getDB()->prepare(
            'SELECT * FROM ' . static::$tabla . ' WHERE dia_semana = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la consulta del día de reservación.');
        }

        $stmt->bind_param('i', $diaSemana);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('No fue posible consultar el día de reservación.');
        }
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $fila ? static::crearObjeto($fila) : null;
    }

    /**
     * Repara la proyección legacy cuando falta una fila. Esta escritura ocurre
     * dentro de la transacción del horario operativo canónico.
     */
    public static function obtenerOCrear(int $diaSemana): self
    {
        $existente = self::buscarPorDiaSemana($diaSemana);
        if ($existente) {
            return $existente;
        }

        $nombres = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
        $nombre = $nombres[$diaSemana] ?? 'Día';
        $apertura = '08:00:00';
        $cierre = '22:00:00';
        $stmt = self::getDB()->prepare(
            'INSERT INTO dias_reservacion
                (dia_semana, nombre, hora_apertura, hora_cierre, activo)
             VALUES (?, ?, ?, ?, 0)'
        );
        if (!$stmt) {
            throw new \RuntimeException(self::getDB()->error);
        }
        $stmt->bind_param('isss', $diaSemana, $nombre, $apertura, $cierre);
        if (!$stmt->execute()) {
            throw new \RuntimeException($stmt->error);
        }
        $stmt->close();

        $creado = self::buscarPorDiaSemana($diaSemana);
        if (!$creado) {
            throw new \RuntimeException('No fue posible crear la proyección del día.');
        }
        return $creado;
    }

    /**
     * Actualiza la estructura compatible con reservaciones usando el ID real.
     * Al cerrar conserva las horas NOT NULL que ya estaban almacenadas.
     */
    public function actualizarDatosDeOperacion(
        bool $activo,
        ?string $horaApertura = null,
        ?string $horaCierre = null
    ): void {
        $id = (int) $this->id;
        if ($id < 1) {
            throw new \RuntimeException('El día de reservación no tiene un identificador válido.');
        }

        $estado = $activo ? 1 : 0;
        if ($activo) {
            if ($horaApertura === null || $horaCierre === null) {
                throw new \InvalidArgumentException('Un día abierto requiere horas de apertura y cierre.');
            }

            $stmt = self::getDB()->prepare(
                'UPDATE ' . static::$tabla . '
                 SET activo = ?, hora_apertura = ?, hora_cierre = ?
                 WHERE id = ? LIMIT 1'
            );
            if (!$stmt) {
                throw new \RuntimeException('No fue posible preparar la actualización del día de reservación.');
            }
            $stmt->bind_param('issi', $estado, $horaApertura, $horaCierre, $id);
        } else {
            $stmt = self::getDB()->prepare(
                'UPDATE ' . static::$tabla . ' SET activo = ? WHERE id = ? LIMIT 1'
            );
            if (!$stmt) {
                throw new \RuntimeException('No fue posible preparar el cierre del día de reservación.');
            }
            $stmt->bind_param('ii', $estado, $id);
        }

        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('No fue posible actualizar el día de reservación.');
        }
        $stmt->close();
    }
}
