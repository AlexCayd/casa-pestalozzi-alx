<?php

namespace Model;

/** Configuración persistida de comunicaciones de reservaciones (id = 1). */
final class ConfiguracionReservaciones extends ActiveRecord
{
    private const ID_UNICO = 1;
    protected static $tabla = 'configuracion_reservaciones';
    protected static $columnasDB = [
        'id',
        'recordatorio_dia_anterior_activo',
        'hora_recordatorio',
        'updated_by',
        'updated_at',
    ];

    public $id = self::ID_UNICO;
    public $recordatorio_dia_anterior_activo = 0;
    public $hora_recordatorio = '18:00:00';
    public $updated_by = null;
    public $updated_at = null;

    public function __construct(array $args = [])
    {
        foreach ($args as $propiedad => $valor) {
            if (property_exists($this, $propiedad)) {
                $this->$propiedad = $valor;
            }
        }
        $this->id = self::ID_UNICO;
        $this->recordatorio_dia_anterior_activo = (int)(bool)$this->recordatorio_dia_anterior_activo;
    }

    public static function obtener(): ?self
    {
        $stmt = self::getDB()->prepare('SELECT * FROM ' . static::$tabla . ' WHERE id = ? LIMIT 1');
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la configuración de reservaciones.');
        }
        $id = self::ID_UNICO;
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('No fue posible consultar la configuración de reservaciones.');
        }
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $fila ? static::crearObjeto($fila) : null;
    }

    public static function obtenerOCrear(): self
    {
        $stmt = self::getDB()->prepare(
            'INSERT INTO ' . static::$tabla . ' (id) VALUES (?)
             ON DUPLICATE KEY UPDATE id = VALUES(id)'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la configuración inicial de reservaciones.');
        }
        $id = self::ID_UNICO;
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('No fue posible inicializar la configuración de reservaciones.');
        }
        $stmt->close();
        $configuracion = static::obtener();
        if (!$configuracion) {
            throw new \RuntimeException('No fue posible recuperar la configuración de reservaciones.');
        }
        return $configuracion;
    }

    public function guardarConfiguracion(): bool
    {
        $stmt = self::getDB()->prepare(
            'INSERT INTO ' . static::$tabla .
            ' (id, recordatorio_dia_anterior_activo, hora_recordatorio, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               recordatorio_dia_anterior_activo = VALUES(recordatorio_dia_anterior_activo),
               hora_recordatorio = VALUES(hora_recordatorio),
               updated_by = VALUES(updated_by),
               updated_at = CURRENT_TIMESTAMP'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el guardado de reservaciones.');
        }
        $id = self::ID_UNICO;
        $activo = (int)(bool)$this->recordatorio_dia_anterior_activo;
        $hora = (string)$this->hora_recordatorio;
        $updatedBy = $this->updated_by !== null ? (int)$this->updated_by : null;
        $stmt->bind_param('iisi', $id, $activo, $hora, $updatedBy);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function validar(): array
    {
        static::$alertas = [];
        if (!in_array((int)$this->recordatorio_dia_anterior_activo, [0, 1], true)) {
            static::setAlerta('error', 'El estado del recordatorio no es válido.');
        }
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', (string)$this->hora_recordatorio)) {
            static::setAlerta('error', 'La hora del recordatorio debe usar el formato HH:MM.');
        }
        return static::$alertas;
    }

    public function valoresFormulario(): array
    {
        return [
            'recordatorio_dia_anterior_activo' => (int)$this->recordatorio_dia_anterior_activo === 1,
            'hora_recordatorio' => substr((string)$this->hora_recordatorio, 0, 5),
            'updated_at' => (string)($this->updated_at ?? ''),
        ];
    }
}
