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
