<?php

namespace Model;

/**
 * Configuración persistida de reservaciones (id = 1): las comunicaciones y los
 * límites de la reserva en línea. Son dos formularios distintos sobre la misma
 * fila, así que cada guardado nombra sólo sus columnas (ver guardarLimites()).
 */
final class ConfiguracionReservaciones extends ActiveRecord
{
    private const ID_UNICO = 1;
    protected static $tabla = 'configuracion_reservaciones';
    protected static $columnasDB = [
        'id',
        'recordatorio_dia_anterior_activo',
        'hora_recordatorio',
        'max_comensales_publico',
        'whatsapp_reservas',
        'updated_by',
        'updated_at',
    ];

    public $id = self::ID_UNICO;
    public $recordatorio_dia_anterior_activo = 0;
    public $hora_recordatorio = '18:00:00';
    public $max_comensales_publico = 12;
    public $whatsapp_reservas = null;
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
        $this->max_comensales_publico = (int)$this->max_comensales_publico;
        $whatsapp = trim((string)($this->whatsapp_reservas ?? ''));
        $this->whatsapp_reservas = $whatsapp === '' ? null : $whatsapp;
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

    /**
     * Guarda SÓLO los límites de la reserva en línea. Va aparte de
     * guardarConfiguracion() por la razón de ConfiguracionPos: un UPSERT que
     * nombrara también el recordatorio pisaría el formulario ajeno con los
     * valores por omisión del objeto en memoria.
     */
    public function guardarLimites(): bool
    {
        $stmt = self::getDB()->prepare(
            'INSERT INTO ' . static::$tabla .
            ' (id, max_comensales_publico, whatsapp_reservas, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               max_comensales_publico = VALUES(max_comensales_publico),
               whatsapp_reservas = VALUES(whatsapp_reservas),
               updated_by = VALUES(updated_by),
               updated_at = CURRENT_TIMESTAMP'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el guardado de los límites de reservación.');
        }
        $id = self::ID_UNICO;
        $maximo = (int)$this->max_comensales_publico;
        $whatsapp = $this->whatsapp_reservas !== null ? (string)$this->whatsapp_reservas : null;
        $updatedBy = $this->updated_by !== null ? (int)$this->updated_by : null;
        $stmt->bind_param('iisi', $id, $maximo, $whatsapp, $updatedBy);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * Valida los límites con los mismos rangos que hace cumplir el CHECK de la
     * tabla, para que el admin reciba un mensaje y no un error de base.
     */
    public function validarLimites(int $minimo, int $tope): array
    {
        static::$alertas = [];
        $maximo = (int)$this->max_comensales_publico;
        if ($maximo < $minimo || $maximo > $tope) {
            static::setAlerta('error', "El máximo de comensales debe estar entre {$minimo} y {$tope}.");
        }
        if ($this->whatsapp_reservas !== null && !preg_match('/^\d{10,15}$/', (string)$this->whatsapp_reservas)) {
            static::setAlerta('error', 'El WhatsApp debe tener entre 10 y 15 dígitos, con la lada del país.');
        }
        return static::$alertas;
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
            'max_comensales_publico' => (int)$this->max_comensales_publico,
            'whatsapp_reservas' => (string)($this->whatsapp_reservas ?? ''),
            'updated_at' => (string)($this->updated_at ?? ''),
        ];
    }
}
