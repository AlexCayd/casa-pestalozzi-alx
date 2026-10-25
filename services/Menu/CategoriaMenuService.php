<?php

/** Coordina persistencia y ciclo de vida de imagenes de categorias. */

namespace Services\Menu;

use Services\Security\ContextoNegocio;

use Classes\ImagenUploader;
use Model\ActiveRecord;
use Model\CategoriasMenu;

class CategoriaMenuService
{
    public const CREADA = 'CREADA';
    public const ACTUALIZADA = 'ACTUALIZADA';
    public const ELIMINADA = 'ELIMINADA';
    public const NO_EXISTE = 'NO_EXISTE';
    public const TIENE_PLATILLOS = 'TIENE_PLATILLOS';
    public const ERROR_GUARDADO = 'ERROR_GUARDADO';

    public static function crear(CategoriasMenu $categoria): array
    {
        if (ContextoNegocio::nuevo()) return CategoriaMenuServiceMultinegocio::crear($categoria);
        $db = ActiveRecord::getDB();
        try {
            $db->begin_transaction();
            $stmt = $db->prepare('INSERT INTO categorias (nombre, carta, img, activo) VALUES (?, ?, ?, ?)');
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }
            $activo = (int)$categoria->activo;
            $carta = CategoriasMenu::normalizarCarta($categoria->carta);
            $stmt->bind_param('sssi', $categoria->nombre, $carta, $categoria->img, $activo);
            self::ejecutar($stmt);
            $id = (int)$db->insert_id;
            $stmt->close();

            $fila = self::buscar($id);
            if (!$fila || (string)$fila['img'] !== (string)$categoria->img) {
                throw new \RuntimeException('No fue posible verificar la categoria creada.');
            }

            $db->commit();
            $categoria->id = $id;
            $categoria->carta = $carta;
            return ['ok' => true, 'codigo' => self::CREADA, 'categoria' => $categoria];
        } catch (\Throwable $e) {
            self::rollbackSeguro($db);
            ImagenUploader::eliminar($categoria->img);
            error_log('CategoriaMenuService::crear - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_GUARDADO, 'categoria' => $categoria];
        }
    }

    public static function actualizar(CategoriasMenu $categoria): array
    {
        if (ContextoNegocio::nuevo()) return CategoriaMenuServiceMultinegocio::actualizar($categoria);
        $id = (int)$categoria->id;
        if ($id < 1) {
            ImagenUploader::eliminar($categoria->img);
            return ['ok' => false, 'codigo' => self::NO_EXISTE, 'categoria' => $categoria];
        }

        $db = ActiveRecord::getDB();
        $imagenAnterior = null;
        try {
            $db->begin_transaction();
            $fila = self::buscar($id, true);
            if (!$fila) {
                $db->rollback();
                ImagenUploader::eliminar($categoria->img);
                return ['ok' => false, 'codigo' => self::NO_EXISTE, 'categoria' => $categoria];
            }
            $imagenAnterior = (string)($fila['img'] ?? '');

            $stmt = $db->prepare('UPDATE categorias SET nombre = ?, carta = ?, img = ?, activo = ? WHERE id = ? LIMIT 1');
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }
            $activo = (int)$categoria->activo;
            $carta = CategoriasMenu::normalizarCarta($categoria->carta);
            $stmt->bind_param('sssii', $categoria->nombre, $carta, $categoria->img, $activo, $id);
            self::ejecutar($stmt);
            $stmt->close();

            $guardada = self::buscar($id);
            if (!$guardada
                || (string)$guardada['nombre'] !== (string)$categoria->nombre
                || (string)$guardada['carta'] !== $carta
                || (string)$guardada['img'] !== (string)$categoria->img
                || (int)$guardada['activo'] !== $activo) {
                throw new \RuntimeException('El estado persistido no coincide con la categoria solicitada.');
            }

            $db->commit();
            if ((string)$categoria->img !== $imagenAnterior) {
                ImagenUploader::eliminar($imagenAnterior);
            }
            return ['ok' => true, 'codigo' => self::ACTUALIZADA, 'categoria' => $categoria];
        } catch (\Throwable $e) {
            self::rollbackSeguro($db);
            if ((string)$categoria->img !== (string)$imagenAnterior) {
                ImagenUploader::eliminar($categoria->img);
                $categoria->img = $imagenAnterior;
            }
            error_log('CategoriaMenuService::actualizar - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_GUARDADO, 'categoria' => $categoria];
        }
    }

    public static function eliminar(int $categoriaId): array
    {
        if (ContextoNegocio::nuevo()) return CategoriaMenuServiceMultinegocio::eliminar($categoriaId);
        if ($categoriaId < 1) {
            return ['ok' => false, 'codigo' => self::NO_EXISTE];
        }

        $db = ActiveRecord::getDB();
        try {
            $db->begin_transaction();
            $fila = self::buscar($categoriaId, true);
            if (!$fila) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::NO_EXISTE];
            }

            // Los platillos viven en `productos` desde la fusión. Antes esta
            // guarda miraba `menu`, así que una categoría usada solo por
            // productos se podía borrar y reventaba contra la FK.
            //
            // Incluye los productos retirados (activo = 0): la fila sigue viva
            // y la llave foránea a categorias impediría el DELETE de todos modos.
            $stmt = $db->prepare('SELECT id FROM productos WHERE categoria_id = ? LIMIT 1 FOR UPDATE');
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }
            $stmt->bind_param('i', $categoriaId);
            self::ejecutar($stmt);
            $tienePlatillos = (bool)$stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($tienePlatillos) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::TIENE_PLATILLOS];
            }

            $stmt = $db->prepare('DELETE FROM categorias WHERE id = ? LIMIT 1');
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }
            $stmt->bind_param('i', $categoriaId);
            self::ejecutar($stmt);
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new \RuntimeException('La eliminacion no afecto una categoria.');
            }
            $stmt->close();
            $db->commit();

            ImagenUploader::eliminar((string)($fila['img'] ?? ''));
            return ['ok' => true, 'codigo' => self::ELIMINADA];
        } catch (\Throwable $e) {
            self::rollbackSeguro($db);
            error_log('CategoriaMenuService::eliminar - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_GUARDADO];
        }
    }

    private static function buscar(int $id, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT id, nombre, carta, img, activo FROM categorias WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = ActiveRecord::getDB()->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }
        $stmt->bind_param('i', $id);
        self::ejecutar($stmt);
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $fila;
    }

    private static function ejecutar(\mysqli_stmt $stmt): void
    {
        if (!$stmt->execute()) {
            throw new \RuntimeException($stmt->error);
        }
    }

    private static function rollbackSeguro(\mysqli $db): void
    {
        try {
            $db->rollback();
        } catch (\Throwable $e) {
            error_log('CategoriaMenuService rollback - ' . $e->getMessage());
        }
    }
}



/** CRUD de categorías con ownership local y ciclo de vida transaccional de imagen. */
class CategoriaMenuServiceMultinegocio extends CategoriaMenuService
{
    public const CREADA='CREADA', ACTUALIZADA='ACTUALIZADA', ELIMINADA='ELIMINADA', NO_EXISTE='NO_EXISTE', TIENE_PLATILLOS='TIENE_PLATILLOS', ERROR_GUARDADO='ERROR_GUARDADO';

    public static function crear(CategoriasMenu $categoria): array
    {
        $ctx = ContextoNegocio::slug();
        ContextoNegocio::exigir('menu', 'editar', $ctx);
        if ($ctx === 'global') return ['ok'=>false,'codigo'=>self::ERROR_GUARDADO,'categoria'=>$categoria];
        $unidad = ContextoNegocio::unidad($ctx); $db = ActiveRecord::getDB();
        $resultado = ContextoNegocio::transaccion(function () use ($db,$unidad,$categoria) {
            ContextoNegocio::exigir('menu','editar',ContextoNegocio::slug(),true);
            $stmt=$db->prepare('INSERT INTO categorias (unidad_id,carta,nombre,img,activo,orden) VALUES (?,?,?,?,?,?)');
            if (!$stmt) throw new \RuntimeException($db->error);
            $carta=CategoriasMenu::normalizarCarta($categoria->carta); $nombre=(string)$categoria->nombre; $img=$categoria->img; $activo=(int)$categoria->activo; $orden=isset($categoria->orden)?(int)$categoria->orden:0;
            $stmt->bind_param('isssii',$unidad,$carta,$nombre,$img,$activo,$orden);
            self::ejecutar($stmt); $id=(int)$db->insert_id; $stmt->close();
            $fila=self::buscar($id,$unidad);
            if (!$fila || $fila['img'] !== $img) throw new \RuntimeException('No fue posible verificar la categoría creada.');
            return ['id'=>$id,'carta'=>$carta];
        });
        $data=self::payload($resultado);
        if (!$data) { ImagenUploader::eliminar($categoria->img); return ['ok'=>false,'codigo'=>self::ERROR_GUARDADO,'categoria'=>$categoria]; }
        $categoria->id=(int)$data['id']; $categoria->unidad_id=$unidad; $categoria->carta=$data['carta'];
        return ['ok'=>true,'codigo'=>self::CREADA,'categoria'=>$categoria];
    }

    public static function actualizar(CategoriasMenu $categoria): array
    {
        $ctx=ContextoNegocio::slug(); ContextoNegocio::exigir('menu','editar',$ctx);
        $id=(int)$categoria->id; $unidad=ContextoNegocio::unidad($ctx);
        if ($id<1 || $ctx==='global') { ImagenUploader::eliminar($categoria->img); return ['ok'=>false,'codigo'=>self::NO_EXISTE,'categoria'=>$categoria]; }
        $db=ActiveRecord::getDB(); $imagenAnterior=null;
        $resultado=ContextoNegocio::transaccion(function () use ($db,$id,$unidad,$categoria,&$imagenAnterior) {
            ContextoNegocio::exigir('menu','editar',ContextoNegocio::slug(),true);
            $fila=self::buscar($id,$unidad,true); if (!$fila) return ['no_existe'=>true];
            $imagenAnterior=(string)($fila['img']??'');
            $stmt=$db->prepare('UPDATE categorias SET nombre=?, carta=?, img=?, activo=?, orden=? WHERE id=? AND unidad_id=? LIMIT 1');
            if (!$stmt) throw new \RuntimeException($db->error);
            $nombre=(string)$categoria->nombre; $carta=CategoriasMenu::normalizarCarta($categoria->carta); $img=$categoria->img; $activo=(int)$categoria->activo; $orden=isset($categoria->orden)?(int)$categoria->orden:0;
            $stmt->bind_param('sssiiii',$nombre,$carta,$img,$activo,$orden,$id,$unidad); self::ejecutar($stmt); $stmt->close();
            $guardada=self::buscar($id,$unidad);
            if (!$guardada || $guardada['nombre']!==$nombre || $guardada['carta']!==$carta || $guardada['img']!==$img || (int)$guardada['activo']!==$activo || (int)$guardada['orden']!==$orden) throw new \RuntimeException('El estado persistido no coincide con la categoría solicitada.');
            return ['ok'=>true,'carta'=>$carta];
        });
        if (!empty($resultado['no_existe'])) {
            ImagenUploader::eliminar($categoria->img);
            $categoria->img=$imagenAnterior;
            return ['ok'=>false,'codigo'=>self::NO_EXISTE,'categoria'=>$categoria];
        }
        $data=self::payload($resultado);
        if (!$data || !isset($data['carta'])) {
            if ((string)$categoria->img!== (string)$imagenAnterior) { ImagenUploader::eliminar($categoria->img); $categoria->img=$imagenAnterior; }
            return ['ok'=>false,'codigo'=>self::ERROR_GUARDADO,'categoria'=>$categoria];
        }
        $categoria->carta=$data['carta'];
        if ((string)$categoria->img!==$imagenAnterior) ImagenUploader::eliminar($imagenAnterior);
        return ['ok'=>true,'codigo'=>self::ACTUALIZADA,'categoria'=>$categoria];
    }

    public static function eliminar(int $categoriaId): array
    {
        $ctx=ContextoNegocio::slug(); ContextoNegocio::exigir('menu','editar',$ctx); $unidad=ContextoNegocio::unidad($ctx);
        if ($categoriaId<1 || $ctx==='global') return ['ok'=>false,'codigo'=>self::NO_EXISTE];
        $db=ActiveRecord::getDB();
        $resultado=ContextoNegocio::transaccion(function () use ($db,$categoriaId,$unidad) {
            ContextoNegocio::exigir('menu','editar',ContextoNegocio::slug(),true);
            $fila=self::buscar($categoriaId,$unidad,true); if (!$fila) return ['codigo'=>self::NO_EXISTE];
            $stmt=$db->prepare('SELECT id FROM ofertas_producto WHERE categoria_id=? AND unidad_id=? LIMIT 1 FOR UPDATE');
            if (!$stmt) throw new \RuntimeException($db->error);
            $stmt->bind_param('ii',$categoriaId,$unidad); self::ejecutar($stmt); $tiene=(bool)$stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($tiene) return ['codigo'=>self::TIENE_PLATILLOS];
            $stmt=$db->prepare('DELETE FROM categorias WHERE id=? AND unidad_id=? LIMIT 1');
            if (!$stmt) throw new \RuntimeException($db->error);
            $stmt->bind_param('ii',$categoriaId,$unidad); self::ejecutar($stmt); $deleted=$stmt->affected_rows===1; $stmt->close();
            if (!$deleted) throw new \RuntimeException('La eliminación no afectó una categoría.');
            return ['codigo'=>self::ELIMINADA,'img'=>$fila['img']??null];
        });
        $data=self::payload($resultado);
        if (!$data) return ['ok'=>false,'codigo'=>self::ERROR_GUARDADO];
        if ($data['codigo']===self::ELIMINADA) { ImagenUploader::eliminar((string)($data['img']??'')); return ['ok'=>true,'codigo'=>self::ELIMINADA]; }
        return ['ok'=>false,'codigo'=>$data['codigo']];
    }

    private static function buscar(int $id,int $unidad,bool $lock=false): ?array
    {
        $stmt=ActiveRecord::getDB()->prepare('SELECT id,unidad_id,nombre,carta,img,activo,orden FROM categorias WHERE id=? AND unidad_id=? LIMIT 1'.($lock?' FOR UPDATE':''));
        if (!$stmt) throw new \RuntimeException(ActiveRecord::getDB()->error);
        $stmt->bind_param('ii',$id,$unidad); self::ejecutar($stmt); $fila=$stmt->get_result()->fetch_assoc()?:null; $stmt->close(); return $fila;
    }

    private static function payload(array $wrapper): array
    {
        if (empty($wrapper['ok']) || empty($wrapper['confirmado'])) return [];
        return $wrapper;
    }
    private static function ejecutar(\mysqli_stmt $stmt): void { if (!$stmt->execute()) throw new \RuntimeException($stmt->error); }
}
