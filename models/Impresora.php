<?php
namespace Model;

class Impresora extends ActiveRecord {

    protected static $tabla = 'impresoras';

    protected static $columnasDB = [
        'id', 'nombre', 'area_id', 'rol', 'conexion', 'host', 'puerto', 'dispositivo', 'ancho', 'activo'
    ];

    public $id;
    public $nombre;
    public $area_id     = null;          // NULL para la impresora de cuenta
    public $rol         = 'comanda';     // 'comanda' | 'cuenta'
    public $conexion    = 'red';         // 'red' (TCP 9100) | 'windows' (spooler)
    public $host;
    public $puerto      = 9100;
    public $dispositivo = null;          // windows: nombre de impresora o smb://host/recurso
    public $ancho       = 48;            // columnas de caracteres del papel
    public $activo      = 1;

    // Validación para el CRUD admin (mismo patrón que Menu/CategoriasMenu).
    public function validar() {
        static::$alertas = [];

        if (!trim((string)$this->nombre)) {
            static::setAlerta('error', 'El nombre de la impresora es obligatorio');
        }
        if (!in_array($this->rol, ['comanda', 'cuenta'], true)) {
            static::setAlerta('error', 'El rol debe ser comanda o cuenta');
        }
        if (!in_array($this->conexion, ['red', 'windows'], true)) {
            static::setAlerta('error', 'El tipo de conexión debe ser red o windows');
        }

        // Los datos de red (host/puerto) sólo aplican a conexión de red; el
        // dispositivo aplica a windows (nombre de impresora / smb).
        if ($this->conexion === 'red') {
            if (!trim((string)$this->host)) {
                static::setAlerta('error', 'El host / IP es obligatorio para una impresora de red');
            }

            $puerto = filter_var($this->puerto, FILTER_VALIDATE_INT);
            if ($puerto === false || $puerto < 1 || $puerto > 65535) {
                static::setAlerta('error', 'El puerto debe ser un número entre 1 y 65535');
            }
        } else { // windows
            if (!trim((string)$this->dispositivo)) {
                static::setAlerta('error', 'El nombre de la impresora de Windows (o smb://host/recurso) es obligatorio');
            }
        }

        $ancho = filter_var($this->ancho, FILTER_VALIDATE_INT);
        if ($ancho === false || $ancho < 1) {
            static::setAlerta('error', 'El ancho debe ser un número positivo (p.ej. 32 o 48)');
        }

        // Una comanda imprime por área; la cuenta no se asocia a ningún área.
        if ($this->rol === 'comanda' && !$this->area_id) {
            static::setAlerta('error', 'Una impresora de comanda debe tener un área asignada');
        }

        return static::$alertas;
    }

    // Descripción legible del destino físico, según el tipo de conexión.
    public function destino(): string {
        if ($this->conexion === 'windows') {
            return (string)($this->dispositivo ?? '');
        }
        return $this->host . ':' . $this->puerto;
    }

    // Impresora de comanda activa asignada a un área de producción
    public static function comandaPorArea($area_id) {
        $area_id = (int)$area_id;
        $resultado = self::consultarSQL(
            "SELECT * FROM " . static::$tabla . "
             WHERE rol = 'comanda' AND area_id = {$area_id} AND activo = 1
             ORDER BY id ASC
             LIMIT 1"
        );
        return array_shift($resultado);
    }

    // Impresora de cuenta activa (la que imprime el ticket de cobro)
    public static function cuenta() {
        $resultado = self::consultarSQL(
            "SELECT * FROM " . static::$tabla . "
             WHERE rol = 'cuenta' AND activo = 1
             ORDER BY id ASC
             LIMIT 1"
        );
        return array_shift($resultado);
    }
}
