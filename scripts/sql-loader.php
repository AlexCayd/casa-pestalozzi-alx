<?php
declare(strict_types=1);

/** Ejecuta un archivo SQL delimitado por líneas, respetando DELIMITER y comentarios. */
function executeSqlFile(mysqli $db, string $sql): void
{
    $delimiter = ';';
    $statement = '';
    foreach (preg_split('/\R/', $sql) as $line) {
        if (preg_match('/^\s*DELIMITER\s+(\S+)/i', $line, $match)) {
            $delimiter = $match[1];
            continue;
        }
        if (preg_match('/^\s*--/', $line) || trim($line) === '') continue;
        $line = preg_replace('/;\s*--.*$/', ';', $line);
        $statement .= $line . "\n";
        if (str_ends_with(rtrim($statement), $delimiter)) {
            $db->query(substr(rtrim($statement), 0, -strlen($delimiter)));
            $statement = '';
        }
    }
    if (trim($statement) !== '') throw new RuntimeException('SQL incompleto.');
}
