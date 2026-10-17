<?php

namespace Model;

/**
 * Paleta de la landing. Fila única (id = 1), igual que ConfiguracionAnuncio.
 *
 * Sólo persiste: qué color es válido, cuál es el de marca y cómo se pinta vive
 * en Services\Configuration\PaletaLanding. Cada columna es NULL mientras el
 * color siga siendo el del manual (ver el comentario de la tabla en database/schema.sql).
 */
class ConfiguracionPaleta extends ActiveRecord
{
    private const ID_UNICO = 1;
    /** Las columnas de color, en el orden en que el panel las presenta. */
    public const COLORES = ['verde', 'cafe', 'beige', 'crema'];

    protected static $tabla = 'configuracion_paleta';
    protected static $columnasDB = [
        'id',
        'verde',
        'cafe',
        'beige',
        'crema',
        'updated_by',
        'updated_at',
    ];

    public $id = self::ID_UNICO;
    public $verde = null;
    public $cafe = null;
    public $beige = null;
    public $crema = null;
    public $updated_by = null;
    public $updated_at = null;

    public static function obtener(): ?self
    {
        $stmt = self::getDB()->prepare(
            'SELECT * FROM ' . static::$tabla . ' WHERE id = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la consulta de la paleta.');
        }

        $id = self::ID_UNICO;
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('No fue posible consultar la paleta.');
        }

        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $fila ? static::crearObjeto($fila) : null;
    }

    /**
     * Guarda los cuatro colores a la vez: la paleta se edita en un solo
     * formulario y no comparte fila con ningún otro ajuste, así que no hay
     * columna ajena que pisar. Un NULL vuelve ese color al de marca.
     *
     * @param array<string, ?string> $colores ya normalizados por PaletaLanding
     */
    public static function guardarColores(array $colores, ?int $usuarioId): bool
    {
        $stmt = self::getDB()->prepare(
            'INSERT INTO ' . static::$tabla . ' (id, verde, cafe, beige, crema, updated_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                verde = VALUES(verde),
                cafe = VALUES(cafe),
                beige = VALUES(beige),
                crema = VALUES(crema),
                updated_by = VALUES(updated_by)'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el guardado de la paleta.');
        }

        $id = self::ID_UNICO;
        $verde = $colores['verde'] ?? null;
        $cafe = $colores['cafe'] ?? null;
        $beige = $colores['beige'] ?? null;
        $crema = $colores['crema'] ?? null;
        $stmt->bind_param('issssi', $id, $verde, $cafe, $beige, $crema, $usuarioId);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}
