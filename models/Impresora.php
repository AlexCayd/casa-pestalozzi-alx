<?php
namespace Model;

use Services\Security\ContextoNegocio;
use Services\Inventory\InventarioError;

class Impresora extends ActiveRecord {

    protected static $tabla = 'impresoras';

    protected static $columnasDB = [
        'id', 'nombre', 'area_id', 'rol', 'conexion', 'host', 'puerto', 'dispositivo', 'ancho', 'activo'
    ];

    public $id;
    public $unidad_id = null;
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
    public static function all() {
        if (!ContextoNegocio::nuevo()) return parent::all();
        ContextoNegocio::exigir('printers','ver','restaurante');
        $unidad=ContextoNegocio::unidad('restaurante');
        $stmt=self::getDB()->prepare('SELECT * FROM impresoras WHERE unidad_id=? ORDER BY id DESC');
        $stmt->bind_param('i',$unidad); $stmt->execute(); $rows=$stmt->get_result()->fetch_all(\MYSQLI_ASSOC); $stmt->close();
        return array_map(static fn($row)=>static::crearObjeto($row),$rows);
    }

    public static function find($id, ?string $ctx = null) {
        if (!ContextoNegocio::nuevo()) return parent::find((int)$id);
        $ctx='restaurante'; ContextoNegocio::exigir('printers','ver',$ctx);
        $unidad=ContextoNegocio::unidad($ctx); $id=(int)$id;
        if($id<1) return null;
        $stmt=self::getDB()->prepare('SELECT * FROM impresoras WHERE id=? AND unidad_id=? LIMIT 1');
        $stmt->bind_param('ii',$id,$unidad); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        return $row?static::crearObjeto($row):null;
    }

    public static function where($columna,$valor) {
        if(!ContextoNegocio::nuevo()) return parent::where($columna,$valor);
        ContextoNegocio::exigir('printers','ver','restaurante');
        if(!in_array((string)$columna,['id','nombre','area_id','rol','conexion','activo'],true)) throw new \InvalidArgumentException('Columna de impresora no permitida.');
        $unidad=ContextoNegocio::unidad('restaurante'); $db=self::getDB();
        $stmt=$db->prepare('SELECT * FROM impresoras WHERE '.$columna.'=? AND unidad_id=? LIMIT 1');
        $stmt->bind_param('si',$valor,$unidad); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        return $row?static::crearObjeto($row):null;
    }

    public static function total($columna='',$valor='') {
        if(!ContextoNegocio::nuevo()) return parent::total($columna,$valor);
        ContextoNegocio::exigir('printers','ver','restaurante');
        $unidad=ContextoNegocio::unidad('restaurante'); $sql='SELECT COUNT(*) FROM impresoras WHERE unidad_id=?'; $params=[$unidad];
        if($columna!=='') {
            if(!in_array((string)$columna,['id','nombre','area_id','rol','conexion','activo'],true)) throw new \InvalidArgumentException('Columna de impresora no permitida.');
            $sql.=' AND '.$columna.'=?'; $params[]=$valor;
        }
        $stmt=self::getDB()->prepare($sql); $stmt->execute($params); $n=(int)$stmt->get_result()->fetch_row()[0]; $stmt->close(); return $n;
    }

    public static function paginar($porPagina,$offset) {
        if(!ContextoNegocio::nuevo()) return parent::paginar($porPagina,$offset);
        ContextoNegocio::exigir('printers','ver','restaurante');
        $unidad=ContextoNegocio::unidad('restaurante'); $porPagina=max(1,min(100,(int)$porPagina)); $offset=max(0,(int)$offset);
        $stmt=self::getDB()->prepare('SELECT * FROM impresoras WHERE unidad_id=? ORDER BY id ASC LIMIT ? OFFSET ?');
        $stmt->bind_param('iii',$unidad,$porPagina,$offset); $stmt->execute(); $rows=$stmt->get_result()->fetch_all(\MYSQLI_ASSOC); $stmt->close();
        return array_map(static fn($row)=>static::crearObjeto($row),$rows);
    }

    public static function get($limite) {
        if(!ContextoNegocio::nuevo()) return parent::get($limite);
        ContextoNegocio::exigir('printers','ver','restaurante');
        $unidad=ContextoNegocio::unidad('restaurante'); $limite=max(1,min(100,(int)$limite));
        $stmt=self::getDB()->prepare('SELECT * FROM impresoras WHERE unidad_id=? ORDER BY id DESC LIMIT ?');
        $stmt->bind_param('ii',$unidad,$limite); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        return $row?static::crearObjeto($row):null;
    }

    public function crear() {
        if (!ContextoNegocio::nuevo()) return parent::crear();
        return $this->persistirNuevo(false);
    }

    public function actualizar() {
        if (!ContextoNegocio::nuevo()) return parent::actualizar();
        return $this->persistirNuevo(true);
    }

    private function persistirNuevo(bool $actualizar): bool
    {
        $ctx='restaurante';
        $r=ContextoNegocio::transaccion(function() use($actualizar,$ctx): array {
            ContextoNegocio::exigir('printers','editar',$ctx,true);
            $db=ContextoNegocio::db(); $unidad=ContextoNegocio::unidad($ctx);
            $alertas=$this->validar();
            if($alertas!==[]) throw new InventarioError('IMPRESORA_INVALIDA','Revisa los datos de la impresora.');
            if($actualizar) {
                $id=(int)$this->id;
                if($id<1) throw new InventarioError('ID_INVALIDO','La impresora no es válida.');
                $check=$db->prepare('SELECT id FROM impresoras WHERE id=? AND unidad_id=? LIMIT 1 FOR UPDATE');
                $check->bind_param('ii',$id,$unidad); $check->execute(); $existe=$check->get_result()->fetch_row(); $check->close();
                if(!$existe) throw new InventarioError('RECURSO_NO_ENCONTRADO','La impresora no pertenece al Restaurante.');
            }
            $this->validarArea($db,$unidad);
            $area=$this->area_id!==null?(int)$this->area_id:null;
            $nombre=trim((string)$this->nombre); $rol=(string)$this->rol; $conexion=(string)$this->conexion;
            $host=(string)$this->host; $puerto=(int)$this->puerto; $dispositivo=$this->dispositivo!==null?(string)$this->dispositivo:null;
            $ancho=(int)$this->ancho; $activo=(int)$this->activo;
            if($actualizar) {
                $stmt=$db->prepare('UPDATE impresoras SET nombre=?,area_id=?,rol=?,conexion=?,host=?,puerto=?,dispositivo=?,ancho=?,activo=? WHERE id=? AND unidad_id=?');
                if(!$stmt) throw new \RuntimeException($db->error);
                $id=(int)$this->id; $stmt->bind_param('sisssisiiii',$nombre,$area,$rol,$conexion,$host,$puerto,$dispositivo,$ancho,$activo,$id,$unidad);
            } else {
                $stmt=$db->prepare('INSERT INTO impresoras(unidad_id,nombre,area_id,rol,conexion,host,puerto,dispositivo,ancho,activo) VALUES(?,?,?,?,?,?,?,?,?,?)');
                if(!$stmt) throw new \RuntimeException($db->error);
                $stmt->bind_param('isisssisii',$unidad,$nombre,$area,$rol,$conexion,$host,$puerto,$dispositivo,$ancho,$activo);
            }
            if(!$stmt->execute()) throw new \RuntimeException($stmt->error);
            if($actualizar && $stmt->affected_rows<0) throw new \RuntimeException($stmt->error);
            if(!$actualizar) $this->id=(int)$db->insert_id;
            $stmt->close();
            return ['resultado'=>true];
        });
        return ($r['ok']??false)===true || ($r['ok']??null)===null;
    }

    private function validarArea(\mysqli $db,int $unidad): void
    {
        if($this->rol==='cuenta') { $this->area_id=null; return; }
        $area=(int)$this->area_id;
        if($area<1) throw new InventarioError('AREA_INVALIDA','Una impresora de comanda requiere un área local.');
        $stmt=$db->prepare('SELECT id FROM areas_produccion WHERE id=? AND unidad_id=? LIMIT 1 FOR SHARE');
        $stmt->bind_param('ii',$area,$unidad); $stmt->execute(); $ok=$stmt->get_result()->fetch_row(); $stmt->close();
        if(!$ok) throw new InventarioError('AREA_NO_AUTORIZADA','El área no pertenece al Restaurante.');
    }

    public function eliminar() {
        if (!ContextoNegocio::nuevo()) return parent::eliminar();
        $r=ContextoNegocio::transaccion(function(): array {
            ContextoNegocio::exigir('printers','editar','restaurante',true);
            $db=ContextoNegocio::db(); $id=(int)$this->id; $unidad=ContextoNegocio::unidad('restaurante');
            $stmt=$db->prepare('DELETE FROM impresoras WHERE id=? AND unidad_id=?');
            $stmt->bind_param('ii',$id,$unidad);
            if(!$stmt->execute() || $stmt->affected_rows!==1) throw new InventarioError('RECURSO_NO_ENCONTRADO','La impresora no pertenece al Restaurante.');
            $stmt->close(); return ['resultado'=>true];
        });
        return ($r['ok']??false)===true || ($r['ok']??null)===null;
    }

    public static function comandaPorArea($area_id, ?string $ctx = null, bool $requierePermiso = false) {
        $area_id = (int)$area_id;
        if (ContextoNegocio::nuevo()) {
            $ctx='restaurante'; if($requierePermiso) ContextoNegocio::exigir('printers','ver',$ctx);
            $unidad=ContextoNegocio::unidad($ctx); $db=self::getDB();
            $stmt=$db->prepare("SELECT p.* FROM impresoras p JOIN areas_produccion a ON a.id=p.area_id AND a.unidad_id=p.unidad_id WHERE p.unidad_id=? AND p.area_id=? AND p.rol='comanda' AND p.activo=1 ORDER BY p.id LIMIT 1");
            $stmt->bind_param('ii',$unidad,$area_id); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
            return $row?static::crearObjeto($row):null;
        }
        $resultado = self::consultarSQL(
            "SELECT * FROM " . static::$tabla . "
             WHERE rol = 'comanda' AND area_id = {$area_id} AND activo = 1
             ORDER BY id ASC
             LIMIT 1"
        );
        return array_shift($resultado);
    }

    // Impresora de cuenta activa (la que imprime el ticket de cobro)
    public static function cuenta(?string $ctx = null, bool $requierePermiso = false) {
        if (ContextoNegocio::nuevo()) {
            $ctx='restaurante'; if($requierePermiso) ContextoNegocio::exigir('printers','ver',$ctx);
            $unidad=ContextoNegocio::unidad($ctx); $stmt=self::getDB()->prepare("SELECT * FROM impresoras WHERE unidad_id=? AND rol='cuenta' AND activo=1 ORDER BY id LIMIT 1");
            $stmt->bind_param('i',$unidad); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
            return $row?static::crearObjeto($row):null;
        }
        $resultado = self::consultarSQL(
            "SELECT * FROM " . static::$tabla . "
             WHERE rol = 'cuenta' AND activo = 1
             ORDER BY id ASC
             LIMIT 1"
        );
        return array_shift($resultado);
    }
}
