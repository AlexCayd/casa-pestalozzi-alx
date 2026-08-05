<?php

namespace Model;

/**
 * Problema reportado desde el panel (modal "Reportar un problema").
 *
 * `ruta_origen` es lo que hace útil el módulo: guarda la pantalla exacta desde
 * donde se reportó, para poder abrirla y reproducir el fallo sin adivinar.
 */
class ReporteSistema extends ActiveRecord
{
    protected static $tabla = 'reportes_sistema';

    protected static $columnasDB = [
        'id',
        'usuario_id',
        'modulo',
        'titulo',
        'descripcion',
        'ruta_origen',
        'navegador',
        'navegador_otro',
        'estado',
        'resuelto_at',
        'created_at',
        'updated_at',
    ];

    public $id;
    public $usuario_id;
    public $modulo;
    public $titulo;
    public $descripcion;
    public $ruta_origen;
    public $navegador;
    public $navegador_otro;
    public $estado = 'nuevo';
    public $resuelto_at;
    public $created_at;
    public $updated_at;

    public const ESTADOS = ['nuevo', 'en_revision', 'resuelto', 'descartado'];
    public const NAVEGADORES = ['chrome', 'edge', 'firefox', 'safari', 'otro'];

    /** Los más recientes primero: un reporte nuevo es el que necesita atención. */
    public static function recientes(int $limite = 200): array
    {
        $limite = max(1, min($limite, 500));

        return self::consultarSQL(
            "SELECT * FROM " . static::$tabla . " ORDER BY created_at DESC, id DESC LIMIT {$limite}"
        );
    }
}
