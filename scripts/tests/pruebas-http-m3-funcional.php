<?php
declare(strict_types=1);
/**
 * Plan funcional M3 (pruebas-almacenes.md): recetas, subrecetas y Almacenes por HTTP real
 * contra el harness 3E (servir-instalacion-3e.php). Todo cambio de datos pasa por la
 * interfaz o la API; la base temporal sólo se consulta con SELECT para verificar.
 *
 * Uso: php scripts/tests/pruebas-http-m3-funcional.php --fixture ABS_JSON_PRIVADO [--resultados ABS_JSON]
 *
 * Cada caso queda como PASS, FAIL u OBS con su evidencia; un FAIL no detiene los
 * demás. El archivo de fixture contiene credenciales efímeras: nunca se imprimen.
 */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/cliente-http-3e.php';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root . '/includes')->safeLoad();

// ── Argumentos y fixture ────────────────────────────────────────────────
$op = getopt('', ['fixture:', 'resultados:', 'solo:']);
if (!isset($op['fixture']) || !is_file($op['fixture'])) { fwrite(STDERR, "Uso: --fixture ABS_JSON_PRIVADO [--resultados ABS_JSON] [--solo 1,2]\n"); exit(2); }
$fx = json_decode((string)file_get_contents($op['fixture']), true, 512, JSON_THROW_ON_ERROR);
if (($fx['url'] ?? '') !== 'http://127.0.0.1:8094/' || !preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', (string)($fx['base_temporal'] ?? ''))) { fwrite(STDERR, "El fixture no identifica un harness 3E aislado.\n"); exit(2); }
$solo = isset($op['solo']) ? array_map('intval', explode(',', (string)$op['solo'])) : [1, 2, 3, 4, 5, 6];
$cuentas = $fx['cuentas'];
// La contraseña de admin_demo es el secreto local de .env que usó el harness; no se imprime.
$cuentas['admin_demo'] = ['username' => 'admin_demo', 'password' => (string)($_ENV['DEV_SUPERADMIN_PASSWORD'] ?? '')];

// ── Lectura SQL: sólo SELECT sobre la base temporal ─────────────────────
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli((string)$_ENV['DB_HOST'], (string)$_ENV['DB_USER'], (string)$_ENV['DB_PASS'], (string)$fx['base_temporal'], (int)($_ENV['DB_PORT'] ?? 3306));
$db->set_charset('utf8mb4');
$db->query('SET SESSION TRANSACTION READ ONLY');
$q = static function (string $sql, array $p = []) use ($db): array {
    if (!preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) throw new RuntimeException('Sólo se permiten consultas de lectura.');
    return $db->execute_query($sql, $p)->fetch_all(MYSQLI_ASSOC);
};
$v = static function (string $sql, array $p = []) use ($q) { $r = $q($sql, $p); return $r ? array_values($r[0])[0] : null; };

// ── Registro de casos ───────────────────────────────────────────────────
$casos = [];
$caso = static function (string $id, string $titulo, string $estado, string $evidencia = '') use (&$casos): void {
    $casos[] = ['caso' => $id, 'titulo' => $titulo, 'estado' => $estado, 'evidencia' => $evidencia];
    fwrite(STDOUT, sprintf("[%s] %s · %s%s\n", $estado, $id, $titulo, $evidencia !== '' ? ' — ' . $evidencia : ''));
};
// Aserción: PASS si se cumple, FAIL si no. Nunca detiene la ejecución.
$ok = static function (string $id, string $titulo, bool $cond, string $evidencia = '') use ($caso): bool { $caso($id, $titulo, $cond ? 'PASS' : 'FAIL', $evidencia); return $cond; };
$obs = static function (string $id, string $titulo, string $evidencia) use ($caso): void { $caso($id, $titulo, 'OBS', $evidencia); };
$seccion = static function (string $nombre, callable $f) use ($caso): void {
    fwrite(STDOUT, "\n== $nombre\n");
    try { $f(); } catch (Throwable $e) { $caso('ERR', 'Excepción en ' . $nombre, 'FAIL', mb_substr($e->getMessage(), 0, 300) . ' @' . basename($e->getFile()) . ':' . $e->getLine()); }
};

// Integridad del plan §1.4: cada saldo es la suma exacta de sus movimientos.
$integridad = static function (string $donde) use ($q, $ok): void {
    $filas = $q('SELECT aa.almacen_id, aa.articulo_id, aa.saldo, COALESCE(SUM(m.delta),0) AS ledger FROM almacen_articulos aa LEFT JOIN movimientos_inventario m ON m.almacen_id=aa.almacen_id AND m.articulo_id=aa.articulo_id GROUP BY aa.almacen_id, aa.articulo_id, aa.saldo HAVING aa.saldo <> COALESCE(SUM(m.delta),0)');
    $ok('INT', 'Integridad saldo = movimientos al cierre de ' . $donde, $filas === [], $filas ? json_encode(array_slice($filas, 0, 5)) : 'sin filas');
};

// ── Formularios: lee todos los campos de un form tal como los enviaría el navegador ──
$campos = static function (string $body, string $xpathForm): array {
    $x = ClienteInstalacion3E::dom($body);
    $form = $x->query($xpathForm)->item(0);
    if (!$form) throw new RuntimeException('No se encontró el formulario ' . $xpathForm);
    $d = [];
    foreach ($x->query('.//input[@name and not(@disabled)]', $form) as $i) {
        $t = strtolower($i->getAttribute('type'));
        if (in_array($t, ['checkbox', 'radio'], true) && !$i->hasAttribute('checked')) continue;
        if (in_array($t, ['submit', 'button', 'file'], true)) continue;
        $d[] = [$i->getAttribute('name'), $i->getAttribute('value')];
    }
    foreach ($x->query('.//textarea[@name and not(@disabled)]', $form) as $t) $d[] = [$t->getAttribute('name'), $t->textContent];
    foreach ($x->query('.//select[@name and not(@disabled)]', $form) as $s) {
        $sel = $x->query('.//option[@selected]', $s)->item(0) ?? $x->query('.//option', $s)->item(0);
        // Como el navegador: sin atributo value, la opción envía su texto.
        $d[] = [$s->getAttribute('name'), $sel ? ($sel->hasAttribute('value') ? $sel->getAttribute('value') : trim($sel->textContent)) : ''];
    }
    return $d;
};
// Convierte pares [nombre, valor] a un cuerpo urlencoded, sobrescribiendo por nombre.
$cuerpo = static function (array $pares, array $sobre = []): string {
    $quitar = array_keys(array_filter($sobre, static fn($x) => $x === null));
    $sobre = array_filter($sobre, static fn($x) => $x !== null);
    $partes = [];
    foreach ($pares as [$n, $val]) { if (array_key_exists($n, $sobre) || in_array($n, $quitar, true)) continue; $partes[] = rawurlencode($n) . '=' . rawurlencode((string)$val); }
    $plano = [];
    $aplanar = static function ($pref, $val) use (&$aplanar, &$plano) { if (is_array($val)) { foreach ($val as $k => $w) $aplanar($pref === '' ? (string)$k : $pref . '[' . $k . ']', $w); } else $plano[] = rawurlencode($pref) . '=' . rawurlencode((string)$val); };
    foreach ($sobre as $n => $val) $aplanar($n, $val);
    return implode('&', array_merge($partes, $plano));
};

// Cliente con soporte de cuerpo crudo (urlencoded) y CSRF del POS.
final class ClienteM3
{
    public ClienteInstalacion3E $c; public string $posCsrf = '';
    private CurlHandle $raw; private string $jar;
    public function __construct() { $this->c = new ClienteInstalacion3E(); }
    public function get(string $p): array { return $this->c->request('GET', $p); }
    public function post(string $p, array $d): array { return $this->c->request('POST', $p, $d); }
    /** POST con cuerpo ya codificado: respeta nombres repetidos como comp[]. */
    public function postCrudo(string $p, string $cuerpo): array
    {
        $ref = new ReflectionProperty(ClienteInstalacion3E::class, 'curl');
        $curl = $ref->getValue($this->c);
        curl_setopt_array($curl, [CURLOPT_URL => 'http://127.0.0.1:8094' . $p, CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => $cuerpo, CURLOPT_HTTPHEADER => ['Accept: text/html,application/json', 'Content-Type: application/x-www-form-urlencoded']]);
        $raw = curl_exec($curl); $size = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE); $head = substr((string)$raw, 0, $size); $body = substr((string)$raw, $size);
        preg_match('/^Location:\s*([^\r\n]+)/mi', $head, $loc);
        return ['status' => (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $body, 'location' => trim($loc[1] ?? ''), 'json' => json_decode($body, true)];
    }
    /** Sigue redirecciones GET (máx. 4). */
    public function seguir(array $r): array { for ($i = 0; $i < 4 && in_array($r['status'], [301, 302, 303], true) && $r['location'] !== ''; $i++) $r = $this->get($r['location']); return $r; }
    public function pos(string $p, array $d): array { return $this->c->request('POST', $p, $d + ['csrf_token' => $this->posCsrf]); }
    public function abrirPos(): void { $r = $this->get('/punto-de-venta'); if (!preg_match('/data-staff-csrf="([^"]+)"/', $r['body'], $m)) throw new RuntimeException('POS sin CSRF de personal (HTTP ' . $r['status'] . ').'); $this->posCsrf = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
    public function close(): void { $this->c->close(); }
}

$clientes = [];
$sesion = static function (string $cuenta, bool $nip = false) use (&$clientes, $cuentas): ClienteM3 {
    $c = new ClienteM3(); $clientes[] = $c;
    $r = $c->c->login($cuentas[$cuenta], $nip);
    if (!in_array($r['status'], [302, 303], true)) throw new RuntimeException('No inició sesión ' . $cuenta . ' (HTTP ' . $r['status'] . ').');
    return $c;
};
$texto = static fn(string $html): string => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
$alertas = static function (string $html) use ($texto): string {
    $x = ClienteInstalacion3E::dom($html); $out = [];
    foreach ($x->query('//*[contains(@class,"alerta") or contains(@class,"admin-alert") or contains(@class,"admin-menu__flash") or contains(@class,"admin-menu__alert") or contains(@class,"admin-notice") or @role="alert"]') as $n) $out[] = $texto($n->textContent);
    return implode(' | ', array_unique(array_filter($out)));
};
$saldo = static fn(int $alm, int $art) => (string)($v('SELECT saldo FROM almacen_articulos WHERE almacen_id=? AND articulo_id=?', [$alm, $art]) ?? 'sin-asociación');
$idArticulo = static fn(string $nombre) => (int)($v('SELECT id FROM ingredientes WHERE nombre=?', [$nombre]) ?? 0);
$idOferta = static fn(string $nombre) => (int)($v('SELECT o.id FROM ofertas_producto o JOIN productos_oferta p ON p.oferta_id=o.id WHERE p.nombre=? AND o.unidad_id=2 ORDER BY o.id DESC LIMIT 1', [$nombre]) ?? 0);
$movs = static fn(int $alm, int $art) => (int)$v('SELECT COUNT(*) FROM movimientos_inventario WHERE almacen_id=? AND articulo_id=?', [$alm, $art]);

const CENTRAL = 1001, COCINA = 1002, BARRA = 1005, REFRI = 1006, PRODUCCION = 1003;

$sesiones = [];
$seccion('Sesiones', function () use (&$sesiones, $sesion, $ok) {
    foreach (['administrador_global', 'operador_global', 'operador_restaurante', 'lectura_restaurante', 'operador_panaderia'] as $c) $sesiones[$c] = $sesion($c);
    $sesiones['piso'] = $sesion('piso_nip', true);
    // admin_demo (superadministrador del harness) sólo para leer el buzón completo (AP-1).
    $GLOBALS['__admin'] = $sesion('admin_demo');
    $ok('0.1', 'Sesiones de panel (contraseña + 2FA) y de piso (NIP)', true, count($sesiones) . ' sesiones');
});
$G = $sesiones['administrador_global'] ?? null; $GO = $sesiones['operador_global'] ?? null; $R = $sesiones['operador_restaurante'] ?? null; $L = $sesiones['lectura_restaurante'] ?? null; $P = $sesiones['operador_panaderia'] ?? null; $PISO = $sesiones['piso'] ?? null;
if (!$G || !$R || !$PISO) { fwrite(STDERR, "Sin sesiones no hay recorrido.\n"); exit(1); }
$integridad('la instalación');
$faltanteMigracion = (int)$v("SELECT COUNT(*) FROM faltantes_inventario WHERE almacen_id=1002 AND estado='pendiente' AND origen='migracion'");
$ok('0.2', 'Línea base: faltante de migración pendiente en Cocina', $faltanteMigracion === 1, "$faltanteMigracion pendiente(s)");

// ── Operaciones de alto nivel ──────────────────────────────────────────
$baseAlm = static fn(string $ctx) => $ctx === 'restaurante' ? '/admin/almacenes' : '/admin/' . $ctx . '/almacenes';
$baseArt = static fn(string $ctx) => $ctx === 'restaurante' ? '/admin/recetas/articulos' : '/admin/' . $ctx . '/recetas/articulos';
/** Operación de Almacenes con el CSRF de la página; devuelve [respuesta POST, página final, alertas]. */
$operar = static function (ClienteM3 $c, string $ctx, string $accion, array $valores) use ($baseAlm, $alertas): array {
    $base = $baseAlm($ctx);
    $pag = $c->get($base);
    $tok = ClienteInstalacion3E::token($pag['body']);
    $post = $c->post($base . '/operacion', ['admin_csrf' => $tok, 'accion' => $accion, 'volver_vista' => 'existencias'] + $valores);
    $fin = $c->seguir($post);
    return [$post, $fin, $alertas($fin['body'])];
};
// Una transferencia nueva avisa su estado (realizada, enviada o solicitada) en vez de «confirmada correctamente».
$exito = static fn(array $o): bool => $o[0]['status'] === 303 && (str_contains($o[2], 'confirmada correctamente') || preg_match('/Transferencia #\d+ (realizada|enviada|solicitada)/u', $o[2]) === 1);
$crearArticulo = static function (ClienteM3 $c, array $datos) use ($campos, $cuerpo): array {
    $pag = $c->get('/admin/global/almacenes/catalogo/nuevo');
    if ($pag['status'] !== 200) return ['status' => $pag['status'], 'body' => $pag['body'], 'location' => ''];
    $pares = $campos($pag['body'], '//form[.//input[@name="m2_csrf"] and .//input[@name="nombre"]]');
    return $c->postCrudo('/admin/global/almacenes/catalogo/nuevo', $cuerpo($pares, $datos));
};
/** Capacidades M2 del artículo en un ámbito (accion=configurar). */
$capacidades = static function (ClienteM3 $c, string $ctx, int $id, array $activas) use ($baseArt, $campos, $cuerpo, $alertas): array {
    $url = $baseArt($ctx) . '/editar?id=' . $id;
    $pag = $c->get($url);
    $pares = $campos($pag['body'], '//form[.//input[@name="accion" and @value="configurar"]]');
    $sobre = ['accion' => 'configurar'];
    foreach (['comprable', 'componente', 'elaborable', 'almacenable', 'produccion_previa', 'costo_conocido', 'activo'] as $k) $sobre[$k] = in_array($k, $activas, true) ? '1' : null;
    $r = $c->postCrudo($url, $cuerpo($pares, $sobre));
    return [$r, $alertas($c->seguir($r)['body'])];
};
$conf = static fn(int $art, int $unidad) => $GLOBALS['__q']('SELECT * FROM articulo_configuraciones WHERE articulo_id=? AND unidad_id=?', [$art, $unidad])[0] ?? null;
$GLOBALS['__q'] = $q;

$datosQA = [];

// ════════════════════════════════════════════════════════════════════════
// Prueba 1 — Alta de productos en las tres categorías
// ════════════════════════════════════════════════════════════════════════
if (in_array(1, $solo, true)) $seccion('Prueba 1 · Alta y datos de prueba', function () use (&$datosQA, $G, $GO, $R, $q, $v, $ok, $obs, $crearArticulo, $capacidades, $operar, $exito, $alertas, $saldo, $idArticulo, $movs, $texto, $baseAlm, $conf) {
    // 1.1 Catálogo
    $r = $crearArticulo($G, ['nombre' => 'QA M3 Vino tinto', 'unidad' => 'ml', 'contenido_pieza' => '750', 'costo' => '0.4000', 'activo' => '1']);
    $vino = $idArticulo('QA M3 Vino tinto');
    $ok('1.1a', 'Global crea «QA M3 Vino tinto» en el catálogo', $r['status'] === 303 && $vino > 0, 'HTTP ' . $r['status'] . ' · id ' . $vino);
    $asoc = (int)$v('SELECT COUNT(*) FROM almacen_articulos WHERE articulo_id=?', [$vino]);
    $mov = (int)$v('SELECT COUNT(*) FROM movimientos_inventario WHERE articulo_id=?', [$vino]);
    $ok('1.1b', 'El alta no crea asociaciones ni saldo', $asoc === 0 && $mov === 0, "asociaciones $asoc · movimientos $mov");
    $nr = $R->get('/admin/almacenes/catalogo/nuevo');
    $ok('1.1c', 'Restaurante no abre el alta del catálogo', $nr['status'] === 403, 'HTTP ' . $nr['status']);
    $dup = $crearArticulo($G, ['nombre' => 'QA M3 Vino tinto', 'unidad' => 'ml', 'contenido_pieza' => '', 'costo' => '0.4000', 'activo' => '1']);
    $ok('1.1d', 'Nombre duplicado → ARTICULO_DUPLICADO', $dup['status'] === 422 && str_contains($dup['body'], 'El artículo ya existe'), 'HTTP ' . $dup['status'] . ' · ' . mb_substr($alertas($dup['body']), 0, 120));
    $pz = $crearArticulo($G, ['nombre' => 'QA M3 Pieza con contenido', 'unidad' => 'pza', 'contenido_pieza' => '6', 'costo' => '1.0000', 'activo' => '1']);
    $ok('1.1e', 'Contenido por pieza en un artículo pza → CONTENIDO_PIEZA_INVALIDO', $pz['status'] === 422 && str_contains($pz['body'], 'no declara contenido por pieza'), 'HTTP ' . $pz['status'] . ' · ' . mb_substr($alertas($pz['body']), 0, 120));

    foreach ([['QA M3 Fresa', 'pza', '', '2.0000'], ['QA M3 Pasta seca', 'g', '', '0.0300'], ['QA M3 Salsa al vino', 'g', '', '0.0000']] as [$n, $u, $c, $costo]) {
        $crearArticulo($G, ['nombre' => $n, 'unidad' => $u, 'contenido_pieza' => $c, 'costo' => $costo, 'activo' => '1']);
    }
    $fresa = $idArticulo('QA M3 Fresa'); $pasta = $idArticulo('QA M3 Pasta seca'); $salsa = $idArticulo('QA M3 Salsa al vino'); $tomate = 1013;
    $ok('1.1f', 'Fresa, pasta y salsa creadas en el catálogo', $fresa && $pasta && $salsa, "fresa $fresa · pasta $pasta · salsa $salsa");
    $datosQA += compact('vino', 'fresa', 'pasta', 'salsa', 'tomate');

    // 1.2 Capacidades y asociación
    [$rc, $al] = $capacidades($R, 'restaurante', $vino, ['componente', 'almacenable', 'comprable', 'activo']);
    $c2 = $conf($vino, 2);
    $ok('1.2a', 'Restaurante activa «Se usa como ingrediente» y «Mantiene existencias» del vino', $rc['status'] === 303 && (int)($c2['componente'] ?? 0) === 1 && (int)($c2['almacenable'] ?? 0) === 1, 'HTTP ' . $rc['status'] . ' ' . mb_substr($al, 0, 80));
    foreach ([$fresa, $pasta, $tomate] as $a) $capacidades($R, 'restaurante', $a, ['componente', 'almacenable', 'comprable', 'activo']);
    $capacidades($R, 'restaurante', $salsa, ['componente', 'elaborable', 'almacenable', 'activo']);
    foreach ([$vino, $fresa] as $a) $capacidades($G, 'global', $a, ['componente', 'almacenable', 'comprable', 'activo']);
    $as = $operar($R, 'restaurante', 'asociar', ['almacen_id' => BARRA, 'articulo_id' => $vino, 'minimo' => '1500']);
    $ok('1.2b', 'Vino asociado a Barra con mínimo 1500 ml', $exito($as) && $saldo(BARRA, $vino) === '0.000', $as[2]);
    $asociaciones = [['restaurante', COCINA, $vino], ['restaurante', BARRA, $fresa], ['restaurante', REFRI, $fresa], ['restaurante', COCINA, $pasta], ['restaurante', COCINA, $tomate], ['restaurante', COCINA, $salsa], ['global', CENTRAL, $vino], ['global', CENTRAL, $fresa]];
    $fallas = [];
    foreach ($asociaciones as [$ctx, $alm, $art]) { $o = $operar($ctx === 'global' ? $GO : $R, $ctx, 'asociar', ['almacen_id' => $alm, 'articulo_id' => $art, 'minimo' => '0']); if (!$exito($o)) $fallas[] = "$ctx $alm/$art: " . $o[2]; }
    $ok('1.2c', 'Asociaciones de los datos de prueba (Cocina, Barra, Refrigeración y Central)', $fallas === [], $fallas ? implode('; ', $fallas) : count($asociaciones) . ' asociaciones');
    $antes = $movs(BARRA, $vino);
    $en = $operar($R, 'restaurante', 'entrada', ['almacen_id' => BARRA, 'articulo_id' => $vino, 'piezas' => '6', 'cantidad' => '', 'clave' => 'qa-m3-vino-barra']);
    $pagina = $R->get('/admin/almacenes?q=' . rawurlencode('QA M3 Vino'));
    $ok('1.2d', 'Entrada de 6 piezas: 4500.000 ml, lectura «6 piezas», un movimiento', $exito($en) && $saldo(BARRA, $vino) === '4500.000' && $movs(BARRA, $vino) === $antes + 1 && str_contains($texto($pagina['body']), '6 piezas'), 'saldo ' . $saldo(BARRA, $vino) . ' · movimientos +' . ($movs(BARRA, $vino) - $antes));

    // Saldos iniciales del plan §1.5
    $entradas = [['restaurante', BARRA, $fresa, '20', null], ['restaurante', REFRI, $fresa, '40', null], ['global', CENTRAL, $vino, null, '4'], ['global', CENTRAL, $fresa, '30', null], ['restaurante', COCINA, $pasta, '5000', null], ['restaurante', COCINA, $tomate, '5000', null], ['restaurante', COCINA, $vino, null, '2']];
    $fallas = [];
    foreach ($entradas as $n => [$ctx, $alm, $art, $cant, $pzs]) {
        $o = $operar($ctx === 'global' ? $GO : $R, $ctx, 'entrada', ['almacen_id' => $alm, 'articulo_id' => $art, 'cantidad' => $cant ?? '', 'piezas' => $pzs ?? '', 'clave' => 'qa-m3-saldo-' . $n]);
        if (!$exito($o)) $fallas[] = "$alm/$art: " . $o[2];
    }
    $esperado = [[BARRA, $fresa, '20.000'], [REFRI, $fresa, '40.000'], [CENTRAL, $vino, '3000.000'], [CENTRAL, $fresa, '30.000'], [COCINA, $pasta, '5000.000'], [COCINA, $vino, '1500.000']];
    $mal = array_filter($esperado, static fn($e) => $saldo($e[0], $e[1]) !== $e[2]);
    $ok('1.2e', 'Saldos iniciales del plan registrados por Entrada', $fallas === [] && $mal === [], $fallas ? implode('; ', $fallas) : ($mal ? json_encode(array_values($mal)) : 'tomate Cocina ' . $saldo(COCINA, $tomate)));
});

// Los datos QA se recuperan por nombre: permite ejecutar secciones por separado (--solo).
$datosQA = array_merge($datosQA, array_filter(['vino' => $idArticulo('QA M3 Vino tinto'), 'fresa' => $idArticulo('QA M3 Fresa'), 'pasta' => $idArticulo('QA M3 Pasta seca'), 'salsa' => $idArticulo('QA M3 Salsa al vino'), 'tomate' => 1013]));

// POST JSON con la sesión del cliente (editor de variantes).
$GLOBALS['__json'] = static function (ClienteM3 $c, string $p, array $d): array { $ref = new ReflectionProperty(ClienteInstalacion3E::class, 'curl'); $curl = $ref->getValue($c->c); curl_setopt_array($curl, [CURLOPT_URL => 'http://127.0.0.1:8094' . $p, CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => json_encode($d), CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json']]); $raw = (string)curl_exec($curl); $sz = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE); return ['status' => (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'json' => json_decode(substr($raw, $sz), true)]; };

// ── Menú, presentaciones, orígenes y elaboración ───────────────────────
$opcion = static function (string $body, string $nombre): string {
    $x = ClienteInstalacion3E::dom($body);
    foreach ($x->query('//select[@name="' . $nombre . '"]//option') as $o) if ($o->getAttribute('value') !== '') return $o->getAttribute('value');
    foreach ($x->query('//input[@type="radio" and @name="' . $nombre . '"]') as $o) if ($o->getAttribute('value') !== '') return $o->getAttribute('value');
    return '';
};
/** Alta de producto en /admin/menu/create; $tipo null omite el tipo de venta. */
$crearOferta = static function (ClienteM3 $c, string $nombre, ?string $tipo, string $precio = '120.00') use ($campos, $cuerpo, $opcion, $idOferta, $alertas): array {
    $pag = $c->get('/admin/menu/create');
    $pares = $campos($pag['body'], '//form[.//input[@name="menu_csrf"]]');
    $r = $c->postCrudo('/admin/menu/create', $cuerpo($pares, ['nombre' => $nombre, 'descripcion' => 'Dato de prueba M3', 'precio' => $precio, 'categoria_id' => $opcion($pag['body'], 'categoria_id'), 'area_id' => $opcion($pag['body'], 'area_id'), 'activo' => '1', 'orden' => '0', 'tipo_venta' => $tipo]));
    return [$r, $idOferta($nombre), $alertas($r['body'])];
};
/** Publica una versión de presentación M2 (accion=publicar con confirmación). */
$presentar = static function (ClienteM3 $c, int $oferta, string $clave, string $nombre, string $precio, array $comps, ?int $presentacion = null, string $activo = '1') use ($campos, $cuerpo, $alertas): array {
    $url = '/admin/menu/presentaciones?id=' . $oferta . ($presentacion ? '&presentacion=' . $presentacion : '');
    $pag = $c->get($url);
    $pares = array_values(array_filter($campos($pag['body'], '//form[.//input[@name="presentacion_clave"]]'), static fn($p) => !str_starts_with($p[0], 'componentes[')));
    $filas = [];
    foreach ($comps as $i => $k) $filas[$i] = ['articulo_id' => $k[0], 'cantidad' => $k[1], 'unidad' => $k[2], 'modalidad' => $k[3], 'etapa' => 'servicio'] + (isset($k[4]) ? ['version_receta_id' => $k[4]] : []);
    $r = $c->postCrudo($url, $cuerpo($pares, ['presentacion_clave' => $clave, 'nombre' => $nombre, 'precio' => $precio, 'activo' => $activo === '1' ? '1' : null, 'accion' => 'publicar', 'confirmar' => '1', 'componentes' => $filas]));
    $id = preg_match('/[?&]presentacion=(\d+)/', $r['location'], $m) ? (int)$m[1] : 0;
    return [$r, $id, $r['status'] === 303 ? '' : $alertas($r['body'])];
};
/** Orígenes de consumo de la oferta (accion=origenes): [articulo_id => almacen_id]. */
$origenes = static function (ClienteM3 $c, int $oferta, array $mapa) use ($campos, $cuerpo, $alertas): array {
    $url = '/admin/menu/presentaciones?id=' . $oferta;
    $pag = $c->get($url);
    if (!ClienteInstalacion3E::dom($pag['body'])->query('//form[.//input[@name="accion" and @value="origenes"]]')->length) return [['status' => 0, 'body' => '', 'location' => ''], 'la página no ofrece orígenes (HTTP ' . $pag['status'] . ')'];
    $pares = $campos($pag['body'], '//form[.//input[@name="accion" and @value="origenes"]]');
    $sobre = []; foreach ($mapa as $art => $alm) $sobre['origenes[' . $art . ']'] = (string)$alm;
    $r = $c->postCrudo($url, $cuerpo($pares, $sobre));
    return [$r, $r['status'] === 303 ? '' : $alertas($r['body'])];
};
/** Elaboración propia M2 de un artículo: prever (huella) y publicar. */
$elaborar = static function (ClienteM3 $c, string $ctx, int $art, string $rend, string $unidad, array $comps) use ($baseArt, $campos, $cuerpo, $alertas): array {
    $url = $baseArt($ctx) . '/editar?id=' . $art;
    $filas = [];
    foreach ($comps as $i => $k) $filas[$i] = ['articulo_id' => $k[0], 'cantidad' => $k[1], 'unidad' => $k[2], 'modalidad' => $k[3] ?? 'almacenado'];
    $pag = $c->get($url);
    $pares = array_values(array_filter($campos($pag['body'], '//form[.//input[@name="rendimiento"]]'), static fn($p) => !str_starts_with($p[0], 'componentes[')));
    $prev = $c->postCrudo($url, $cuerpo($pares, ['rendimiento' => $rend, 'unidad' => $unidad, 'procedencia' => 'Elaboración propia', 'accion' => 'prever', 'componentes' => $filas]));
    $pares2 = array_values(array_filter($campos($prev['body'], '//form[.//input[@name="rendimiento"]]'), static fn($p) => !str_starts_with($p[0], 'componentes[')));
    $r = $c->postCrudo($url, $cuerpo($pares2, ['rendimiento' => $rend, 'unidad' => $unidad, 'procedencia' => 'Elaboración propia', 'accion' => 'publicar', 'confirmar' => '1', 'componentes' => $filas]));
    return [$r, $r['status'] === 303 ? '' : ($alertas($r['body']) ?: $alertas($prev['body']))];
};
/**
 * Flujo de la interfaz: con «Habilitar» la versión exige orígenes, y los orígenes sólo
 * se ofrecen para artículos de versiones ya publicadas. Por eso se publica
 * deshabilitada, se eligen orígenes y se publica de nuevo habilitada.
 */
$presentarCompleta = static function (ClienteM3 $c, int $oferta, string $clave, string $nombre, string $precio, array $comps, array $mapa) use ($presentar, $origenes): array {
    [$r1, $pid, $a1] = $presentar($c, $oferta, $clave, $nombre, $precio, $comps, null, '0');
    if (!$pid) return [0, 'publicación deshabilitada: ' . $a1];
    [$ro, $ao] = $origenes($c, $oferta, $mapa);
    [$r2, $pid2, $a2] = $presentar($c, $oferta, $clave, $nombre, $precio, $comps, $pid, '1');
    return [$pid2 ?: 0, trim("orígenes HTTP {$ro['status']} $ao · habilitar HTTP {$r2['status']} $a2")];
};
$version = static fn(int $art) => (int)($v('SELECT id FROM articulo_recetas WHERE articulo_id=? AND unidad_id=2 AND publicada=1 ORDER BY version DESC LIMIT 1', [$art]) ?? 0);
$presentacionDe = static fn(int $oferta, string $clave) => (int)($v('SELECT id FROM oferta_presentaciones WHERE oferta_id=? AND clave=?', [$oferta, $clave]) ?? 0);

if (array_intersect([1, 11], $solo)) $seccion('Prueba 1 · Ofertas, reventa e ingrediente', function () use (&$datosQA, $G, $R, $q, $v, $ok, $obs, $crearOferta, $presentar, $presentarCompleta, $origenes, $elaborar, $version, $alertas, $texto, $baseArt, $cuerpo, $campos) {
    ['vino' => $vino, 'fresa' => $fresa, 'pasta' => $pasta, 'salsa' => $salsa, 'tomate' => $tomate] = $datosQA;

    // 1.3 Preparado (copa): sin tipo no se guarda; con tipo queda confirmado.
    [$r0, $id0, $al0] = $crearOferta($R, 'QA M3 Copa sin tipo', null);
    $ok('1.3a', 'Sin tipo de venta el producto no se guarda', $id0 === 0, 'HTTP ' . $r0['status'] . ' · ' . mb_substr($al0, 0, 140));
    [$r, $copa, $al] = $crearOferta($R, 'QA M3 Copa de vino tinto', 'preparado', '95.00');
    $t = $q('SELECT tipo_venta,tipo_venta_estado FROM ofertas_producto WHERE id=?', [$copa])[0] ?? [];
    $ok('1.3b', 'La copa queda preparado/confirmado', $copa > 0 && ($t['tipo_venta'] ?? '') === 'preparado' && ($t['tipo_venta_estado'] ?? '') === 'confirmado', json_encode($t));
    [$r1c, $p1c, $a1c] = $presentar($R, $copa, 'copa', 'Copa', '95.00', [[$vino, '150', 'ml', 'almacenado']]);
    $obs('1.3d', 'La primera versión habilitada exige orígenes que la página sólo ofrece después de publicar', 'HTTP ' . $r1c['status'] . ' · ' . $a1c . ' Se publica deshabilitada, se eligen orígenes y se habilita.');
    [$pCopa, $evc] = $presentarCompleta($R, $copa, 'copa', 'Copa', '95.00', [[$vino, '150', 'ml', 'almacenado']], [$vino => BARRA]);
    $ok('1.3c', 'Presentación «Copa» (vino 150 ml almacenado) con origen Barra', $pCopa > 0 && (int)$v('SELECT almacen_id FROM oferta_consumos WHERE oferta_id=? AND articulo_id=?', [$copa, $vino]) === BARRA && (int)$v('SELECT activo FROM oferta_presentaciones WHERE id=?', [$pCopa]) === 1, "presentación $pCopa · $evc");

    // 1.4 Reventa (botella)
    [$r, $botella] = $crearOferta($R, 'QA M3 Botella de vino tinto', 'reventa', '420.00');
    $t = $q('SELECT tipo_venta,tipo_venta_estado FROM ofertas_producto WHERE id=?', [$botella])[0] ?? [];
    $ok('1.4a', 'La botella queda reventa/confirmado', $botella > 0 && ($t['tipo_venta'] ?? '') === 'reventa' && ($t['tipo_venta_estado'] ?? '') === 'confirmado', json_encode($t));
    $pagB = $R->get('/admin/menu/presentaciones?id=' . $botella);
    $editorReventa = (int)ClienteInstalacion3E::dom($pagB['body'])->query('//input[@type="hidden" and @name="componentes[0][modalidad]" and @value="almacenado"]')->length;
    $filasArticulo = (int)ClienteInstalacion3E::dom($pagB['body'])->query('//*[@name="componentes[1][articulo_id]"]')->length;
    $ok('1.4b', 'El editor de reventa pide un solo artículo y su cantidad por unidad vendida', $editorReventa === 1 && $filasArticulo === 0, "modalidad fija $editorReventa · segunda fila $filasArticulo");
    [$rd, $pd, $ald] = $presentar($R, $botella, 'doble', 'Botella con dos', '420.00', [[$vino, '750', 'ml', 'almacenado'], [$fresa, '1', 'pza', 'almacenado']]);
    $ok('1.4c', 'Dos componentes en reventa → REVENTA_COMPOSICION', $pd === 0 && str_contains($ald, 'un solo artículo'), 'HTTP ' . $rd['status'] . ' · ' . mb_substr($ald, 0, 140));
    [$pBot, $alb] = $presentarCompleta($R, $botella, 'botella', 'Botella', '420.00', [[$vino, '750', 'ml', 'almacenado']], [$vino => BARRA]);
    $ok('1.4d', 'Botella publicada: vino 750 ml por unidad, origen Barra', $pBot > 0 && (int)$v('SELECT almacen_id FROM oferta_consumos WHERE oferta_id=? AND articulo_id=?', [$botella, $vino]) === BARRA, "presentación $pBot · $alb");
    // Variantes: la que agrega un artículo se rechaza; la de sólo precio se guarda.
    $pv = $R->get('/admin/menu/variantes?id=' . $botella);
    $csrfV = preg_match('/data-admin-csrf="([^"]+)"/', $pv['body'], $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5) : '';
    $grupo = static fn(array $aj) => [['nombre' => 'Servicio', 'opciones' => [['id' => 0, 'nombre' => 'Normal', 'precio_delta' => '0.00', 'predeterminada' => true, 'activo' => true, 'ajustes' => []], ['id' => 0, 'nombre' => 'Con fresas', 'precio_delta' => '30.00', 'predeterminada' => false, 'activo' => true, 'ajustes' => $aj]]]];
    $json = static function (ClienteM3 $c, string $p, array $d): array { $ref = new ReflectionProperty(ClienteInstalacion3E::class, 'curl'); $curl = $ref->getValue($c->c); curl_setopt_array($curl, [CURLOPT_URL => 'http://127.0.0.1:8094' . $p, CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => json_encode($d), CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json']]); $raw = (string)curl_exec($curl); $s = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE); return ['status' => (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'json' => json_decode(substr($raw, $s), true)]; };
    $datosQA['__json'] = true;
    $v1 = $json($R, '/admin/api/menu/variantes', ['admin_csrf' => $csrfV, 'producto_id' => $botella, 'grupos' => $grupo([['accion' => 'agregar', 'tipo' => 'ingrediente', 'ref_id' => $fresa, 'cantidad' => '2']])]);
    $ok('1.4e', 'Variante que agrega un artículo → REVENTA_SIN_AJUSTES', ($v1['json']['ok'] ?? true) === false && str_contains(json_encode($v1['json'], JSON_UNESCAPED_UNICODE), 'sólo admite variantes de precio'), 'HTTP ' . $v1['status'] . ' · ' . mb_substr(json_encode($v1['json']['errores'] ?? $v1['json'], JSON_UNESCAPED_UNICODE), 0, 160));
    $v2 = $json($R, '/admin/api/menu/variantes', ['admin_csrf' => $csrfV, 'producto_id' => $botella, 'grupos' => $grupo([])]);
    $ok('1.4f', 'Variante sólo de precio se guarda', ($v2['json']['ok'] ?? false) === true, 'HTTP ' . $v2['status']);
    $datosQA += compact('copa', 'botella', 'pCopa', 'pBot');

    // 1.5 Ingrediente: salsa elaborada y pasta al vino con desglose.
    [$re, $ale] = $elaborar($R, 'restaurante', $salsa, '1000', 'g', [[$tomate, '800', 'g'], [$vino, '200', 'ml']]);
    $vs = $version($salsa);
    $ok('1.5a', 'Salsa al vino: elaboración 1000 g = tomate 800 g + vino 200 ml', $vs > 0, 'HTTP ' . $re['status'] . ' · versión ' . $vs . ' ' . $ale);
    [$r, $pastaO] = $crearOferta($R, 'QA M3 Pasta al vino', 'preparado', '180.00');
    [$pPasta, $alpp] = $presentarCompleta($R, $pastaO, 'plato', 'Plato', '180.00', [[$pasta, '120', 'g', 'almacenado'], [$vino, '50', 'ml', 'almacenado'], [$salsa, '150', 'g', 'desglosar', $vs]], [$pasta => COCINA, $vino => COCINA, $tomate => COCINA]);
    $alop = '';
    $origen = $q('SELECT articulo_id,almacen_id FROM oferta_consumos WHERE oferta_id=? ORDER BY articulo_id', [$pastaO]);
    $ok('1.5b', 'Pasta al vino: pasta 120 g + vino 50 ml + salsa 150 g desglosada, orígenes Cocina', $pPasta > 0 && count($origen) >= 3 && count(array_filter($origen, static fn($o) => (int)$o['almacen_id'] !== COCINA)) === 0, "presentación $pPasta $alpp · orígenes " . json_encode($origen) . " $alop");
    $fichaHtml = $R->get($baseArt('restaurante') . '/editar?id=' . $vino)['body'];
    $enlaces = static fn(int $o) => ClienteInstalacion3E::dom($fichaHtml)->query('//a[contains(@href,"presentaciones?id=' . $o . '")]')->length;
    $usos = ['copa' => $enlaces($copa), 'botella' => $enlaces($botella), 'pasta' => $enlaces($pastaO), 'salsa' => (int)str_contains($texto($fichaHtml), 'QA M3 Salsa al vino')];
    $ok('1.5c', '«Dónde se utiliza» del vino enlaza copa, botella, pasta y la salsa', !in_array(0, $usos, true), json_encode($usos));
    $sec = $texto($fichaHtml); $i = mb_strpos($sec, 'Presentaciones');
    $obs('1.5e', '«Dónde se utiliza» rotula cada presentación sólo con su nombre y repite cada versión', 'Se lee: ' . mb_substr($sec, $i, 150) . ' — sin el nombre del producto, dos «Copa» de productos distintos no se distinguen.');
    [$rf, $copaFresas] = $crearOferta($R, 'QA M3 Copa de fresas', 'preparado', '60.00');
    [$pFresas] = $presentarCompleta($R, $copaFresas, 'copa', 'Copa', '60.00', [[$fresa, '5', 'pza', 'almacenado']], [$fresa => BARRA]);
    $ok('1.5d', 'Copa de fresas: fresas 5 pza con origen Barra', $pFresas > 0 && (int)$v('SELECT almacen_id FROM oferta_consumos WHERE oferta_id=? AND articulo_id=?', [$copaFresas, $fresa]) === BARRA, "presentación $pFresas");
    $datosQA += compact('pastaO', 'pPasta', 'copaFresas', 'pFresas');

    // Cambiar a reventa una oferta con dos componentes se rechaza sin cambios.
    $antes = $q('SELECT tipo_venta,tipo_venta_estado FROM ofertas_producto WHERE id=?', [$pastaO])[0];
    $pe = $R->get('/admin/menu/edit?id=' . $pastaO);
    $pares = $campos($pe['body'], '//form[.//input[@name="menu_csrf"]]');
    $rr = $R->postCrudo('/admin/menu/edit?id=' . $pastaO, $cuerpo($pares, ['tipo_venta' => 'reventa']));
    $desp = $q('SELECT tipo_venta,tipo_venta_estado FROM ofertas_producto WHERE id=?', [$pastaO])[0];
    $ok('1.4g', 'Cambiar a reventa una oferta con dos componentes se rechaza sin cambios', $antes === $desp, 'antes ' . json_encode($antes) . ' · después ' . json_encode($desp) . ' · ' . mb_substr($alertas($rr['body']), 0, 140));

    // 1.6 Sugeridos: confirmación en lote sin tocar precio, receta ni disponibilidad.
    $huella = static fn() => hash('sha256', json_encode([
        $q('SELECT o.id,o.precio,o.activo,o.producto_id FROM ofertas_producto o WHERE o.unidad_id=2 AND o.producto_id<1000 ORDER BY o.id'),
        $q('SELECT r.oferta_id,r.version,r.activo,rc.articulo_id,rc.subreceta_id,rc.cantidad FROM recetas r JOIN receta_componentes rc ON rc.receta_id=r.id AND rc.unidad_id=r.unidad_id JOIN ofertas_producto o ON o.id=r.oferta_id WHERE o.producto_id<1000 AND r.unidad_id=2 ORDER BY r.id,rc.id'),
    ]));
    $originales = (int)$v('SELECT COUNT(*) FROM productos WHERE id<1000');
    $sugReventa = (int)$v("SELECT COUNT(*) FROM ofertas_producto WHERE unidad_id=2 AND tipo_venta='reventa' AND tipo_venta_estado='sugerido'");
    $sug = $q("SELECT id FROM ofertas_producto WHERE unidad_id=2 AND tipo_venta_estado='sugerido' ORDER BY id LIMIT 3");
    $lista = $R->get('/admin/menu?tipo=por_confirmar');
    $enLista = count(array_filter($sug, static fn($s) => ClienteInstalacion3E::dom($lista['body'])->query('//input[@name="ofertas[]" and @value="' . $s['id'] . '"]')->length > 0));
    $obs('1.6a', 'Precondición del plan: una oferta sugerida como reventa', $sugReventa === 0 ? "No existe en el fixture: las ofertas en «sugerido» son todas «preparado» ({$v("SELECT COUNT(*) FROM ofertas_producto WHERE unidad_id=2 AND tipo_venta_estado='sugerido'")}). No se pudo provocar el rechazo por segundo componente." : "$sugReventa sugeridas como reventa");
    $h0 = $huella();
    $csrfMenu = ClienteInstalacion3E::token($lista['body'], 'menu_csrf');
    $lote = $R->postCrudo('/admin/menu/tipo-venta', implode('&', array_merge(['menu_csrf=' . rawurlencode($csrfMenu)], array_map(static fn($s) => 'ofertas%5B%5D=' . $s['id'], $sug))));
    $confirmadas = (int)$v("SELECT COUNT(*) FROM ofertas_producto WHERE id IN (" . implode(',', array_map(static fn($s) => (int)$s['id'], $sug)) . ") AND tipo_venta_estado='confirmado'");
    $ok('1.6b', 'Confirmación en lote de tres sugeridas desde «Por confirmar»', $enLista === 3 && $confirmadas === 3, "en la lista $enLista/3 · confirmadas $confirmadas/3 · " . mb_substr($alertas($lote['body']), 0, 120));
    $ok('1.6c', 'Ninguno de los 112 originales cambia de precio, receta ni disponibilidad', $originales === 112 && $huella() === $h0, "$originales originales · huella " . ($huella() === $h0 ? 'igual' : 'distinta'));

    // 1.7 OBS: una oferta con copa y botella. ¿Qué tipo acepta y cambia el descuento?
    [, $carta] = $crearOferta($R, 'QA M3 Vino tinto (carta)', 'preparado', '95.00');
    [$pc1] = $presentarCompleta($R, $carta, 'copa', 'Copa 150 ml', '95.00', [[$vino, '150', 'ml', 'almacenado']], [$vino => BARRA]);
    [$pc2] = $presentarCompleta($R, $carta, 'botella', 'Botella 750 ml', '420.00', [[$vino, '750', 'ml', 'almacenado']], [$vino => BARRA]);
    $pe = $R->get('/admin/menu/edit?id=' . $carta);
    $rr = $R->postCrudo('/admin/menu/edit?id=' . $carta, $cuerpo($campos($pe['body'], '//form[.//input[@name="menu_csrf"]]'), ['tipo_venta' => 'reventa']));
    $t = $q('SELECT tipo_venta,tipo_venta_estado FROM ofertas_producto WHERE id=?', [$carta])[0] ?? [];
    $obs('1.7', 'Una oferta con Copa 150 ml y Botella 750 ml', "presentaciones $pc1/$pc2 · como preparado se guarda; al pasarla a reventa queda " . json_encode($t) . '. El tipo es de la oferta, no de la presentación, y ambas presentaciones descuentan el mismo artículo almacenado: «preparado» o «reventa» no cambia el descuento (AP-6).');
    $datosQA += compact('carta', 'pc1', 'pc2');
});

// ── Punto de venta ──────────────────────────────────────────────────────
$datosQA = array_merge($datosQA, array_filter([
    'copa' => $idOferta('QA M3 Copa de vino tinto'), 'botella' => $idOferta('QA M3 Botella de vino tinto'), 'pastaO' => $idOferta('QA M3 Pasta al vino'), 'copaFresas' => $idOferta('QA M3 Copa de fresas'),
]));
foreach (['pCopa' => ['copa', 'copa'], 'pBot' => ['botella', 'botella'], 'pPasta' => ['pastaO', 'plato'], 'pFresas' => ['copaFresas', 'copa']] as $k => [$o, $cl]) if (!isset($datosQA[$k]) && !empty($datosQA[$o])) $datosQA[$k] = $presentacionDe($datosQA[$o], $cl);
$mesasLibres = array_map('intval', array_column($q("SELECT m.id FROM mesas m WHERE m.unidad_id=2 AND m.activo=1 AND NOT EXISTS (SELECT 1 FROM ticket_mesas tm JOIN tickets t ON t.id=tm.ticket_id WHERE tm.mesa_id=m.id AND t.estado='abierto') ORDER BY m.id"), 'id'));
$abrir = static function (string $nombre) use ($PISO, &$mesasLibres): int {
    if ($PISO->posCsrf === '') $PISO->abrirPos();
    // Una mesa puede rechazar la apertura (reserva próxima): se prueba la siguiente.
    while ($mesa = array_shift($mesasLibres)) {
        $r = $PISO->pos('/api/abrir-ticket', ['mesa_id' => $mesa, 'comensales' => 2, 'nombre' => $nombre]);
        $id = (int)($r['json']['ticket_id'] ?? $r['json']['id'] ?? 0);
        if ($id > 0) return $id;
    }
    throw new RuntimeException('No quedan mesas libres para abrir tickets.');
};
$enviar = static function (int $ticket, array $items, string $clave) use ($PISO, $v, $q): array {
    if ($PISO->posCsrf === '') $PISO->abrirPos();
    // La respuesta pública no publica los IDs de las líneas: se leen del ticket (SELECT).
    $tope = (int)($v('SELECT COALESCE(MAX(id),0) FROM ticket_items WHERE ticket_id=?', [$ticket]) ?? 0);
    $filas = array_map(static fn($i) => ['oferta_id' => $i[0], 'cantidad' => $i[1], 'comensal' => 1] + (!empty($i[2]) ? ['presentacion_id' => $i[2]] : []) + (!empty($i[3]) ? ['variantes' => $i[3]] : []), $items);
    $r = $PISO->pos('/api/enviar-comanda', ['ticket_id' => $ticket, 'clave_idempotencia' => $clave, 'items' => $filas]);
    $ids = array_map('intval', array_column($q('SELECT id FROM ticket_items WHERE ticket_id=? AND id>? ORDER BY id', [$ticket, $tope]), 'id'));
    return ['status' => $r['status'], 'json' => $r['json'] ?? [], 'items' => $ids];
};
$cancelar = static fn(int $item) => $PISO->pos('/api/cancelar-item', ['item_id' => $item]);
$movItem = static fn(int $item) => $q('SELECT tipo,almacen_id,articulo_id,delta,motivo,faltante_id FROM movimientos_inventario WHERE ticket_item_id=? ORDER BY id', [$item]);
$faltantesDe = static fn(int $item) => $q('SELECT id,almacen_id,articulo_id,cantidad,cantidad_conciliada,cantidad_en_confirmacion,estado,version FROM faltantes_inventario WHERE ticket_item_id=? ORDER BY id', [$item]);
$sumaDelta = static function (array $movs, int $alm, int $art): string { $t = 0; foreach ($movs as $m) if ((int)$m['almacen_id'] === $alm && (int)$m['articulo_id'] === $art) $t += Services\Inventory\DecimalInventario::leer((string)$m['delta'], 3, true); return Services\Inventory\DecimalInventario::formato($t); };
$clave = static fn(string $s) => substr('qa-m3-' . $s . '-' . str_repeat('0', 20), 0, 40);
$conciliar = static function (ClienteM3 $c, int $faltante, array $asign, ?int $version = null, string $k = '') use ($v, $baseAlm, $alertas): array {
    $version ??= (int)$v('SELECT version FROM faltantes_inventario WHERE id=?', [$faltante]);
    $pag = $c->get($baseAlm('restaurante') . '?vista=faltantes');
    $tok = ClienteInstalacion3E::token($pag['body']);
    $post = $c->post($baseAlm('restaurante') . '/operacion', ['admin_csrf' => $tok, 'accion' => 'conciliar_faltante', 'volver_vista' => 'faltantes', 'faltante_id' => (string)$faltante, 'version' => (string)$version, 'clave' => $k !== '' ? $k : 'qa-conc-' . bin2hex(random_bytes(8)), 'asignaciones' => array_map(static fn($a) => ['almacen_id' => (string)$a[0], 'cantidad' => (string)$a[1]], $asign)]);
    $fin = $c->seguir($post);
    return [$post, $fin, $alertas($fin['body'])];
};
$transferencia = static function (ClienteM3 $c, string $ctx, string $comando, int $tid, array $extra = []) use ($v, $operar): array {
    $ver = (int)$v('SELECT version FROM transferencias WHERE id=?', [$tid]);
    // El servidor exige motivo en toda acción de transferencia.
    return $operar($c, $ctx, 'transferencia_' . $comando, $extra + ['transferencia_id' => (string)$tid, 'version' => (string)$ver, 'clave' => 'qa-tr-' . bin2hex(random_bytes(8)), 'motivo' => 'QA M3 ' . $comando]);
};

// ════════════════════════════════════════════════════════════════════════
// Prueba 2 — Preparado: descuento por receta y faltante sin detener la producción
// ════════════════════════════════════════════════════════════════════════
if (in_array(2, $solo, true)) $seccion('Prueba 2 · Preparado y faltantes', function () use (&$datosQA, $G, $GO, $R, $PISO, $q, $v, $ok, $obs, $abrir, $enviar, $cancelar, $movItem, $faltantesDe, $sumaDelta, $clave, $conciliar, $transferencia, $operar, $exito, $saldo, $crearArticulo, $capacidades, $crearOferta, $presentarCompleta, $origenes, $version, $alertas, $texto, $baseAlm, $baseArt, $campos, $cuerpo, $integridad) {
    ['vino' => $vino, 'fresa' => $fresa, 'pasta' => $pasta, 'salsa' => $salsa, 'tomate' => $tomate] = $datosQA;
    $t1 = $abrir('QA M3 Prueba 2');
    $ok('2.0', 'El piso abre un ticket con sesión de NIP', $t1 > 0, "ticket $t1");
    $datosQA['ticket2'] = $t1;

    // 2.1 Camino feliz con desglose de la salsa
    $antes = ['pasta' => $saldo(COCINA, $pasta), 'vino' => $saldo(COCINA, $vino), 'tomate' => $saldo(COCINA, $tomate)];
    $e = $enviar($t1, [[$datosQA['pastaO'], 2, $datosQA['pPasta']]], $clave('pasta-2'));
    $item = $e['items'][0] ?? 0; $m = $item ? $movItem($item) : [];
    $d = ['pasta' => $sumaDelta($m, COCINA, $pasta), 'vino' => $sumaDelta($m, COCINA, $vino), 'tomate' => $sumaDelta($m, COCINA, $tomate)];
    $ok('2.1a', '2 × Pasta al vino: pasta −240 g, vino −160 ml (100 directo + 60 de la salsa), tomate −240 g', ($e['json']['ok'] ?? false) === true && $d === ['pasta' => '-240.000', 'vino' => '-160.000', 'tomate' => '-240.000'], "item $item · deltas " . json_encode($d) . ' · ' . ($e['json']['codigo'] ?? ''));
    $res = (int)$v('SELECT COUNT(*) FROM ticket_item_resoluciones WHERE ticket_item_id=?', [$item]);
    $ok('2.1b', 'Un solo ticket_item_resoluciones por item y sin faltantes', $res === 1 && $faltantesDe($item) === [], "resoluciones $res · faltantes " . count($faltantesDe($item)));
    $directo = array_values(array_filter($m, static fn($x) => (int)$x['articulo_id'] === $vino));
    $obs('2.1c', 'El vino directo y el de la salsa salen en movimientos separados o agregados', count($directo) . ' movimiento(s) de vino: ' . json_encode(array_column($directo, 'delta')));

    // 2.2 Subreceta legada (oferta 1002: harina 80 g + «Porción de leche» × 20)
    foreach ([[1001, '500', 'qa-harina'], [1002, '500', 'qa-leche']] as [$art, $cant, $k]) $operar($R, 'restaurante', 'entrada', ['almacen_id' => COCINA, 'articulo_id' => $art, 'cantidad' => $cant, 'clave' => $k]);
    $e = $enviar($t1, [[1002, 1]], $clave('legado-1'));
    $item = $e['items'][0] ?? 0; $m = $item ? $movItem($item) : [];
    $d = ['harina' => $sumaDelta($m, COCINA, 1001), 'leche' => $sumaDelta($m, COCINA, 1002)];
    $ok('2.2a', 'Subreceta legada: harina −80 g y leche −20 ml (20 sobre rendimiento 100 ml)', $d === ['harina' => '-80.000', 'leche' => '-20.000'], "item $item · " . json_encode($d) . ' · ' . ($e['json']['codigo'] ?? ''));
    $crearArticulo($G, ['nombre' => 'QA M3 Porción de leche', 'unidad' => 'ml', 'contenido_pieza' => '', 'costo' => '0.0200', 'activo' => '1']);
    $porcion = (int)$v('SELECT id FROM ingredientes WHERE nombre=?', ['QA M3 Porción de leche']);
    $cat = $R->get($baseArt('restaurante'));
    $pend = ClienteInstalacion3E::dom($cat['body'])->query('//form[.//*[@name="subreceta_id"]]')->length;
    $pares = $pend ? $campos($cat['body'], '//form[.//*[@name="subreceta_id"]]') : [];
    $vinc = $pend ? $R->postCrudo($baseArt('restaurante'), $cuerpo($pares, ['subreceta_id' => '1001', 'articulo_id' => (string)$porcion, 'confirmar' => '1'])) : ['status' => 0, 'body' => ''];
    $corr = (int)$v('SELECT articulo_id FROM articulo_correspondencias WHERE subreceta_id=1001');
    $ok('2.2b', 'La subreceta «Porción de leche» se vincula a un artículo desde «Preparaciones anteriores por vincular»', $corr === $porcion && $porcion > 0, "formulario $pend · HTTP {$vinc['status']} · correspondencia $corr");
    $e2 = $enviar($t1, [[1002, 1]], $clave('legado-2'));
    $item2 = $e2['items'][0] ?? 0; $m2 = $item2 ? $movItem($item2) : [];
    $obs('2.2c', 'Tras vincular, la venta de la oferta 1002 cambia su descuento (AP-7)', 'HTTP ' . $e2['status'] . ' · ' . ($e2['json']['codigo'] ?? 'ok') . ' · ' . mb_substr((string)($e2['json']['mensaje'] ?? ''), 0, 120) . ' · movimientos ' . json_encode(array_map(static fn($x) => $x['articulo_id'] . ':' . $x['delta'], $m2)) . ' (antes: harina 1001 y leche 1002; el artículo vinculado es ' . $porcion . ')');

    // 2.3 Faltante sin detener la comanda
    $avisosAntes = (int)$v("SELECT COUNT(*) FROM buzon_notificaciones WHERE tipo='inventario.faltante'");
    $e = $enviar($t1, [[$datosQA['copaFresas'], 10, $datosQA['pFresas']]], $clave('fresas-10'));
    $item = $e['items'][0] ?? 0; $m = $item ? $movItem($item) : []; $f = $item ? $faltantesDe($item) : [];
    $ok('2.3a', '10 × Copa de fresas con Barra en 20: HTTP 200 y la comanda sale', $e['status'] === 200 && ($e['json']['ok'] ?? false) === true && $item > 0, 'HTTP ' . $e['status'] . ' · item ' . $item);
    $ok('2.3b', 'Barra queda en 0 con un movimiento venta de −20', $saldo(BARRA, $fresa) === '0.000' && count($m) === 1 && $m[0]['delta'] === '-20.000' && $m[0]['tipo'] === 'venta', json_encode($m));
    $ok('2.3c', 'faltantes_inventario registra 30 pendientes ligados al item', count($f) === 1 && $f[0]['cantidad'] === '30.000' && $f[0]['estado'] === 'pendiente', json_encode($f));
    $avisos = (int)$v("SELECT COUNT(*) FROM buzon_notificaciones WHERE tipo='inventario.faltante'");
    $alm = $texto($R->get('/admin/almacenes')['body']);
    $ok('2.3d', 'Hay aviso en buzon_notificaciones y Almacenes muestra los faltantes por conciliar', $avisos === $avisosAntes + 1 && preg_match('/(\d+) faltantes? por conciliar/u', $alm, $mm) === 1, "avisos +" . ($avisos - $avisosAntes) . ' · Almacenes: «' . ($mm[0] ?? 'sin aviso') . '»');
    $buzonR = $R->get('/admin/api/buzon/resumen');
    $admin = $GLOBALS['__admin'];
    $buzon = $admin ? $admin->get('/admin/api/buzon/resumen') : ['status' => 0, 'json' => null];
    $topbar = json_encode($buzon['json'], JSON_UNESCAPED_UNICODE);
    $ok('2.3e', 'AP-1: el aviso de faltante aparece en el buzón del topbar', str_contains($topbar, 'faltante') || str_contains($topbar, 'inventario'), 'operador_restaurante: HTTP ' . $buzonR['status'] . ' ' . ($buzonR['json']['codigo'] ?? '') . ' · admin_demo: HTTP ' . $buzon['status'] . ' · ' . mb_substr($topbar, 0, 200));
    $datosQA['faltante23'] = (int)($f[0]['id'] ?? 0); $datosQA['item23'] = $item;

    // 2.5 (preparación) salsa con producción previa usada como almacenada
    [$rc] = $capacidades($R, 'restaurante', $salsa, ['componente', 'elaborable', 'almacenable', 'produccion_previa', 'activo']);
    [, $salsaServ] = $crearOferta($R, 'QA M3 Salsa servida', 'preparado', '40.00');
    [$pSalsa, $evs] = $presentarCompleta($R, $salsaServ, 'porcion', 'Porción', '40.00', [[$salsa, '100', 'g', 'almacenado']], [$salsa => COCINA]);
    $ok('2.5a', 'Salsa marcada «Se elabora antes de vender» y servida como almacenada desde Cocina', (int)($q('SELECT produccion_previa FROM articulo_configuraciones WHERE articulo_id=? AND unidad_id=2', [$salsa])[0]['produccion_previa'] ?? 0) === 1 && $pSalsa > 0, "presentación $pSalsa · $evs · saldo salsa " . $saldo(COCINA, $salsa));

    // 2.4 Dos productos con faltante en una comanda y replay
    $k24 = $clave('doble-faltante');
    $e = $enviar($t1, [[$datosQA['copaFresas'], 1, $datosQA['pFresas']], [$salsaServ, 1, $pSalsa]], $k24);
    $fals = array_merge(...array_map($faltantesDe, $e['items'] ?: [0]));
    $ok('2.4a', 'Dos productos sin saldo generan un faltante por artículo y almacén', ($e['json']['ok'] ?? false) === true && count($fals) === 2, json_encode(array_map(static fn($x) => $x['almacen_id'] . '/' . $x['articulo_id'] . ':' . $x['cantidad'], $fals)));
    $totalF = (int)$v('SELECT COUNT(*) FROM faltantes_inventario'); $sB = $saldo(BARRA, $fresa);
    $rp = $enviar($t1, [[$datosQA['copaFresas'], 1, $datosQA['pFresas']], [$salsaServ, 1, $pSalsa]], $k24);
    $ok('2.4b', 'El replay con la misma clave no descuenta ni crea otro faltante', ($rp['json']['reintento'] ?? false) === true && (int)$v('SELECT COUNT(*) FROM faltantes_inventario') === $totalF && $saldo(BARRA, $fresa) === $sB, 'reintento ' . json_encode($rp['json']['reintento'] ?? null));
    $fSalsa = array_values(array_filter($fals, static fn($x) => (int)$x['articulo_id'] === $salsa));
    $ok('2.5b', 'Producción previa: el faltante es de salsa, no de tomate ni de vino, y la comanda sale', count($fSalsa) === 1 && $fSalsa[0]['cantidad'] === '100.000' && count(array_filter($fals, static fn($x) => in_array((int)$x['articulo_id'], [$tomate, $vino], true))) === 0, json_encode($fSalsa));
    $datosQA['salsaServ'] = $salsaServ; $datosQA['pSalsa'] = $pSalsa;
    $integridad('2.1–2.5');

    // 2.6 Conciliación
    $f23 = $datosQA['faltante23'];
    $sR = $saldo(REFRI, $fresa);
    $api = $conciliar($R, $f23, [[REFRI, '20'], [BARRA, '10']]);
    $ok('2.6a', 'Repartir 20 de Refrigeración y 10 de Barra (en 0) se rechaza completo', !str_contains($api[2], 'confirmada') && $saldo(REFRI, $fresa) === $sR && $saldo(BARRA, $fresa) === '0.000' && $q('SELECT estado FROM faltantes_inventario WHERE id=?', [$f23])[0]['estado'] === 'pendiente', mb_substr($api[2], 0, 160));
    $mal = $conciliar($R, $f23, [[REFRI, '29']]);
    $ok('2.6b', 'Una suma inexacta da SUMA_INVALIDA', str_contains($mal[2], 'debe sumar exactamente'), mb_substr($mal[2], 0, 160));
    $cero = $conciliar($R, $f23, [[REFRI, '30'], [CENTRAL, '0.00']]);
    $okCero = str_contains($cero[2], 'confirmada');
    $obs('2.6c', 'AP-12: «0.00» en un origen que no se usa', $okCero ? 'Se aceptó: la fila con 0.00 no bloqueó la conciliación.' : 'Se rechazó: ' . mb_substr($cero[2], 0, 160));
    if (!$okCero) $conciliar($R, $f23, [[REFRI, '30']]);
    $fz = $q('SELECT estado FROM faltantes_inventario WHERE id=?', [$f23])[0]['estado'];
    $reg = $q("SELECT id,estado,tipo FROM transferencias WHERE tipo='regularizacion' AND almacen_origen_id=? AND almacen_destino_id=? ORDER BY id DESC LIMIT 1", [REFRI, BARRA])[0] ?? [];
    $mc = $q('SELECT tipo,almacen_id,delta,motivo,faltante_id FROM movimientos_inventario WHERE faltante_id=? ORDER BY id', [$f23]);
    $tipos = array_column($q('SELECT m.tipo FROM movimientos_inventario m JOIN transferencia_lineas l ON l.id=m.transferencia_linea_id WHERE l.transferencia_id=? ORDER BY m.id', [(int)($reg['id'] ?? 0)]), 'tipo');
    $ok('2.6d', 'Mismo negocio: 30 de Refrigeración (queda en 10) se aplica al instante', $fz === 'conciliado' && $saldo(REFRI, $fresa) === '10.000' && $saldo(BARRA, $fresa) === '0.000' && ($reg['estado'] ?? '') === 'recibida' && in_array('transferencia_salida', $tipos, true) && in_array('transferencia_entrada', $tipos, true) && count(array_filter($mc, static fn($x) => $x['tipo'] === 'venta' && $x['motivo'] === 'venta_conciliada')) === 1, "faltante $fz · transferencia " . json_encode($reg) . ' · ' . json_encode($tipos) . ' · venta ' . json_encode($mc));

    // Otro negocio, confirma: faltante nuevo de 30 → 10 Refrigeración + 20 Central
    $e = $enviar($t1, [[$datosQA['copaFresas'], 6, $datosQA['pFresas']]], $clave('fresas-6'));
    $fB = $faltantesDe($e['items'][0] ?? 0)[0] ?? [];
    $c1 = $conciliar($R, (int)($fB['id'] ?? 0), [[REFRI, '10'], [CENTRAL, '20']]);
    $fBe = $q('SELECT estado,cantidad_conciliada,cantidad_en_confirmacion FROM faltantes_inventario WHERE id=?', [(int)($fB['id'] ?? 0)])[0] ?? [];
    $trG = $q("SELECT id,estado FROM transferencias WHERE tipo='regularizacion' AND almacen_origen_id=? ORDER BY id DESC LIMIT 1", [CENTRAL])[0] ?? [];
    $ok('2.6e', 'Otro negocio: la parte de Refrigeración se aplica y el faltante queda en confirmación', ($fB['cantidad'] ?? '') === '30.000' && ($fBe['estado'] ?? '') === 'en_confirmacion' && ($fBe['cantidad_conciliada'] ?? '') === '10.000' && ($trG['estado'] ?? '') === 'solicitada', 'faltante ' . json_encode($fB + $fBe) . ' · transferencia ' . json_encode($trG) . ' · ' . mb_substr($c1[2], 0, 80));
    $conf = $transferencia($GO, 'global', 'confirmar', (int)($trG['id'] ?? 0), ['motivo' => 'Confirmación QA M3']);
    $fBf = $q('SELECT estado FROM faltantes_inventario WHERE id=?', [(int)($fB['id'] ?? 0)])[0]['estado'] ?? '';
    $ok('2.6f', 'operador_global confirma en Transferencias y el faltante queda conciliado', $exito($conf) && $fBf === 'conciliado' && $saldo(CENTRAL, $fresa) === '10.000', "faltante $fBf · Central " . $saldo(CENTRAL, $fresa) . ' · ' . mb_substr($conf[2], 0, 100));
    $datosQA['faltanteB'] = (int)($fB['id'] ?? 0); $datosQA['itemB'] = $e['items'][0] ?? 0;

    // Otro negocio, rechaza: 30 del Central, que sólo tiene 10
    $e = $enviar($t1, [[$datosQA['copaFresas'], 6, $datosQA['pFresas']]], $clave('fresas-6b'));
    $fC = $faltantesDe($e['items'][0] ?? 0)[0] ?? [];
    $c2 = $conciliar($R, (int)($fC['id'] ?? 0), [[CENTRAL, '30']]);
    $trC = $q("SELECT id,estado FROM transferencias WHERE tipo='regularizacion' AND almacen_origen_id=? ORDER BY id DESC LIMIT 1", [CENTRAL])[0] ?? [];
    $conf2 = $transferencia($GO, 'global', 'confirmar', (int)($trC['id'] ?? 0), ['motivo' => 'Confirmación QA M3']);
    $ok('2.6g', 'Confirmar 30 desde el Central (tiene 10) falla por saldo', !$exito($conf2) && $saldo(CENTRAL, $fresa) === '10.000', mb_substr($c2[2], 0, 60) . ' · confirmar: ' . mb_substr($conf2[2], 0, 140));
    $avisosAbiertosAntes = (int)$v("SELECT COUNT(*) FROM buzon_notificaciones WHERE tipo='inventario.faltante' AND cerrada_at IS NULL");
    $rech = $transferencia($GO, 'global', 'rechazar', (int)($trC['id'] ?? 0), ['motivo' => 'Sin existencias suficientes en el Central']);
    $fCe = $q('SELECT estado,cantidad_en_confirmacion FROM faltantes_inventario WHERE id=?', [(int)($fC['id'] ?? 0)])[0] ?? [];
    $ok('2.6h', 'Global rechaza con motivo: la parte vuelve a pendiente y el aviso se reabre', $exito($rech) && ($fCe['estado'] ?? '') === 'pendiente' && ($fCe['cantidad_en_confirmacion'] ?? '') === '0.000' && (int)$v("SELECT COUNT(*) FROM buzon_notificaciones WHERE tipo='inventario.faltante' AND cerrada_at IS NULL") >= 1, 'faltante ' . json_encode($fCe) . ' · ' . mb_substr($rech[2], 0, 100) . " · avisos abiertos antes $avisosAbiertosAntes");
    $datosQA['faltanteC'] = (int)($fC['id'] ?? 0); $datosQA['itemC'] = $e['items'][0] ?? 0;

    // Nadie alcanza: vaciar Refrigeración por merma y luego una entrada de 30.
    $operar($R, 'restaurante', 'merma', ['almacen_id' => REFRI, 'articulo_id' => $fresa, 'cantidad' => '10', 'motivo' => 'dano', 'clave' => 'qa-merma-refri']);
    $bandeja = $texto($R->get('/admin/almacenes?vista=faltantes')['body']);
    $ok('2.6i', 'Con Refrigeración en 0 la bandeja marca que ningún almacén propio cubre', $saldo(REFRI, $fresa) === '0.000' && str_contains($bandeja, 'Ningún almacén propio cubre'), 'Refrigeración ' . $saldo(REFRI, $fresa) . ' · ' . (str_contains($bandeja, 'Ningún almacén propio cubre') ? 'aviso presente' : 'sin el aviso'));
    $abiertosAntes = (int)$v("SELECT COUNT(*) FROM buzon_notificaciones WHERE tipo='inventario.faltante' AND cerrada_at IS NULL");
    $operar($R, 'restaurante', 'entrada', ['almacen_id' => REFRI, 'articulo_id' => $fresa, 'cantidad' => '30', 'clave' => 'qa-entrada-refri-30']);
    $abiertos = (int)$v("SELECT COUNT(*) FROM buzon_notificaciones WHERE tipo='inventario.faltante' AND cerrada_at IS NULL");
    $vC = (int)$v('SELECT version FROM faltantes_inventario WHERE id=?', [$datosQA['faltanteC']]);
    $c3 = $conciliar($R, $datosQA['faltanteC'], [[REFRI, '30']], $vC);
    $ok('2.6j', 'Tras una entrada de 30 el aviso se reabre y se puede conciliar', $abiertos >= $abiertosAntes && str_contains($c3[2], 'confirmada') && $q('SELECT estado FROM faltantes_inventario WHERE id=?', [$datosQA['faltanteC']])[0]['estado'] === 'conciliado', "avisos abiertos {$abiertosAntes}→{$abiertos} · " . mb_substr($c3[2], 0, 80));
    // Dos pestañas: la segunda usa la versión vieja.
    $operar($R, 'restaurante', 'entrada', ['almacen_id' => REFRI, 'articulo_id' => $fresa, 'cantidad' => '20', 'clave' => 'qa-entrada-refri-20']);
    $e = $enviar($t1, [[$datosQA['copaFresas'], 2, $datosQA['pFresas']]], $clave('fresas-2'));
    $fD = $faltantesDe($e['items'][0] ?? 0)[0] ?? [];
    $vD = (int)($fD['version'] ?? 0);
    $pest1 = $conciliar($R, (int)($fD['id'] ?? 0), [[REFRI, $fD['cantidad'] ?? '10']], $vD);
    $pest2 = $conciliar($R, (int)($fD['id'] ?? 0), [[REFRI, $fD['cantidad'] ?? '10']], $vD);
    $ok('2.6k', 'Dos pestañas sobre el mismo faltante: la segunda da VERSION_CONFLICTO', str_contains($pest1[2], 'confirmada') && str_contains($pest2[2], 'cambió mientras lo conciliabas'), mb_substr($pest1[2], 0, 60) . ' · ' . mb_substr($pest2[2], 0, 120));
    $integridad('2.6');

    // 2.7 Cancelación
    $e = $enviar($t1, [[$datosQA['copaFresas'], 1, $datosQA['pFresas']]], $clave('cancel-pend'));
    $iP = $e['items'][0] ?? 0; $fP = $faltantesDe($iP)[0] ?? [];
    $sB0 = $saldo(BARRA, $fresa);
    $cancelar($iP);
    $fPe = $q('SELECT estado FROM faltantes_inventario WHERE id=?', [(int)($fP['id'] ?? 0)])[0]['estado'] ?? '';
    $comp = $q("SELECT SUM(delta) s FROM movimientos_inventario WHERE ticket_item_id=? AND tipo='cancelacion'", [$iP])[0]['s'] ?? null;
    $ok('2.7a', 'Cancelar un item con faltante pendiente lo anula y sólo compensa lo descontado', $fPe === 'anulado' && $saldo(BARRA, $fresa) === $sB0, "faltante $fPe · compensación " . json_encode($comp) . ' · Barra ' . $sB0 . '→' . $saldo(BARRA, $fresa));
    $e = $enviar($t1, [[$datosQA['copaFresas'], 2, $datosQA['pFresas']]], $clave('cancel-conf'));
    $iQ = $e['items'][0] ?? 0; $fQ = $faltantesDe($iQ)[0] ?? [];
    $conciliar($R, (int)($fQ['id'] ?? 0), [[CENTRAL, $fQ['cantidad'] ?? '10']]);
    $trQ = (int)$v("SELECT id FROM transferencias WHERE tipo='regularizacion' AND almacen_origen_id=? ORDER BY id DESC LIMIT 1", [CENTRAL]);
    $cancelar($iQ);
    $estQ = $v('SELECT estado FROM transferencias WHERE id=?', [$trQ]);
    $ok('2.7b', 'Cancelar un item con parte en confirmación deja su transferencia cancelada', $estQ === 'cancelada' && $q('SELECT estado FROM faltantes_inventario WHERE id=?', [(int)($fQ['id'] ?? 0)])[0]['estado'] === 'anulado', "transferencia $trQ $estQ");
    $trApl = (int)$v("SELECT id FROM transferencias WHERE tipo='regularizacion' AND almacen_origen_id=? AND estado='recibida' ORDER BY id LIMIT 1", [REFRI]);
    $sB1 = $saldo(BARRA, $fresa);
    $cancelar($datosQA['item23']);
    $ok('2.7c', 'Cancelar un item conciliado devuelve la compensación a Barra y no revierte la transferencia', $saldo(BARRA, $fresa) === Services\Inventory\DecimalInventario::formato(Services\Inventory\DecimalInventario::leer($sB1) + 50000) && $v('SELECT estado FROM transferencias WHERE id=?', [$trApl]) === 'recibida', 'Barra ' . $sB1 . '→' . $saldo(BARRA, $fresa) . ' (item de 50 pza: 20 descontadas + 30 conciliadas) · transferencia ' . $v('SELECT estado FROM transferencias WHERE id=?', [$trApl]));
    $integridad('2.7');

    // 2.8 Lo que sí detiene la comanda
    // Un producto nuevo no admite receta anterior (409): el artículo sin origen se
    // provoca con una variante que agrega un artículo que la oferta no tiene en orígenes.
    [, $sinOrigen] = $crearOferta($R, 'QA M3 Sin origen', 'preparado', '10.00');
    $rec = $R->postCrudo('/admin/recetas/editar?id=' . $sinOrigen, 'comp%5B%5D=' . rawurlencode('ingrediente:' . $pasta) . '&comp_cant%5B%5D=10');
    $obs('2.8x', 'Un producto nuevo no acepta receta anterior', 'HTTP ' . $rec['status'] . ' · ' . mb_substr($alertas($rec['body']), 0, 140));
    $pv = $R->get('/admin/menu/variantes?id=' . $datosQA['copa']);
    $csrfV = preg_match('/data-admin-csrf="([^"]+)"/', $pv['body'], $mv) ? html_entity_decode($mv[1], ENT_QUOTES | ENT_HTML5) : '';
    $gv = $GLOBALS['__json']($R, '/admin/api/menu/variantes', ['admin_csrf' => $csrfV, 'producto_id' => $datosQA['copa'], 'grupos' => [['nombre' => 'Extra', 'opciones' => [['id' => 0, 'nombre' => 'Sola', 'precio_delta' => '0.00', 'predeterminada' => true, 'activo' => true, 'ajustes' => []], ['id' => 0, 'nombre' => 'Con pasta', 'precio_delta' => '10.00', 'predeterminada' => false, 'activo' => true, 'ajustes' => [['accion' => 'agregar', 'tipo' => 'ingrediente', 'ref_id' => $pasta, 'cantidad' => '5']]]]]]]);
    $vid = (int)$v("SELECT id FROM producto_variantes WHERE oferta_id=? AND nombre='Con pasta'", [$datosQA['copa']]);
    $sinOrigenPasta = (int)$v('SELECT COUNT(*) FROM oferta_consumos WHERE oferta_id=? AND articulo_id=?', [$datosQA['copa'], $pasta]) === 0;
    $n0 = (int)$v('SELECT COUNT(*) FROM ticket_items WHERE ticket_id=?', [$t1]);
    $e = $enviar($t1, [[$datosQA['copa'], 1, $datosQA['pCopa'], [$vid]], [$datosQA['copaFresas'], 1, $datosQA['pFresas']]], $clave('sin-origen'));
    $ok('2.8a', 'Un artículo sin origen da ALMACEN_ORIGEN_FALTANTE y rechaza la comanda completa', $vid > 0 && $sinOrigenPasta && ($e['json']['codigo'] ?? '') === 'ALMACEN_ORIGEN_FALTANTE' && (int)$v('SELECT COUNT(*) FROM ticket_items WHERE ticket_id=?', [$t1]) === $n0, "variante $vid (HTTP {$gv['status']}) · pasta sin origen en la copa: " . ($sinOrigenPasta ? 'sí' : 'no') . ' · HTTP ' . $e['status'] . ' · ' . ($e['json']['codigo'] ?? 'ok'));
    $obs('2.8b', 'Requisito «no detener la producción» frente a ALMACEN_ORIGEN_FALTANTE', 'La copa de fresas, válida, tampoco salió: se rechazó la comanda entera (AP-2). La variante se guardó sin pedir el origen del artículo que agrega.');
    $n0 = (int)$v('SELECT COUNT(*) FROM ticket_items WHERE ticket_id=?', [$t1]);
    // Desactivar exige saldo en cero: se vacía Barra con una merma antes.
    $operar($R, 'restaurante', 'merma', ['almacen_id' => BARRA, 'articulo_id' => $fresa, 'cantidad' => $saldo(BARRA, $fresa), 'motivo' => 'otro', 'clave' => 'qa-vaciar-barra']);
    $o = $operar($R, 'restaurante', 'configurar_asociacion', ['almacen_id' => BARRA, 'articulo_id' => $fresa, 'minimo' => '0', 'activo' => null]);
    $inact = (int)$v('SELECT activo FROM almacen_articulos WHERE almacen_id=? AND articulo_id=?', [BARRA, $fresa]);
    $e = $enviar($t1, [[$datosQA['copa'], 1, $datosQA['pCopa']], [$datosQA['copaFresas'], 1, $datosQA['pFresas']]], $clave('asoc-inactiva'));
    $ok('2.8c', 'Una asociación inactiva rechaza la comanda completa', $inact === 0 && ($e['json']['ok'] ?? true) === false && (int)$v('SELECT COUNT(*) FROM ticket_items WHERE ticket_id=?', [$t1]) === $n0, "asociación activa=$inact · " . ($e['json']['codigo'] ?? '') . ' · ' . mb_substr($o[2], 0, 80));
    $operar($R, 'restaurante', 'configurar_asociacion', ['almacen_id' => BARRA, 'articulo_id' => $fresa, 'minimo' => '0', 'activo' => '1']);

    // 2.9 Volumen: 8 tickets con faltante de fresas el mismo día
    $f0 = (int)$v("SELECT COUNT(*) FROM faltantes_inventario WHERE estado='pendiente'"); $a0 = (int)$v("SELECT COUNT(*) FROM buzon_notificaciones WHERE tipo='inventario.faltante'");
    $abiertos = [];
    for ($i = 0; $i < 8; $i++) { $t = $abrir('QA M3 Volumen ' . ($i + 1)); $abiertos[] = $t; $enviar($t, [[$datosQA['copaFresas'], 1, $datosQA['pFresas']]], $clave('vol-' . $i)); }
    $nuevos = (int)$v("SELECT COUNT(*) FROM faltantes_inventario WHERE estado='pendiente'") - $f0;
    $avisosN = (int)$v("SELECT COUNT(*) FROM buzon_notificaciones WHERE tipo='inventario.faltante'") - $a0;
    $obs('2.9', 'Volumen: 8 tickets con faltante de fresas el mismo día', "$nuevos faltantes nuevos que conciliar uno por uno · $avisosN aviso(s) nuevo(s) en el buzón (deduplicado por almacén, artículo y día). Ver AP-3.");
    $datosQA['ticketsVolumen'] = $abiertos;
    $integridad('2.8–2.9');
});

// ════════════════════════════════════════════════════════════════════════
// Prueba 3 — Reventa: descuento solo del almacén declarado
// ════════════════════════════════════════════════════════════════════════
if (in_array(3, $solo, true)) $seccion('Prueba 3 · Reventa', function () use (&$datosQA, $R, $q, $v, $ok, $obs, $abrir, $enviar, $cancelar, $movItem, $faltantesDe, $saldo, $operar, $texto, $clave, $integridad) {
    $vino = $datosQA['vino'];
    $t = $abrir('QA M3 Prueba 3');
    $base = ['barra' => $saldo(BARRA, $vino), 'central' => $saldo(CENTRAL, $vino), 'cocina' => $saldo(COCINA, $vino)];
    // Lectura de la fila del vino en Existencias: saldo de Barra y su lectura en piezas.
    $lectura = static function () use ($R, $texto): string {
        $x = ClienteInstalacion3E::dom($R->get('/admin/almacenes?q=' . rawurlencode('QA M3 Vino tinto'))['body']);
        $n = $x->query('//tr[.//strong[normalize-space()="QA M3 Vino tinto"]]//ul[contains(@class,"almacen-balances")]/li[.//button[normalize-space()="Barra y Maridaje"]]//*[contains(@class,"almacen-balance-value")]')->item(0);
        return $n ? $texto($n->textContent) : 'sin lectura';
    };
    $e = $enviar($t, [[$datosQA['botella'], 1, $datosQA['pBot']]], $clave('botella-1'));
    $iB = $e['items'][0] ?? 0; $m = $iB ? $movItem($iB) : [];
    $ok('3.1', '1 × Botella: un solo movimiento venta de −750 ml en Barra; Central y Cocina intactos; «5 piezas»', count($m) === 1 && (int)$m[0]['almacen_id'] === BARRA && $m[0]['delta'] === '-750.000' && $saldo(CENTRAL, $vino) === $base['central'] && $saldo(COCINA, $vino) === $base['cocina'] && str_contains($lectura(), '5 piezas'), ($e['json']['codigo'] ?? 'ok') . ' ' . mb_substr((string)($e['json']['mensaje'] ?? ''), 0, 100) . ' · Barra ' . $base['barra'] . '→' . $saldo(BARRA, $vino) . ' · ' . $lectura() . ' · ' . json_encode($m));
    $e = $enviar($t, [[$datosQA['copa'], 2, $datosQA['pCopa']]], $clave('copas-2'));
    $ok('3.2', '2 × Copa: −300 ml del mismo saldo, lectura «4 piezas + 450 ml» (3450 ml)', $saldo(BARRA, $vino) === '3450.000' && str_contains($lectura(), '4 piezas + 450 ml'), $saldo(BARRA, $vino) . ' · ' . $lectura());
    $operar($R, 'restaurante', 'merma', ['almacen_id' => BARRA, 'articulo_id' => $vino, 'cantidad' => '2850', 'motivo' => 'derrame', 'clave' => 'qa-merma-vino-600']);
    $e = $enviar($t, [[$datosQA['botella'], 1, $datosQA['pBot']]], $clave('botella-sin'));
    $i3 = $e['items'][0] ?? 0; $f = $i3 ? $faltantesDe($i3) : [];
    $ok('3.3a', 'Botella con Barra en 600 ml: descuenta 600 y crea un faltante de 150 ml', ($e['json']['ok'] ?? false) === true && $saldo(BARRA, $vino) === '0.000' && count($f) === 1 && $f[0]['cantidad'] === '150.000', json_encode($f));
    $obs('3.3b', 'Reventa sin la botella completa', 'La venta no se bloquea: sale la botella con 600 ml y queda un faltante de 150 ml. Si el negocio espera bloquear la venta de una botella que no está, el contrato actual no lo hace (AP-6).');
    $sAntes = $saldo(BARRA, $vino);
    $cancelar($iB);
    $ok('3.4', 'Cancelar la botella de 3.1 regresa 750 ml a Barra', $saldo(BARRA, $vino) === Services\Inventory\DecimalInventario::formato(Services\Inventory\DecimalInventario::leer($sAntes) + 750000), $sAntes . '→' . $saldo(BARRA, $vino));
    $datosQA['ticket3'] = $t; $datosQA['item33'] = $i3;
    $integridad('Prueba 3');
});

$solicitar = static function (ClienteM3 $c, string $ctx, int $origen, int $destino, int $art, string $cant, string $motivo = 'QA M3 solicitud') use ($operar, $v): array {
    $antes = (int)$v('SELECT COALESCE(MAX(id),0) FROM transferencias');
    $o = $operar($c, $ctx, 'transferencia_solicitar', ['origen_id' => (string)$origen, 'destino_id' => (string)$destino, 'lineas' => [['articulo_id' => (string)$art, 'cantidad' => $cant]], 'motivo' => $motivo, 'clave' => 'qa-sol-' . bin2hex(random_bytes(8))]);
    return [$o, (int)$v('SELECT COALESCE(MAX(id),0) FROM transferencias WHERE id>? AND almacen_origen_id=? AND almacen_destino_id=?', [$antes, $origen, $destino])];
};
$estadoTr = static fn(int $t) => (string)($v('SELECT estado FROM transferencias WHERE id=?', [$t]) ?? '');

// ════════════════════════════════════════════════════════════════════════
// Prueba 4 — Transferencias, mermas y entradas
// ════════════════════════════════════════════════════════════════════════
if (in_array(4, $solo, true)) $seccion('Prueba 4 · Entradas, mermas y transferencias', function () use (&$datosQA, $G, $GO, $R, $L, $P, $PISO, $q, $v, $ok, $obs, $operar, $exito, $saldo, $movs, $transferencia, $solicitar, $estadoTr, $baseAlm, $alertas, $integridad, $abrir, $enviar, $faltantesDe, $conciliar, $clave) {
    ['vino' => $vino, 'fresa' => $fresa, 'pasta' => $pasta] = $datosQA;
    $D = Services\Inventory\DecimalInventario::class;
    $suma = static fn(string $a, int $b) => $D::formato($D::leer($a, 3, true) + $b);
    // 4.1 Entradas
    $s0 = $saldo(BARRA, $vino);
    $e = $operar($R, 'restaurante', 'entrada', ['almacen_id' => BARRA, 'articulo_id' => $vino, 'piezas' => '2', 'cantidad' => '100', 'clave' => 'qa-41-piezas']);
    $ok('4.1a', 'Entrada en piezas más resto: 2 piezas + 100 ml = 1600 ml', $exito($e) && $saldo(BARRA, $vino) === $suma($s0, 1600000), $s0 . '→' . $saldo(BARRA, $vino));
    $maestro = (string)$v('SELECT costo FROM ingredientes WHERE id=?', [$vino]);
    $operar($R, 'restaurante', 'entrada', ['almacen_id' => BARRA, 'articulo_id' => $vino, 'piezas' => '1', 'cantidad' => '', 'costo_unitario' => '300', 'costo_por' => 'pieza', 'clave' => 'qa-41-costo-pieza']);
    $cPieza = (string)$v("SELECT m.costo_unitario FROM movimientos_inventario m JOIN operaciones_inventario o ON o.id=m.operacion_id WHERE o.clave_idempotencia='qa-41-costo-pieza'");
    $operar($R, 'restaurante', 'entrada', ['almacen_id' => BARRA, 'articulo_id' => $vino, 'cantidad' => '10', 'costo_unitario' => '0.5', 'costo_por' => 'unidad', 'clave' => 'qa-41-costo-ml']);
    $cMl = (string)$v("SELECT m.costo_unitario FROM movimientos_inventario m JOIN operaciones_inventario o ON o.id=m.operacion_id WHERE o.clave_idempotencia='qa-41-costo-ml'");
    $ok('4.1b', 'Costo por pieza (300 / 750 ml = 0.4000) y por ml (0.5000) quedan en el movimiento; el maestro no cambia', $cPieza === '0.4000' && $cMl === '0.5000' && (string)$v('SELECT costo FROM ingredientes WHERE id=?', [$vino]) === $maestro, "pieza $cPieza · ml $cMl · maestro $maestro");
    $m1 = $movs(BARRA, $vino);
    $operar($R, 'restaurante', 'entrada', ['almacen_id' => BARRA, 'articulo_id' => $vino, 'cantidad' => '10', 'costo_unitario' => '0.5', 'costo_por' => 'unidad', 'clave' => 'qa-41-costo-ml']);
    $ok('4.1c', 'El mismo formulario dos veces (misma clave) no duplica', $movs(BARRA, $vino) === $m1, "movimientos {$m1}→" . $movs(BARRA, $vino));

    // 4.2 Mermas: cada motivo; mayor que el saldo; etiqueta «Faltante de inventario».
    $operar($R, 'restaurante', 'entrada', ['almacen_id' => COCINA, 'articulo_id' => $pasta, 'cantidad' => '100', 'clave' => 'qa-42-base']);
    $fallas = [];
    foreach (array_keys(Services\Inventory\Inventario::MOTIVOS_MERMA) as $mot) {
        $o = $operar($R, 'restaurante', 'merma', ['almacen_id' => COCINA, 'articulo_id' => $pasta, 'cantidad' => '1', 'motivo' => $mot, 'clave' => 'qa-42-' . $mot]);
        if (!$exito($o) || $v("SELECT m.motivo FROM movimientos_inventario m JOIN operaciones_inventario o ON o.id=m.operacion_id WHERE o.clave_idempotencia=?", ['qa-42-' . $mot]) !== $mot) $fallas[] = $mot . ': ' . $o[2];
    }
    $ok('4.2a', 'Merma con cada motivo de Inventario::MOTIVOS_MERMA', $fallas === [], $fallas ? implode('; ', $fallas) : implode(', ', array_keys(Services\Inventory\Inventario::MOTIVOS_MERMA)));
    $o = $operar($R, 'restaurante', 'merma', ['almacen_id' => COCINA, 'articulo_id' => $pasta, 'cantidad' => '999999', 'motivo' => 'otro', 'clave' => 'qa-42-excede']);
    $ok('4.2b', 'Una merma mayor que el saldo da SALDO_INSUFICIENTE', !$exito($o) && str_contains($o[2], 'saldo suficiente'), $o[2]);
    $obs('4.2c', 'AP-9: el motivo «Faltante de inventario» convive con los faltantes de venta', 'El selector de merma ofrece «' . Services\Inventory\Inventario::MOTIVOS_MERMA['faltante'] . '» y la bandeja nueva se llama «faltantes por conciliar»: dos conceptos distintos con el mismo nombre.');

    // 4.3 Transferencia en el mismo negocio: Cocina → Barra, 1 pieza de vino.
    $c0 = $saldo(COCINA, $vino); $b0 = $saldo(BARRA, $vino);
    [$o, $t] = $solicitar($R, 'restaurante', COCINA, BARRA, $vino, '750');
    $rec = $estadoTr($t) === 'recibida' && $saldo(COCINA, $vino) === $suma($c0, -750000) && $saldo(BARRA, $vino) === $suma($b0, 750000);
    $ok('4.3a', 'Cocina → Barra (mismo negocio): un solo paso, descuenta el origen y suma el destino', $rec, "transferencia $t · estado " . $estadoTr($t) . ' · ' . mb_substr($o[2], 0, 80));
    $hist = $q('SELECT estado_nuevo,actor_id,motivo FROM transferencia_historial WHERE transferencia_id=? ORDER BY id', [$t]);
    $ok('4.3b', 'El historial guarda actor y motivo del único paso', count($hist) === 1 && $hist[0]['estado_nuevo'] === 'recibida' && (int)$hist[0]['actor_id'] > 0 && trim((string)$hist[0]['motivo']) !== '', json_encode(array_map(static fn($h) => $h['estado_nuevo'] . ':' . $h['motivo'], $hist), JSON_UNESCAPED_UNICODE));
    $docs0 = (int)$v('SELECT COUNT(*) FROM transferencias');
    $nip = $PISO->post('/admin/almacenes/operacion', ['accion' => 'transferencia_solicitar', 'origen_id' => (string)COCINA, 'destino_id' => (string)BARRA, 'lineas' => [['articulo_id' => (string)$vino, 'cantidad' => '10']], 'clave' => 'qa-nip', 'motivo' => 'NIP']);
    $ok('4.3c', 'Con una sesión de NIP la transferencia se rechaza', in_array($nip['status'], [401, 403], true) && (int)$v('SELECT COUNT(*) FROM transferencias') === $docs0, 'HTTP ' . $nip['status']);

    // 4.4 Entre negocios: Central (Global) → Barra (Restaurante)
    $cv0 = $saldo(CENTRAL, $vino); $bv0 = $saldo(BARRA, $vino);
    [$o, $tx] = $solicitar($R, 'restaurante', CENTRAL, BARRA, $vino, '750');
    $ajeno = $transferencia($R, 'restaurante', 'enviar', $tx);
    $ok('4.4a', 'Sólo el dueño del origen envía: Restaurante recibe AMBITO_DENEGADO', !$exito($ajeno) && $estadoTr($tx) === 'solicitada', mb_substr($ajeno[2], 0, 140));
    $envAjeno = $ajeno;
    $transferencia($GO, 'global', 'enviar', $tx);
    $recibir = $transferencia($R, 'restaurante', 'recibir', $tx, ['lineas' => [['articulo_id' => (string)$vino, 'cantidad' => '500']], 'motivo' => 'Llegaron 500 ml']);
    $inc = $estadoTr($tx);
    $ok('4.4b', 'Recepción parcial deja «incidencia» con motivo', !$exito($envAjeno) && $inc === 'incidencia' && $saldo(BARRA, $vino) === $suma($bv0, 500000) && $saldo(CENTRAL, $vino) === $suma($cv0, -750000), "estado $inc · Barra +" . $D::formato($D::leer($saldo(BARRA, $vino)) - $D::leer($bv0)) . ' · envío por el destino: ' . mb_substr($envAjeno[2], 0, 60));
    $ret = $transferencia($GO, 'global', 'retornar', $tx, ['lineas' => [['articulo_id' => (string)$vino, 'cantidad' => '250']], 'motivo' => 'Regresan 250 ml']);
    $ok('4.4c', 'El retorno desde el origen cierra la incidencia', $estadoTr($tx) === 'cerrada' && $saldo(CENTRAL, $vino) === $suma($cv0, -500000), 'estado ' . $estadoTr($tx) . ' · Central ' . $cv0 . '→' . $saldo(CENTRAL, $vino) . ' · ' . mb_substr($ret[2], 0, 80));
    $mv0 = (int)$v('SELECT COUNT(*) FROM movimientos_inventario');
    [, $tr] = $solicitar($R, 'restaurante', CENTRAL, BARRA, $vino, '100'); $rr = $transferencia($GO, 'global', 'rechazar', $tr);
    [, $tc] = $solicitar($R, 'restaurante', CENTRAL, BARRA, $vino, '100'); $cc = $transferencia($R, 'restaurante', 'cancelar', $tc);
    $ok('4.4d', 'Rechazar o cancelar antes de enviar no genera movimientos', $estadoTr($tr) === 'rechazada' && $estadoTr($tc) === 'cancelada' && (int)$v('SELECT COUNT(*) FROM movimientos_inventario') === $mv0, "rechazada {$estadoTr($tr)} · cancelada {$estadoTr($tc)} · " . mb_substr($cc[2], 0, 60));
    $mv1 = (int)$v('SELECT COUNT(*) FROM movimientos_inventario');
    [, $tm] = $solicitar($R, 'restaurante', CENTRAL, BARRA, $vino, '999999'); $em = $transferencia($GO, 'global', 'enviar', $tm);
    $ok('4.4e', 'Enviar más que el saldo se rechaza (sin movimientos)', !$exito($em) && $estadoTr($tm) === 'solicitada' && (int)$v('SELECT COUNT(*) FROM movimientos_inventario') === $mv1, 'enviar: ' . mb_substr($em[2], 0, 100) . ' · estado ' . $estadoTr($tm));
    $transferencia($GO, 'global', 'cancelar', $tm);
    [, $tn] = $solicitar($R, 'restaurante', CENTRAL, BARRA, $vino, '500');
    $operar($GO, 'global', 'merma', ['almacen_id' => CENTRAL, 'articulo_id' => $vino, 'cantidad' => $D::formato($D::leer($saldo(CENTRAL, $vino)) - 100000), 'motivo' => 'otro', 'clave' => 'qa-44-consumir']);
    $en = $transferencia($GO, 'global', 'enviar', $tn);
    $ok('4.4f', 'No hay reserva: solicitada, otra operación consume el origen y el envío se rechaza', !$exito($en) && $estadoTr($tn) === 'solicitada' && $saldo(CENTRAL, $vino) === '100.000', 'Central ' . $saldo(CENTRAL, $vino) . ' · ' . mb_substr($en[2], 0, 120));
    $transferencia($GO, 'global', 'cancelar', $tn);
    // Registrada por el dueño del origen: nace enviada y el destino sólo recibe.
    [$og, $tg] = $solicitar($GO, 'global', CENTRAL, BARRA, $vino, '50');
    $env = $estadoTr($tg) === 'enviada' && $saldo(CENTRAL, $vino) === '50.000';
    $transferencia($R, 'restaurante', 'recibir', $tg, ['lineas' => [['articulo_id' => (string)$vino, 'cantidad' => '50']]]);
    $ok('4.4g', 'Central → Barra registrada por Global: nace enviada y Barra la recibe', $env && $estadoTr($tg) === 'recibida', "transferencia $tg · estado " . $estadoTr($tg) . ' · Central ' . $saldo(CENTRAL, $vino) . ' · ' . mb_substr($og[2], 0, 80));

    // 4.5 Regularizaciones: sólo confirmar/rechazar y sólo el dueño del origen.
    $tk = $abrir('QA M3 Prueba 4');
    if ($saldo(BARRA, $fresa) !== '0.000') $operar($R, 'restaurante', 'merma', ['almacen_id' => BARRA, 'articulo_id' => $fresa, 'cantidad' => $saldo(BARRA, $fresa), 'motivo' => 'otro', 'clave' => 'qa-45-vaciar']);
    $e = $enviar($tk, [[$datosQA['copaFresas'], 1, $datosQA['pFresas']]], $clave('reg-45'));
    $fr = $faltantesDe($e['items'][0] ?? 0)[0] ?? [];
    $conciliar($R, (int)($fr['id'] ?? 0), [[CENTRAL, $fr['cantidad'] ?? '5']]);
    $treg = (int)$v("SELECT id FROM transferencias WHERE tipo='regularizacion' AND estado='solicitada' ORDER BY id DESC LIMIT 1");
    $acciones = static function (ClienteM3 $c, string $ctx, int $t) use ($baseAlm): array { $x = ClienteInstalacion3E::dom($c->get($baseAlm($ctx) . '?vista=transferencias')['body']); $out = []; foreach ($x->query('//form[.//input[@name="transferencia_id" and @value="' . $t . '"]]//input[@name="accion"]') as $i) $out[] = substr($i->getAttribute('value'), 14); return $out; };
    $aG = $acciones($GO, 'global', $treg); $aR = $acciones($R, 'restaurante', $treg);
    sort($aG);
    $ok('4.5', 'Regularización: el dueño del origen sólo ve confirmar y rechazar; el destino, ninguna acción', $treg > 0 && $aG === ['confirmar', 'rechazar'] && $aR === [], "transferencia $treg · Global " . json_encode($aG) . ' · Restaurante ' . json_encode($aR));
    $transferencia($GO, 'global', 'rechazar', $treg, ['motivo' => 'QA M3 cierre de 4.5']);

    // 4.6 Permisos
    $pag = $L->get('/admin/almacenes');
    $botones = ClienteInstalacion3E::dom($pag['body'])->query('//button[@data-admin-modal-open[contains(.,"-entrada") or contains(.,"-merma") or contains(.,"-ajuste")]]')->length;
    $tokL = preg_match('/name="admin_csrf"\s+value="([^"]+)"/', $pag['body'], $mm) ? html_entity_decode($mm[1]) : '';
    $pl = $L->post('/admin/almacenes/operacion', ['admin_csrf' => $tokL, 'accion' => 'entrada', 'almacen_id' => (string)BARRA, 'articulo_id' => (string)$vino, 'cantidad' => '1', 'clave' => 'qa-46-lectura', 'volver_vista' => 'existencias']);
    $ok('4.6a', 'lectura_restaurante no ve botones de operación y su POST da 403', $botones === 0 && $pl['status'] === 403, "botones $botones · HTTP " . $pl['status']);
    $central0 = $saldo(CENTRAL, $vino);
    $aj = $operar($R, 'restaurante', 'entrada', ['almacen_id' => (string)CENTRAL, 'articulo_id' => (string)$vino, 'cantidad' => '1', 'clave' => 'qa-46-ajeno']);
    $aa = $operar($R, 'restaurante', 'entrada', ['almacen_id' => (string)BARRA, 'articulo_id' => '999999', 'cantidad' => '1', 'clave' => 'qa-46-art']);
    $ok('4.6b', 'Un almacen_id o articulo_id ajeno se rechaza', !$exito($aj) && !$exito($aa) && $saldo(CENTRAL, $vino) === $central0, mb_substr($aj[2], 0, 80) . ' · ' . mb_substr($aa[2], 0, 80));
    $sin = $R->post('/admin/almacenes/operacion', ['accion' => 'entrada', 'almacen_id' => (string)BARRA, 'articulo_id' => (string)$vino, 'cantidad' => '1', 'clave' => 'qa-46-csrf', 'volver_vista' => 'existencias']);
    // Editor de receta anterior: un cuerpo inválido sin token revela si se valida CSRF antes que los datos.
    $ofLeg = (int)$v('SELECT o.id FROM ofertas_producto o JOIN recetas r ON r.oferta_id=o.id AND r.activo=1 WHERE o.unidad_id=2 AND NOT EXISTS (SELECT 1 FROM oferta_presentaciones p WHERE p.oferta_id=o.id) AND NOT EXISTS (SELECT 1 FROM receta_componentes rc WHERE rc.receta_id=r.id AND rc.subreceta_id IS NOT NULL) ORDER BY o.id LIMIT 1');
    // Cuerpo inválido: no cambia la receta; sólo revela si el CSRF se valida antes que los datos.
    $leg = $R->postCrudo('/admin/recetas/editar?id=' . $ofLeg, 'comp%5B%5D=no-valido&comp_cant%5B%5D=1');
    $ok('4.6d', 'El editor de receta anterior rechaza un POST sin CSRF', in_array($leg['status'], [403, 419], true), 'oferta ' . $ofLeg . ' · HTTP ' . $leg['status'] . ' · ' . mb_substr($alertas($leg['body']), 0, 140) . (in_array($leg['status'], [403, 419], true) ? '' : ' (sin token llegó a validar los datos)'));
    // Regresión A-1: los componentes vigentes, reenviados sin token, no crean versión; con el token del formulario, sí.
    $compLeg = $q('SELECT rc.articulo_id, rc.cantidad FROM receta_componentes rc JOIN recetas r ON r.id=rc.receta_id WHERE r.oferta_id=? AND r.activo=1 ORDER BY rc.id', [$ofLeg]);
    $cuerpoLeg = implode('&', array_map(static fn($c) => 'comp%5B%5D=' . rawurlencode('ingrediente:' . $c['articulo_id']) . '&comp_cant%5B%5D=' . rawurlencode((string)$c['cantidad']), $compLeg));
    $verLeg = static fn() => (int)$v('SELECT MAX(version) FROM recetas WHERE oferta_id=?', [$ofLeg]);
    $antes = $verLeg();
    $sinTok = $R->postCrudo('/admin/recetas/editar?id=' . $ofLeg, $cuerpoLeg);
    $trasSin = $verLeg();
    $formLeg = $R->get('/admin/recetas/editar?id=' . $ofLeg);
    $tokLeg = preg_match('/<form method="POST" data-recipe-builder>\s*<input type="hidden" name="admin_csrf" value="([^"]+)"/', $formLeg['body'], $mm) ? html_entity_decode($mm[1]) : '';
    $conTok = $R->postCrudo('/admin/recetas/editar?id=' . $ofLeg, 'admin_csrf=' . rawurlencode($tokLeg) . '&' . $cuerpoLeg);
    $trasCon = $verLeg();
    $ok('4.6e', 'Sin token la receta anterior no cambia; con token se guarda', $compLeg && $sinTok['status'] === 403 && $trasSin === $antes && $tokLeg !== '' && $conTok['status'] < 400 && $trasCon === $antes + 1, count($compLeg) . " componentes · sin token HTTP {$sinTok['status']}, versión {$antes}→{$trasSin} · con token HTTP {$conTok['status']}, versión {$trasSin}→{$trasCon}");
    $ok('4.6c', 'Un POST sin CSRF se rechaza', $sin['status'] === 403 && (int)$v("SELECT COUNT(*) FROM operaciones_inventario WHERE clave_idempotencia='qa-46-csrf'") === 0, 'HTTP ' . $sin['status']);
    $integridad('Prueba 4');
});

// ════════════════════════════════════════════════════════════════════════
// Prueba 5 — «Conteo físico» retirado (auditoría A-4)
// ════════════════════════════════════════════════════════════════════════
// El conteo sobrescribía el saldo sin motivo (dictamen de la corrida anterior). Se
// retiró: ningún camino de la interfaz ni del servidor crea ajustes, y una diferencia
// real se registra como merma con motivo o como entrada.
if (in_array(5, $solo, true)) $seccion('Prueba 5 · Conteo físico retirado', function () use (&$datosQA, $R, $q, $v, $ok, $obs, $operar, $exito, $saldo, $movs, $baseAlm, $integridad) {
    ['pasta' => $pasta] = $datosQA;
    $D = Services\Inventory\DecimalInventario::class;
    $pagina = $R->get($baseAlm('restaurante') . '?q=' . rawurlencode('QA M3'))['body'];
    $x = ClienteInstalacion3E::dom($pagina);
    $ok('5.1a', 'Almacenes no ofrece «Conteo físico» ni su formulario', !str_contains($pagina, 'Conteo físico') && $x->query('//input[@name="accion" and @value="ajuste"]')->length === 0 && $x->query('//*[contains(@data-admin-modal-open,"-ajuste")]')->length === 0, 'formularios ajuste: ' . $x->query('//input[@name="accion" and @value="ajuste"]')->length);
    $s = $saldo(COCINA, $pasta); $n = $movs(COCINA, $pasta);
    $forzado = $operar($R, 'restaurante', 'ajuste', ['almacen_id' => (string)COCINA, 'articulo_id' => (string)$pasta, 'cantidad' => '1', 'version' => (string)$v('SELECT version FROM almacen_articulos WHERE almacen_id=? AND articulo_id=?', [COCINA, $pasta]), 'clave' => 'qa-5-forzado']);
    $ok('5.1b', 'Un POST de conteo forzado se rechaza sin tocar el saldo', !$exito($forzado) && $saldo(COCINA, $pasta) === $s && $movs(COCINA, $pasta) === $n, mb_substr($forzado[2], 0, 120));
    $legado = $R->post('/admin/inventario/ajustar', ['id' => (string)$pasta, 'almacen_id' => (string)COCINA, 'stock' => '1']);
    $ok('5.1c', 'La ruta antigua /admin/inventario/ajustar ya no existe', in_array($legado['status'], [403, 404], true) && $saldo(COCINA, $pasta) === $s && $movs(COCINA, $pasta) === $n, 'HTTP ' . $legado['status']);
    $operar($R, 'restaurante', 'entrada', ['almacen_id' => COCINA, 'articulo_id' => $pasta, 'cantidad' => '20', 'clave' => 'qa-5-base']);
    $m = $operar($R, 'restaurante', 'merma', ['almacen_id' => COCINA, 'articulo_id' => $pasta, 'cantidad' => '5', 'motivo' => 'otro', 'nota' => 'Diferencia de inventario', 'clave' => 'qa-5-diferencia']);
    $ult = $q('SELECT tipo,delta,motivo,nota FROM movimientos_inventario WHERE almacen_id=? AND articulo_id=? ORDER BY id DESC LIMIT 1', [COCINA, $pasta])[0] ?? [];
    $ok('5.1d', 'Una diferencia de inventario queda como merma con motivo y nota', $exito($m) && ($ult['tipo'] ?? '') === 'merma' && ($ult['motivo'] ?? '') === 'otro' && ($ult['nota'] ?? '') === 'Diferencia de inventario', json_encode($ult, JSON_UNESCAPED_UNICODE));
    $historicos = (int)$v("SELECT COUNT(*) FROM movimientos_inventario m JOIN almacenes a ON a.id=m.almacen_id JOIN unidades u ON u.id=a.unidad_id WHERE m.tipo='ajuste' AND u.slug='restaurante'");
    if ($historicos > 0) {
        $mov = $R->get($baseAlm('restaurante') . '?vista=movimientos&tipo=ajuste')['body'];
        $ok('5.1e', 'Los ajustes anteriores siguen visibles como «ajuste (histórico)»', str_contains($mov, 'ajuste (histórico)'), "$historicos ajustes en Restaurante");
    } else {
        $obs('5.1e', 'Ajustes históricos visibles', 'El fixture no trae movimientos de ajuste en Restaurante; no se pudo comprobar el rótulo «ajuste (histórico)».');
    }
    $integridad('Prueba 5');
});

// ════════════════════════════════════════════════════════════════════════
// Prueba 6 — «Catálogo compartido»
// ════════════════════════════════════════════════════════════════════════
if (in_array(6, $solo, true)) $seccion('Prueba 6 · Catálogo compartido', function () use (&$datosQA, $G, $GO, $R, $q, $v, $ok, $obs, $crearArticulo, $capacidades, $baseArt, $campos, $cuerpo, $alertas, $texto, $saldo, $movs, $operar, $exito, $solicitar, $transferencia) {
    ['vino' => $vino, 'fresa' => $fresa, 'salsa' => $salsa] = $datosQA;
    // 6.1 Dos entradas al mismo catálogo
    $a = $R->get('/admin/almacenes/catalogo'); $b = $R->get('/admin/recetas/articulos');
    $rasgos = static function (string $html) use ($texto): array { $x = ClienteInstalacion3E::dom($html); $t = $texto($html); return ['catalogo_compartido' => str_contains($t, 'Catálogo compartido'), 'alta' => $x->query('//a[contains(@href,"/catalogo/nuevo")]')->length > 0, 'ficha' => $x->query('//a[contains(@href,"/editar?id=")]')->length > 0, 'vincular' => str_contains($t, 'Preparaciones anteriores por vincular') || $x->query('//*[@name="subreceta_id"]')->length > 0]; };
    $ra = $rasgos($a['body']); $rb = $rasgos($b['body']);
    $obs('6.1', 'Dos entradas al catálogo (AP-10)', 'Almacenes /admin/almacenes/catalogo (HTTP ' . $a['status'] . '): ' . json_encode($ra) . ' · Recetas /admin/recetas/articulos (HTTP ' . $b['status'] . '): ' . json_encode($rb));
    // 6.2 Lista: búsqueda con comodines escapados, filtro de estado, páginas de 30 y filtros conservados.
    $crearArticulo($G, ['nombre' => 'QA M3 100% natural', 'unidad' => 'g', 'contenido_pieza' => '', 'costo' => '0', 'activo' => '1']);
    $crearArticulo($G, ['nombre' => 'QA M3 sal_fina!', 'unidad' => 'g', 'contenido_pieza' => '', 'costo' => '0', 'activo' => '1']);
    $filas = static fn(string $u) => array_values(array_unique(array_map(static fn($n) => trim($n->textContent), iterator_to_array(ClienteInstalacion3E::dom($R->get($u)['body'])->query('//table//tbody//tr//*[self::strong or self::a][contains(.,"QA M3") or contains(.,"")][1]')))));
    $busca = static fn(string $q) => array_map(static fn($n) => trim(preg_replace('/\s+/', ' ', $n->textContent)), iterator_to_array(ClienteInstalacion3E::dom($R->get('/admin/almacenes/catalogo?q=' . rawurlencode($q))['body'])->query('//tbody/tr/td[1]')));
    $pct = $busca('%'); $und = $busca('_'); $exc = $busca('!');
    $soloCon = static fn(array $r, string $c) => $r !== [] && count(array_filter($r, static fn($x) => !str_contains($x, $c))) === 0;
    $ok('6.2a', 'La búsqueda escapa % _ y ! (cada una sólo encuentra nombres que la contienen)', $soloCon($pct, '%') && $soloCon($und, '_') && $soloCon($exc, '!'), '% → ' . count($pct) . ' · _ → ' . count($und) . ' · ! → ' . count($exc));
    $total = (int)$v('SELECT COUNT(*) FROM ingredientes');
    $p1 = count($busca(''));
    $ok('6.2b', 'Paginación de 30 y Restaurante ve todo el maestro (incluidos artículos sin asociación propia)', $p1 === min(30, $total) && str_contains($texto($R->get('/admin/almacenes/catalogo?q=' . rawurlencode('QA M3 100%'))['body']), 'QA M3 100% natural'), "maestro $total · primera página $p1");
    $inact = $R->get('/admin/almacenes/catalogo?estado=inactivos');
    $ok('6.2c', 'Filtro por estado', $inact['status'] === 200, 'HTTP ' . $inact['status'] . ' · ' . count(ClienteInstalacion3E::dom($inact['body'])->query('//tbody/tr')) . ' filas inactivas');
    $volver = ClienteInstalacion3E::dom($R->get('/admin/almacenes/catalogo?q=QA&pagina=1')['body'])->query('//a[contains(@href,"editar?id=") and contains(@href,"volver=")]')->length;
    $ok('6.2d', 'La ficha conserva los filtros para volver', $volver > 0, "$volver enlaces con volver=");
    // 6.3 Alta y edición
    $ficha = static fn(ClienteM3 $c, string $base, int $id) => $c->get($base . '/catalogo/editar?id=' . $id);
    $fr = $ficha($R, '/admin/almacenes', $vino);
    $postR = $R->postCrudo('/admin/almacenes/catalogo/editar?id=' . $vino, $cuerpo($campos($fr['body'], '//form[.//input[@name="m2_csrf"]]') ?: [], ['nombre' => 'QA M3 Vino renombrado']));
    $ok('6.3a', 'La identidad sólo se edita desde Global: Restaurante recibe 403', in_array($postR['status'], [403, 405], true) && (string)$v('SELECT nombre FROM ingredientes WHERE id=?', [$vino]) === 'QA M3 Vino tinto', 'HTTP ' . $postR['status']);
    $editarG = static function (array $sobre, int $id) use ($G, $campos, $cuerpo) { $p = $G->get('/admin/global/almacenes/catalogo/editar?id=' . $id); return $G->postCrudo('/admin/global/almacenes/catalogo/editar?id=' . $id, $cuerpo($campos($p['body'], '//form[.//input[@name="m2_csrf"] and .//input[@name="nombre"]]'), $sobre)); };
    $u = $editarG(['unidad' => 'g', 'contenido_pieza' => ''], $vino);
    $ok('6.3b', 'No se cambia la unidad de un artículo con historial', $u['status'] === 422 && (string)$v('SELECT unidad FROM ingredientes WHERE id=?', [$vino]) === 'ml', 'HTTP ' . $u['status'] . ' · ' . mb_substr($alertas($u['body']), 0, 140));
    $i = $editarG(['activo' => null], $vino);
    $ok('6.3c', 'No se inactiva un artículo con saldo', $i['status'] === 422 && (int)$v('SELECT activo FROM ingredientes WHERE id=?', [$vino]) === 1, 'HTTP ' . $i['status'] . ' · ' . mb_substr($alertas($i['body']), 0, 140));
    [, $tAb] = $solicitar($R, 'restaurante', REFRI, BARRA, $fresa, '1');
    $operar($R, 'restaurante', 'merma', ['almacen_id' => BARRA, 'articulo_id' => $fresa, 'cantidad' => $saldo(BARRA, $fresa), 'motivo' => 'otro', 'clave' => 'qa-63-vaciar-b']);
    $obs('6.3d', 'Inactivar con transferencias abiertas', 'Con la transferencia ' . $tAb . ' abierta (fresas, que conserva saldo en otros almacenes) la inactivación ' . (($ii = $editarG(['activo' => null], $fresa))['status'] === 422 ? 'se rechaza: ' . mb_substr($alertas($ii['body']), 0, 120) : 'no se rechazó (HTTP ' . $ii['status'] . ')') . '. No se aisló la causa entre saldo y transferencia.');
    $transferencia($R, 'restaurante', 'cancelar', $tAb);
    $s = $saldo(BARRA, $vino); $n = $movs(BARRA, $vino);
    $cp = $editarG(['contenido_pieza' => '700'], $vino);
    $ok('6.3e', 'Cambiar el contenido por pieza no altera saldo ni movimientos', $saldo(BARRA, $vino) === $s && $movs(BARRA, $vino) === $n, 'HTTP ' . $cp['status'] . ' · contenido ' . $v('SELECT contenido_pieza FROM ingredientes WHERE id=?', [$vino]));
    $editarG(['contenido_pieza' => '750'], $vino);
    // 6.4 Etiqueta de producción previa
    $t = $texto($R->get('/admin/recetas/articulos?q=' . rawurlencode('QA M3 Salsa'))['body']);
    $obs('6.4', 'AP-8: etiqueta de producción previa en «Configuración del negocio»', str_contains($t, 'Existencias previas requeridas') ? 'Sigue diciendo «Existencias previas requeridas», aunque desde M3 la venta no exige existencias previas.' : 'No aparece la etiqueta: ' . mb_substr($t, (int)mb_strpos($t, 'QA M3 Salsa'), 160));
    // 6.5 Ficha: dependencia de producción previa, versiones y comparación
    $art = $crearArticulo($G, ['nombre' => 'QA M3 Sin elaboración', 'unidad' => 'g', 'contenido_pieza' => '', 'costo' => '0', 'activo' => '1']);
    $sinE = (int)$v('SELECT id FROM ingredientes WHERE nombre=?', ['QA M3 Sin elaboración']);
    [$rc, $al] = $capacidades($R, 'restaurante', $sinE, ['componente', 'produccion_previa', 'activo']);
    $ok('6.5a', 'Producción previa exige elaboración y almacenamiento', $rc['status'] === 422 && (int)($q('SELECT produccion_previa FROM articulo_configuraciones WHERE articulo_id=? AND unidad_id=2', [$sinE])[0]['produccion_previa'] ?? 0) === 0, 'HTTP ' . $rc['status'] . ' · ' . mb_substr($al ?: $alertas($rc['body']), 0, 140));
    $vs = (int)$v('SELECT id FROM articulo_recetas WHERE articulo_id=? AND unidad_id=2 ORDER BY version LIMIT 1', [$salsa]);
    $verV = $R->get($baseArt('restaurante') . '/editar?id=' . $salsa . '&version=' . $vs);
    $cmp = $R->get($baseArt('restaurante') . '/editar?id=' . $salsa . '&version=' . $vs . '&comparar=' . $vs);
    $ok('6.5b', 'Versiones publicadas: consulta y comparación', $verV['status'] === 200 && $cmp['status'] === 200, "versión HTTP {$verV['status']} · comparar HTTP {$cmp['status']}");
    // 6.6 Redirecciones
    $h1 = $G->get('/admin/global/catalogo'); $h2 = $h1['location'] ? $G->get($h1['location']) : ['status' => 0, 'location' => ''];
    $ok('6.6', 'Redirecciones /admin/global/catalogo → ?vista=catalogo → /catalogo', $h1['status'] === 302 && str_contains($h1['location'], 'vista=catalogo') && $h2['status'] === 302 && str_ends_with(parse_url($h2['location'], PHP_URL_PATH) ?? '', '/admin/global/almacenes/catalogo'), $h1['status'] . ' ' . $h1['location'] . ' → ' . $h2['status'] . ' ' . $h2['location'] . ' (doble redirección)');
});

// ── Cierre ──────────────────────────────────────────────────────────────
foreach ($clientes as $c) $c->close();
$resumen = array_count_values(array_column($casos, 'estado'));
fwrite(STDOUT, "\nResumen: " . json_encode($resumen, JSON_UNESCAPED_UNICODE) . "\n");
if (isset($op['resultados'])) file_put_contents($op['resultados'], json_encode(['casos' => $casos, 'datos' => $datosQA, 'resumen' => $resumen], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
exit(($resumen['FAIL'] ?? 0) > 0 ? 1 : 0);
