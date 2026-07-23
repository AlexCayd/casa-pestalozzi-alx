<?php
namespace Model;

class HorarioReservacion extends ActiveRecord {
    protected static $tabla = 'horarios_reservacion';
    protected static $columnasDB = ['id', 'dia_id', 'hora'];

    public $id;
    public $dia_id;
    public $hora;

    public static function buscarPorDiaId(int $diaId): array
    {
        if ($diaId < 1) {
            throw new \InvalidArgumentException('El identificador del día no es válido.');
        }

        $stmt = self::getDB()->prepare(
            'SELECT id, dia_id, hora
             FROM ' . static::$tabla . '
             WHERE dia_id = ?
             ORDER BY hora ASC'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la consulta de horarios reservables.');
        }

        $stmt->bind_param('i', $diaId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('No fue posible consultar los horarios reservables.');
        }

        $resultado = $stmt->get_result();
        $horarios = [];
        while ($fila = $resultado->fetch_assoc()) {
            $horarios[] = static::crearObjeto($fila);
        }
        $stmt->close();

        return $horarios;
    }

    public static function eliminarPorDiaId(int $diaId): void
    {
        if ($diaId < 1) {
            throw new \InvalidArgumentException('El identificador del día no es válido.');
        }

        $stmt = self::getDB()->prepare(
            'DELETE FROM ' . static::$tabla . ' WHERE dia_id = ?'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la eliminación de horarios reservables.');
        }
        $stmt->bind_param('i', $diaId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('No fue posible eliminar los horarios reservables anteriores.');
        }
        $stmt->close();
    }

    public static function insertarIntervalos(int $diaId, array $intervalos): void
    {
        if ($diaId < 1) {
            throw new \InvalidArgumentException('El identificador del día no es válido.');
        }
        if ($intervalos === []) {
            return;
        }

        $stmt = self::getDB()->prepare(
            'INSERT INTO ' . static::$tabla . ' (dia_id, hora) VALUES (?, ?)'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la inserción de horarios reservables.');
        }

        $hora = '';
        $stmt->bind_param('is', $diaId, $hora);
        foreach ($intervalos as $intervalo) {
            $hora = (string) $intervalo;
            if (!$stmt->execute()) {
                $stmt->close();
                throw new \RuntimeException('No fue posible insertar uno de los horarios reservables.');
            }
        }
        $stmt->close();
    }
}
