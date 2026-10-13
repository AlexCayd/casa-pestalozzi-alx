<?php

namespace Services\Configuration;

use Model\ActiveRecord;

/**
 * Qué módulos del panel de administración están a la vista.
 *
 * El catálogo NO vive aquí: es AdminController::MODULES, que es lo que pinta
 * el sidebar. Este servicio sólo guarda la excepción —los módulos que el
 * administrador apagó— en `configuracion_modulos`, y por eso un módulo sin
 * fila cuenta como visible: uno nuevo aparece solo al desplegarlo.
 *
 * Alcance: únicamente las vistas de /admin. El POS de meseros, los tableros de
 * cocina y el mapa operativo de reservaciones (que cuelga de /admin/ pero lo
 * usa el piso) no consultan esto nunca.
 */
final class ModuloVisibilidadService
{
    /**
     * Módulos que no se pueden ocultar. Configuración es donde vive este
     * interruptor: si se pudiera apagar, la única forma de volver a encenderla
     * sería escribir en la base a mano.
     */
    public const BLOQUEADOS = ['configuration'];

    /** @var array<string, bool>|null Caché por petición: el layout la consulta varias veces. */
    private static ?array $ocultos = null;

    /**
     * Claves de los módulos ocultos.
     *
     * Lectura tolerante, con el mismo criterio que ConfiguracionPos: si la
     * tabla no existe todavía (base sin migrar) se responde «nada oculto». El
     * interruptor sólo puede quitar algo de la vista; un fallo de lectura
     * nunca debe dejar al administrador sin panel.
     *
     * @return array<string, bool>
     */
    public static function ocultos(): array
    {
        if (self::$ocultos !== null) {
            return self::$ocultos;
        }

        self::$ocultos = [];
        try {
            $resultado = ActiveRecord::getDB()->query(
                'SELECT clave FROM configuracion_modulos WHERE visible = 0'
            );
            if ($resultado) {
                while ($fila = $resultado->fetch_assoc()) {
                    $clave = (string) $fila['clave'];
                    if (!in_array($clave, self::BLOQUEADOS, true)) {
                        self::$ocultos[$clave] = true;
                    }
                }
                $resultado->free();
            }
        } catch (\Throwable $e) {
            error_log('ModuloVisibilidadService::ocultos - ' . $e->getMessage());
        }

        return self::$ocultos;
    }

    public static function esVisible(string $clave): bool
    {
        return !isset(self::ocultos()[$clave]);
    }

    public static function esBloqueado(string $clave): bool
    {
        return in_array($clave, self::BLOQUEADOS, true);
    }

    /**
     * El catálogo sin los módulos ocultos, en el mismo orden.
     *
     * @param array<string, array> $modulos
     * @return array<string, array>
     */
    public static function filtrarVisibles(array $modulos): array
    {
        $ocultos = self::ocultos();

        return array_filter(
            $modulos,
            static fn ($clave): bool => !isset($ocultos[(string) $clave]),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Módulo al que pertenece una URL del panel, o null si no es de ninguno.
     *
     * Casa por prefijo de segmento completo: '/admin/area' cubre
     * '/admin/area/cocina' pero no '/admin/areas'. Cada módulo declara sus
     * prefijos en 'rutas'; sin esa clave vale su 'path'.
     *
     * @param array<string, array> $modulos
     */
    public static function moduloDeRuta(string $url, array $modulos): ?string
    {
        foreach ($modulos as $clave => $modulo) {
            $prefijos = $modulo['rutas'] ?? [$modulo['path'] ?? ''];
            foreach ($prefijos as $prefijo) {
                if ($prefijo === '') {
                    continue;
                }
                if ($url === $prefijo || str_starts_with($url, $prefijo . '/')) {
                    return (string) $clave;
                }
            }
        }

        return null;
    }

    /**
     * Ruta del primer módulo visible: a donde aterriza /admin y a donde apunta
     * la marca del sidebar cuando Análisis está oculto.
     *
     * @param array<string, array> $modulos
     */
    public static function rutaInicial(array $modulos): string
    {
        $visibles = self::filtrarVisibles($modulos);
        $primero = reset($visibles);

        return is_array($primero) ? (string) $primero['path'] : '/admin/configuracion';
    }

    /**
     * Guarda la visibilidad de TODO el catálogo de una vez.
     *
     * Se escriben todas las filas y no sólo las que cambiaron: el formulario
     * manda el estado completo, y así la tabla refleja exactamente lo que se
     * vio en pantalla al guardar. Las claves que no están en el catálogo se
     * ignoran, y los módulos bloqueados se guardan siempre como visibles.
     *
     * @param array<string, array> $modulos   Catálogo (AdminController::MODULES).
     * @param array<int, string>   $visibles  Claves marcadas en el formulario.
     */
    public static function guardar(array $modulos, array $visibles, ?int $usuarioId): bool
    {
        $marcados = array_fill_keys(array_map('strval', $visibles), true);
        $db = ActiveRecord::getDB();

        $stmt = $db->prepare(
            'INSERT INTO configuracion_modulos (clave, visible, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                visible = VALUES(visible),
                updated_by = VALUES(updated_by)'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el guardado de la visibilidad de módulos.');
        }

        // Todo o nada: un guardado a medias dejaría el panel en un estado que
        // nadie eligió.
        $db->begin_transaction();
        try {
            foreach (array_keys($modulos) as $clave) {
                $clave = (string) $clave;
                $visible = (self::esBloqueado($clave) || isset($marcados[$clave])) ? 1 : 0;
                $stmt->bind_param('sii', $clave, $visible, $usuarioId);
                if (!$stmt->execute()) {
                    throw new \RuntimeException('No fue posible guardar la visibilidad del módulo ' . $clave . '.');
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            $stmt->close();
            throw $e;
        }

        $stmt->close();
        self::$ocultos = null;

        return true;
    }
}
