<?php

namespace Model;

/**
 * Ajustes del punto de venta. Fila única (id = 1), igual que
 * ConfiguracionAnuncio: son preferencias del sistema, no un catálogo.
 */
class ConfiguracionPos extends ActiveRecord
{
    private const ID_UNICO = 1;
    protected static $tabla = 'configuracion_pos';
    protected static $columnasDB = [
        'id',
        'mesero_editable',
        'updated_by',
        'updated_at',
    ];

    public $id = self::ID_UNICO;
    public $mesero_editable = 1;
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
        $this->mesero_editable = (int) (bool) $this->mesero_editable;
    }

    public static function obtener(): ?self
    {
        $stmt = self::getDB()->prepare(
            'SELECT * FROM ' . static::$tabla . ' WHERE id = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la consulta de la configuración del POS.');
        }

        $id = self::ID_UNICO;
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('No fue posible consultar la configuración del POS.');
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
            throw new \RuntimeException('No fue posible preparar la configuración inicial del POS.');
        }

        $id = self::ID_UNICO;
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('No fue posible inicializar la configuración del POS.');
        }
        $stmt->close();

        $configuracion = static::obtener();
        if (!$configuracion) {
            throw new \RuntimeException('No fue posible recuperar la configuración del POS.');
        }

        return $configuracion;
    }

    /**
     * Lectura tolerante para el POS: si la tabla todavía no existe (base sin
     * migrar) se responde con el valor histórico —campo editable— en vez de
     * tumbar el mapa de mesas, que es la pantalla de trabajo del turno.
     */
    public static function meseroEditable(): bool
    {
        try {
            $configuracion = static::obtener();
        } catch (\Throwable $e) {
            error_log('ConfiguracionPos::meseroEditable - ' . $e->getMessage());
            return true;
        }

        return $configuracion === null || (int) $configuracion->mesero_editable === 1;
    }

    public function guardarConfiguracion(): bool
    {
        $stmt = self::getDB()->prepare(
            'INSERT INTO ' . static::$tabla . ' (id, mesero_editable, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                mesero_editable = VALUES(mesero_editable),
                updated_by = VALUES(updated_by)'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el guardado de la configuración del POS.');
        }

        $id = self::ID_UNICO;
        $editable = (int) (bool) $this->mesero_editable;
        $updatedBy = $this->updated_by !== null ? (int) $this->updated_by : null;
        $stmt->bind_param('iii', $id, $editable, $updatedBy);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }

    public function valoresFormulario(): array
    {
        return [
            'mesero_editable' => (int) $this->mesero_editable === 1,
            'updated_at' => (string) ($this->updated_at ?? ''),
        ];
    }
}
