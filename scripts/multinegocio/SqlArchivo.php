<?php
declare(strict_types=1);

namespace Multinegocio;

/** Lector de archivos SQL con DELIMITER; no habilita ejecuciones sobre DB_NAME. */
final class SqlArchivo
{
    public static function ejecutar(\mysqli $db, string $ruta): void
    {
        $base = (string) $db->query('SELECT DATABASE()')->fetch_row()[0];
        if (!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', $base)) {
            throw new \RuntimeException('DDL 3A solo permitido en una base desechable identificada.');
        }
        $marker = $db->query('SELECT token FROM _cp_multinegocio_owner')->fetch_all(MYSQLI_ASSOC);
        if (count($marker) !== 1 || !preg_match('/^[a-f0-9]{64}$/D', (string) $marker[0]['token'])) {
            throw new \RuntimeException('Falta la marca de propiedad del destino desechable.');
        }
        foreach (self::sentencias((string) file_get_contents($ruta)) as $sql) {
            $resultado = $db->query($sql);
            if ($resultado instanceof \mysqli_result) { $resultado->free(); }
        }
    }

    /** @return list<string> */
    public static function sentencias(string $texto): array
    {
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);
        $delimiter = ';';
        $buffer = '';
        $comilla = null;
        $bloque = false;
        $salida = [];
        foreach (preg_split('/\R/', $texto) as $linea) {
            if ($comilla === null && !$bloque && trim($buffer) === ''
                && preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $linea, $m)) {
                $delimiter = $m[1];
                continue;
            }
            $linea .= "\n";
            for ($i = 0, $len = strlen($linea); $i < $len; $i++) {
                $char = $linea[$i];
                $next = $linea[$i + 1] ?? '';
                if ($bloque) {
                    if ($char === '*' && $next === '/') { $bloque = false; $i++; $buffer .= ' '; }
                    continue;
                }
                if ($comilla !== null) {
                    $buffer .= $char;
                    if ($char === '\\' && $comilla !== '`' && $next !== '') { $buffer .= $next; $i++; continue; }
                    if ($char === $comilla) {
                        if ($next === $comilla) { $buffer .= $next; $i++; }
                        else { $comilla = null; }
                    }
                    continue;
                }
                if (($char === '-' && $next === '-' && ctype_space($linea[$i + 2] ?? ' ')) || $char === '#') { break; }
                if ($char === '/' && $next === '*') { $bloque = true; $i++; continue; }
                if (in_array($char, ["'", '"', '`'], true)) { $comilla = $char; $buffer .= $char; continue; }
                if (substr($linea, $i, strlen($delimiter)) === $delimiter) {
                    if (trim($buffer) !== '') { $salida[] = trim($buffer); }
                    $buffer = '';
                    $i += strlen($delimiter) - 1;
                    continue;
                }
                $buffer .= $char;
            }
            $buffer .= "\n";
        }
        if ($comilla !== null || $bloque || trim($buffer) !== '') {
            throw new \RuntimeException('Archivo SQL incompleto, comillas o delimitador sin cerrar.');
        }
        return $salida;
    }
}
