<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__.'/informes-db-bootstrap.php';
pdfTestConfig();
$dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'cp_pdf_suite_'.bin2hex(random_bytes(16));
if (!mkdir($dir,0700)) throw new RuntimeException('No se pudo crear temporal privado');
file_put_contents($dir.'/.pdf-test-owner',bin2hex(random_bytes(32)),LOCK_EX);
putenv('PDF_TEST_TEMP='.$dir);
$fallo=null;
try {
    pdfTestSetup();
    $env=array_merge(getenv(),pdfTestEnv(),['PDF_TEST_TEMP'=>$dir]);
    $tests=['informes-fixture-seed.php','fin-independent.php','ana-independent.php','res-independent.php','inv-independent.php','tic-independent.php','consistencia-independent.php','ia-reportes-independent.php'];
    foreach (['finanzas','analytics','reservaciones','inventario','tickets'] as $mod) $tests[]=['informes-http-independent.php',$mod];
    foreach ($tests as $test) {
        $args=is_array($test)?$test:[$test]; $script=array_shift($args);
        $output=$dir.'/child-output.log';
        $proc=proc_open(array_merge([PHP_BINARY,'-d','variables_order=EGPCS',__DIR__.'/'.$script],$args),[0=>['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','r'],1=>['file',$output,'w'],2=>['file',$output,'a']],$pipes,PDF_TEST_ROOT,$env);
        if (!is_resource($proc)) throw new RuntimeException('No se pudo iniciar prueba: '.$script);
        $code=proc_close($proc); $log=(string)file_get_contents($output); echo $log;
        $markers=[
            'informes-fixture-seed.php'=>'PASS fixture FIN/ANA/TIC y actores sembrados en base propia.',
            'fin-independent.php'=>'FIN independiente PASS',
            'ana-independent.php'=>'ANA independiente PASS',
            'res-independent.php'=>'RES independiente PASS',
            'inv-independent.php'=>'INV independiente PASS',
            'tic-independent.php'=>'TIC independiente PASS',
            'consistencia-independent.php'=>'PASS consistencia independiente: 13 comprobaciones',
            'ia-reportes-independent.php'=>'IA Reportes independiente PASS',
            'informes-http-independent.php'=>strtoupper($args[0] ?? '').' HTTP independiente PASS 21 casos:',
        ];
        if ($code!==0 || preg_match('/\b(?:FAIL|SKIP)\b/i',$log) || !isset($markers[$script]) || !str_contains($log,$markers[$script])) {
            throw new RuntimeException('Falló, se omitió o no terminó la prueba: '.$script);
        }
    }
    echo "PASS aceptación de informes completa\n";
} catch (Throwable $e) { $fallo=$e; }
try { pdfTestCleanup(); } catch (Throwable $e) {
    fwrite(STDERR, 'FAIL limpieza de base; se conserva el directorio temporal de propiedad para recuperación manual: '.$dir.PHP_EOL);
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
}
// Sólo se borran archivos planos en el directorio aleatorio de esta ejecución.
foreach (new DirectoryIterator($dir) as $item) {
    if ($item->isDot()) continue;
    if ($item->isFile() && !$item->isLink()) unlink($item->getPathname());
    else $fallo=new RuntimeException('Contenido temporal inesperado; limpieza detenida');
}
if (!$fallo || count(scandir($dir))===2) rmdir($dir);
if ($fallo) { fwrite(STDERR,'FAIL aceptación de informes: '.$fallo->getMessage().PHP_EOL); exit(1); }
