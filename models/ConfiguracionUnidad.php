<?php
namespace Model;

use Services\Security\ContextoNegocio;

/** Persistencia de preferencias existentes de Restaurante, con PK unidad/id. */
abstract class ConfiguracionUnidad extends RegistroRestaurante
{
    protected static function asegurarFila(): void
    {
        $stmt=static::getDB()->prepare('INSERT INTO '.static::$tabla.'(unidad_id,id) VALUES (?,1) ON DUPLICATE KEY UPDATE id=VALUES(id)');
        $stmt->execute([static::unidadRestaurante()]); $stmt->close();
    }

    protected static function guardarCampos(array $campos): bool
    {
        $resultado=ContextoNegocio::transaccion(static function () use($campos): array {
            ContextoNegocio::exigir('configuration','editar','restaurante',true);
            foreach(array_keys($campos) as $columna) {
                if (!in_array($columna,static::$columnasDB,true) || in_array($columna,['id','unidad_id','updated_at','updated_by'],true)) throw new \InvalidArgumentException('Preferencia inválida.');
            }
            $campos['updated_by']=ContextoNegocio::actorId();
            $nombres=array_keys($campos);
            $stmt=static::getDB()->prepare('INSERT INTO '.static::$tabla.'(unidad_id,id,'.implode(',',$nombres).') VALUES (?,1,'.implode(',',array_fill(0,count($campos),'?')).') ON DUPLICATE KEY UPDATE '.implode(',',array_map(static fn(string $c): string=>$c.'=VALUES('.$c.')',$nombres)));
            $stmt->execute(array_merge([static::unidadRestaurante()],array_values($campos))); $stmt->close();
            return ['guardado'=>true];
        });
        return ($resultado['ok']??null)!==false && !empty($resultado['guardado']);
    }
}
