<?php
namespace Model;

use Services\Security\ContextoNegocio;

/** CRUD de raíces operativas de Restaurante; no transforma SQL de consumidores. */
abstract class RegistroRestaurante extends ActiveRecord
{
    public $unidad_id;

    public static function unidadRestaurante(): int
    {
        return ContextoNegocio::unidad('restaurante');
    }

    public static function filtroUnidad(string $alias = ''): string
    {
        if (!ContextoNegocio::nuevo()) return '';
        if ($alias !== '' && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$alias)) throw new \InvalidArgumentException('Alias inválido.');
        return ' AND '.($alias!==''?$alias.'.':'').'unidad_id='.static::unidadRestaurante();
    }

    private static function columna(string $columna): string
    {
        if (!in_array($columna,array_merge(static::$columnasDB,['unidad_id']),true)) throw new \InvalidArgumentException('Columna inválida.');
        return $columna;
    }

    public static function all()
    {
        if (!ContextoNegocio::nuevo()) return parent::all();
        return static::consultarSQL('SELECT * FROM '.static::$tabla.' WHERE unidad_id='.static::unidadRestaurante().' ORDER BY id DESC');
    }

    public static function find($id)
    {
        if (!ContextoNegocio::nuevo()) return parent::find($id);
        $id=(int)$id;
        if ($id<1) return null;
        $rows=static::consultarSQL('SELECT * FROM '.static::$tabla.' WHERE id='.$id.static::filtroUnidad().' LIMIT 1');
        return $rows[0]??null;
    }

    public static function where($columna,$valor)
    {
        if (!ContextoNegocio::nuevo()) return parent::where($columna,$valor);
        $columna=static::columna((string)$columna);
        $stmt=static::getDB()->prepare('SELECT * FROM '.static::$tabla.' WHERE '.$columna.'=? AND unidad_id=? LIMIT 1');
        $stmt->execute([$valor,static::unidadRestaurante()]);
        $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        return $row?static::crearObjeto($row):null;
    }

    public static function total($columna='',$valor='')
    {
        if (!ContextoNegocio::nuevo()) return parent::total($columna,$valor);
        $sql='SELECT COUNT(*) FROM '.static::$tabla.' WHERE unidad_id=?';
        $valores=[static::unidadRestaurante()];
        if ($columna!=='') { $sql.=' AND '.static::columna((string)$columna).'=?'; $valores[]=$valor; }
        $stmt=static::getDB()->prepare($sql); $stmt->execute($valores);
        $total=(int)$stmt->get_result()->fetch_row()[0]; $stmt->close(); return $total;
    }

    public static function paginar($por_pagina,$offset)
    {
        if (!ContextoNegocio::nuevo()) return parent::paginar($por_pagina,$offset);
        return static::consultarSQL('SELECT * FROM '.static::$tabla.' WHERE unidad_id='.static::unidadRestaurante().' ORDER BY id ASC LIMIT '.max(1,(int)$por_pagina).' OFFSET '.max(0,(int)$offset));
    }

    public static function get($limite)
    {
        if (!ContextoNegocio::nuevo()) return parent::get($limite);
        $rows=static::consultarSQL('SELECT * FROM '.static::$tabla.' WHERE unidad_id='.static::unidadRestaurante().' ORDER BY id DESC LIMIT '.max(1,(int)$limite));
        return $rows[0]??null;
    }

    public function crear()
    {
        if (!ContextoNegocio::nuevo()) return parent::crear();
        $atributos=$this->atributos(); unset($atributos['unidad_id']);
        $atributos['unidad_id']=static::unidadRestaurante();
        $stmt=static::getDB()->prepare('INSERT INTO '.static::$tabla.' ('.implode(',',array_keys($atributos)).') VALUES ('.implode(',',array_fill(0,count($atributos),'?')).')');
        $ok=$stmt->execute(array_values($atributos)); $id=(int)static::getDB()->insert_id; $stmt->close();
        if ($ok) $this->unidad_id=static::unidadRestaurante();
        return ['resultado'=>$ok,'id'=>$id];
    }

    public function actualizar()
    {
        if (!ContextoNegocio::nuevo()) return parent::actualizar();
        $atributos=$this->atributos(); unset($atributos['unidad_id']);
        $stmt=static::getDB()->prepare('UPDATE '.static::$tabla.' SET '.implode(',',array_map(static fn(string $key): string=>$key.'=?',array_keys($atributos))).' WHERE id=? AND unidad_id=? LIMIT 1');
        $stmt->execute(array_merge(array_values($atributos),[(int)$this->id,static::unidadRestaurante()]));
        $ok=$stmt->affected_rows>0; $stmt->close(); return $ok;
    }

    public function eliminar()
    {
        if (!ContextoNegocio::nuevo()) return parent::eliminar();
        $stmt=static::getDB()->prepare('DELETE FROM '.static::$tabla.' WHERE id=? AND unidad_id=? LIMIT 1');
        $stmt->execute([(int)$this->id,static::unidadRestaurante()]); $ok=$stmt->affected_rows===1; $stmt->close(); return $ok;
    }
}
