<?php

namespace Services;

use Model\ActiveRecord;
use Model\ReporteSistema;

/**
 * Reportes de problemas del panel.
 *
 * El módulo existía como maqueta: la tabla estaba en la base, pero ni se
 * escribía al enviar el formulario ni se leía al abrir la pantalla. Aquí se
 * cierra el circuito: crear, listar y mover el estado.
 */
final class ReporteSistemaService
{
    public const OK = 'OK';
    public const DATOS_INVALIDOS = 'DATOS_INVALIDOS';
    public const NO_EXISTE = 'NO_EXISTE';
    public const ERROR_GUARDADO = 'ERROR_GUARDADO';

    /** Filas listas para la vista, con folio y fechas ya formateados. */
    public static function listar(): array
    {
        $filas = [];

        foreach (ReporteSistema::recientes() as $reporte) {
            $navegador = (string) ($reporte->navegador ?? '');
            if ($navegador === 'otro' && trim((string) $reporte->navegador_otro) !== '') {
                $navegador = trim((string) $reporte->navegador_otro);
            }

            $filas[] = [
                'id'          => (int) $reporte->id,
                // Folio legible y estable; el id crudo no dice nada por teléfono.
                'folio'       => 'RS-' . str_pad((string) $reporte->id, 4, '0', STR_PAD_LEFT),
                'titulo'      => (string) $reporte->titulo,
                'descripcion' => (string) $reporte->descripcion,
                'modulo'      => (string) ($reporte->modulo ?? '—'),
                'ruta_origen' => (string) ($reporte->ruta_origen ?? ''),
                'navegador'   => $navegador !== '' ? ucfirst($navegador) : '—',
                'estado'      => (string) $reporte->estado,
                'fecha'       => $reporte->created_at
                    ? date('d/m/Y H:i', strtotime((string) $reporte->created_at))
                    : '',
            ];
        }

        return $filas;
    }

    public static function crear(array $datos, int $usuarioId): array
    {
        $titulo      = trim((string) ($datos['titulo'] ?? ''));
        $descripcion = trim((string) ($datos['descripcion'] ?? ''));
        $modulo      = trim((string) ($datos['modulo'] ?? ''));
        $ruta        = trim((string) ($datos['ruta_origen'] ?? ''));
        $navegador   = trim((string) ($datos['navegador'] ?? ''));
        $navegadorOtro = trim((string) ($datos['navegador_otro'] ?? ''));

        if ($titulo === '' || $descripcion === '') {
            return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS, 'msg' => 'El título y la descripción son obligatorios.'];
        }
        if (!in_array($navegador, ReporteSistema::NAVEGADORES, true)) {
            $navegador = 'otro';
        }

        // Solo rutas internas: el campo lo llena el cliente y luego se ofrece
        // como enlace, así que no debe poder apuntar fuera del sitio.
        if ($ruta !== '' && !preg_match('~^/[^\s]*$~', $ruta)) {
            $ruta = '';
        }

        $db = ActiveRecord::getDB();

        try {
            $stmt = $db->prepare(
                'INSERT INTO reportes_sistema
                     (usuario_id, modulo, titulo, descripcion, ruta_origen, navegador, navegador_otro)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }

            $usuario = $usuarioId > 0 ? $usuarioId : null;
            $modulo  = $modulo !== '' ? mb_substr($modulo, 0, 60) : null;
            $ruta    = $ruta !== '' ? mb_substr($ruta, 0, 255) : null;
            $otro    = $navegador === 'otro' && $navegadorOtro !== ''
                ? mb_substr($navegadorOtro, 0, 80)
                : null;
            $titulo  = mb_substr($titulo, 0, 120);

            $stmt->bind_param('issssss', $usuario, $modulo, $titulo, $descripcion, $ruta, $navegador, $otro);
            if (!$stmt->execute()) {
                throw new \RuntimeException($stmt->error);
            }
            $stmt->close();

            return ['ok' => true, 'codigo' => self::OK];
        } catch (\Throwable $e) {
            error_log('ReporteSistemaService::crear - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_GUARDADO, 'msg' => 'No se pudo registrar el reporte.'];
        }
    }

    /**
     * Mueve un reporte entre estados. `resuelto_at` se sella al resolver y se
     * limpia al reabrir, para que la métrica de tiempo a resolución no arrastre
     * la fecha de un cierre que se deshizo.
     */
    public static function cambiarEstado(int $reporteId, string $estado): array
    {
        if ($reporteId < 1 || !in_array($estado, ReporteSistema::ESTADOS, true)) {
            return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS];
        }

        $db = ActiveRecord::getDB();

        try {
            $stmt = $db->prepare(
                "UPDATE reportes_sistema
                 SET estado = ?,
                     resuelto_at = CASE WHEN ? = 'resuelto' THEN COALESCE(resuelto_at, NOW()) ELSE NULL END
                 WHERE id = ? LIMIT 1"
            );
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }
            $stmt->bind_param('ssi', $estado, $estado, $reporteId);
            if (!$stmt->execute()) {
                throw new \RuntimeException($stmt->error);
            }
            $stmt->close();

            return ['ok' => true, 'codigo' => self::OK];
        } catch (\Throwable $e) {
            error_log('ReporteSistemaService::cambiarEstado - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_GUARDADO];
        }
    }
}
