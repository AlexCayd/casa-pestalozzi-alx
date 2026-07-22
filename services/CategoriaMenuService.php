<?php

/** Coordina persistencia y ciclo de vida de imagenes de categorias. */

namespace Services;

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
        $db = ActiveRecord::getDB();
        try {
            $db->begin_transaction();
            $stmt = $db->prepare('INSERT INTO categorias (nombre, img, activo) VALUES (?, ?, ?)');
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }
            $activo = (int)$categoria->activo;
            $stmt->bind_param('ssi', $categoria->nombre, $categoria->img, $activo);
            self::ejecutar($stmt);
            $id = (int)$db->insert_id;
            $stmt->close();

            $fila = self::buscar($id);
            if (!$fila || (string)$fila['img'] !== (string)$categoria->img) {
                throw new \RuntimeException('No fue posible verificar la categoria creada.');
            }

            $db->commit();
            $categoria->id = $id;
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

            $stmt = $db->prepare('UPDATE categorias SET nombre = ?, img = ?, activo = ? WHERE id = ? LIMIT 1');
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }
            $activo = (int)$categoria->activo;
            $stmt->bind_param('ssii', $categoria->nombre, $categoria->img, $activo, $id);
            self::ejecutar($stmt);
            $stmt->close();

            $guardada = self::buscar($id);
            if (!$guardada
                || (string)$guardada['nombre'] !== (string)$categoria->nombre
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

            $stmt = $db->prepare('SELECT id FROM menu WHERE categoria_id = ? LIMIT 1 FOR UPDATE');
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
        $sql = 'SELECT id, nombre, img, activo FROM categorias WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
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
