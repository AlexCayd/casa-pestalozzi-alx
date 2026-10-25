<?php
declare(strict_types=1);

/** Recorridos HTTP locales contra servir-multinegocio-3b.php. No conecta a BD. */
if (PHP_SAPI !== 'cli') { exit(1); }

function http3bAssert(bool $condition, string $message, int &$count): void
{
    if (!$condition) throw new RuntimeException($message);
    $count++;
}

function http3bOpciones(array $args): array
{
    $options = [];
    for ($i = 0; $i < count($args); $i++) {
        if (!preg_match('/^--(url|super-user|super-password|nip-user|nip|reader-user|reader-password)$/D', $args[$i], $m)
            || !isset($args[$i + 1]) || str_starts_with($args[$i + 1], '--') || isset($options[$m[1]])) {
            throw new RuntimeException('Uso: --url http://127.0.0.1:8091 --super-user U --super-password P --nip-user U --nip 0000 --reader-user U --reader-password P');
        }
        $options[$m[1]] = $args[++$i];
    }
    foreach (['url','super-user','super-password','nip-user','nip','reader-user','reader-password'] as $key) {
        if (!isset($options[$key]) || $options[$key] === '') throw new RuntimeException("Falta --$key.");
    }
    $url = parse_url($options['url']);
    if (!is_array($url) || ($url['scheme'] ?? '') !== 'http' || ($url['host'] ?? '') !== '127.0.0.1'
        || (int)($url['port'] ?? 80) !== 8091 || isset($url['user']) || isset($url['pass'])
        || !in_array($url['path'] ?? '', ['', '/'], true) || isset($url['query']) || isset($url['fragment'])) {
        throw new RuntimeException('El runner solo acepta http://127.0.0.1:8091.');
    }
    if (!preg_match('/^\d{4}$/D', $options['nip'])) throw new RuntimeException('--nip debe tener cuatro dígitos.');
    return $options;
}

final class HttpCliente3B
{
    private CurlHandle $handle;
    private string $base;

    public function __construct(string $base)
    {
        $this->base = rtrim($base, '/');
        $handle = curl_init();
        if (!$handle) throw new RuntimeException('No se pudo crear el cliente cURL.');
        $this->handle = $handle;
        curl_setopt_array($this->handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_COOKIEFILE => '',
            CURLOPT_USERAGENT => 'Casa-Pestalozzi-3B-Isolated-HTTP-Runner/1.0',
        ]);
    }

    /** @return array{status:int,headers:string,body:string,location:string} */
    public function request(string $method, string $path, array $data = []): array
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) throw new RuntimeException('Ruta HTTP no local.');
        curl_setopt($this->handle, CURLOPT_URL, $this->base . $path);
        curl_setopt($this->handle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($this->handle, CURLOPT_POSTFIELDS, $method === 'POST' ? http_build_query($data) : null);
        curl_setopt($this->handle, CURLOPT_HTTPHEADER, $method === 'POST' ? ['Content-Type: application/x-www-form-urlencoded'] : []);
        $raw = curl_exec($this->handle);
        if (!is_string($raw)) throw new RuntimeException('Falló la petición HTTP local: ' . curl_error($this->handle));
        $headerSize = (int)curl_getinfo($this->handle, CURLINFO_HEADER_SIZE);
        $headers = substr($raw, 0, $headerSize);
        $location = '';
        foreach (preg_split('/\r?\n/', $headers) as $line) {
            if (stripos($line, 'Location:') === 0) $location = trim(substr($line, strlen('Location:')));
        }
        return [
            'status' => (int)curl_getinfo($this->handle, CURLINFO_RESPONSE_CODE),
            'headers' => $headers,
            'body' => substr($raw, $headerSize),
            'location' => $location,
        ];
    }

    public function close(): void { curl_close($this->handle); }
}

function http3bLogin(HttpCliente3B $client, string $path, array $data, int &$count, string $label): array
{
    $form = $client->request('GET', '/login');
    if (!preg_match('/name="login_csrf"\s+value="([^"]+)"/', $form['body'], $match)) throw new RuntimeException('El formulario de login no entregó token CSRF.');
    $data['login_csrf'] = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $response = $client->request('POST', $path, $data);
    http3bAssert(in_array($response['status'], [302,303], true), "$label no redirigió tras autenticar.", $count);
    return $response;
}

function http3bCsrf(string $html): string
{
    if (!preg_match('/name="admin_csrf"\s+value="([^"]+)"/', $html, $match)) throw new RuntimeException('No apareció el token CSRF en el formulario.');
    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

$clients = [];
$count = 0;
$phase = 'argumentos';
$failure = null;
try {
    if (!extension_loaded('curl')) throw new RuntimeException('El runner HTTP requiere la extensión cURL de PHP.');
    $options = http3bOpciones(array_slice($argv, 1));
    $base = rtrim($options['url'], '/');
    $super = new HttpCliente3B($base); $clients[] = $super;
    $phase = 'login por contraseña y contexto';
    $login = http3bLogin($super, '/admin/login', ['username' => $options['super-user'], 'password' => $options['super-password']], $count, 'Login de superadministrador');
    http3bAssert(str_starts_with($login['location'], '/admin/'), 'El login por contraseña no llevó al panel.', $count);
    $globalList = $super->request('GET', '/admin/global/usuarios');
    http3bAssert($globalList['status'] === 200 && str_contains($globalList['body'], 'Usuarios'), 'Global no abrió el listado de Usuarios.', $count);
    $restList = $super->request('GET', '/admin/usuarios');
    http3bAssert($restList['status'] === 200 && str_contains($restList['body'], 'Restaurante'), 'El superadministrador no cambió al contexto Restaurante.', $count);

    $phase = 'creación y edición por formulario';
    $createPage = $super->request('GET', '/admin/global/usuarios/create');
    http3bAssert($createPage['status'] === 200 && str_contains($createPage['body'], 'data-user-direct-form'), 'El formulario real de creación no cargó.', $count);
    $csrf = http3bCsrf($createPage['body']);
    $suffix = bin2hex(random_bytes(3));
    $userName = 'web3b_' . $suffix;
    $newPassword = 'PruebaWeb3B1';
    $createData = [
        'admin_csrf' => $csrf,
        'username' => $userName,
        'nombre' => 'Cuenta HTTP 3B',
        'activo' => '1',
        'password' => $newPassword,
        'password_confirm' => $newPassword,
        'ambitos' => [
            'global' => ['activo' => '1', 'permisos' => ['users' => 'ver', 'menu' => 'ver'], 'facultades' => []],
        ],
    ];
    $created = $super->request('POST', '/admin/global/usuarios/create', $createData);
    http3bAssert(in_array($created['status'], [302,303], true) && str_contains($created['location'], '/edit?id='), 'El envío del formulario no creó y abrió el usuario.', $count);
    if (!preg_match('/[?&]id=(\d+)/', $created['location'], $idMatch)) throw new RuntimeException('No se encontró el ID del usuario creado en la redirección.');
    $userId = (int)$idMatch[1];

    $editPage = $super->request('GET', '/admin/global/usuarios/edit?id=' . $userId);
    http3bAssert($editPage['status'] === 200 && str_contains($editPage['body'], $userName), 'El formulario real de edición no cargó el usuario creado.', $count);
    $editToken = http3bCsrf($editPage['body']);
    $edited = $super->request('POST', '/admin/global/usuarios/edit?id=' . $userId, [
        'admin_csrf' => $editToken,
        'id' => (string)$userId,
        'username' => $userName,
        'nombre' => 'Cuenta HTTP 3B editada',
        'activo' => '1',
        'ambitos' => [
            'global' => ['activo' => '1', 'permisos' => ['users' => 'ver', 'menu' => 'editar'], 'facultades' => []],
        ],
    ]);
    http3bAssert(in_array($edited['status'], [302,303], true), 'La edición del permiso de menú no guardó.', $count);
    $editedPage = $super->request('GET', '/admin/global/usuarios/edit?id=' . $userId);
    http3bAssert($editedPage['status'] === 200 && str_contains($editedPage['body'], 'Cuenta HTTP 3B editada'), 'La edición de identidad no persistió.', $count);

    $invalidPage = $super->request('GET', '/admin/global/usuarios/create');
    $invalidToken = http3bCsrf($invalidPage['body']);
    $invalidName = 'webbad_' . bin2hex(random_bytes(3));
    $invalid = $super->request('POST', '/admin/global/usuarios/create', [
        'admin_csrf' => $invalidToken,
        'username' => $invalidName,
        'nombre' => 'Cuenta inválida 3B',
        'ambitos' => ['global' => ['activo' => '1', 'permisos' => ['menu' => 'ver'], 'facultades' => []]],
    ]);
    http3bAssert($invalid['status'] === 200 && str_contains(mb_strtolower($invalid['body']), 'contraseña'), 'La validación devuelve el formulario con error claro.', $count);
    $listAfterInvalid = $super->request('GET', '/admin/global/usuarios');
    http3bAssert(!str_contains($listAfterInvalid['body'], $invalidName), 'La validación fallida no creó una identidad.', $count);

    $csrfRejected = $super->request('POST', '/admin/global/usuarios/create', [
        'username' => 'webcsrf_' . bin2hex(random_bytes(3)), 'nombre' => 'CSRF 3B', 'password' => 'ValidPass3B', 'password_confirm' => 'ValidPass3B',
        'ambitos' => ['global' => ['activo' => '1', 'permisos' => ['menu' => 'ver'], 'facultades' => []]],
    ]);
    http3bAssert(in_array($csrfRejected['status'], [302,303], true) && str_contains($csrfRejected['location'], 'csrf_invalido'), 'El POST sin CSRF no fue rechazado.', $count);
    $listAfterCsrf = $super->request('GET', '/admin/global/usuarios');
    http3bAssert(!str_contains($listAfterCsrf['body'], 'webcsrf_'), 'El POST sin CSRF no persistió cambios.', $count);

    $phase = 'permisos de solo lectura y contexto';
    $reader = new HttpCliente3B($base); $clients[] = $reader;
    http3bLogin($reader, '/admin/login', ['username' => $options['reader-user'], 'password' => $options['reader-password']], $count, 'Login de lectura');
    $readerGlobal = $reader->request('GET', '/admin/global/usuarios');
    http3bAssert($readerGlobal['status'] === 200, 'La cuenta de lectura no pudo consultar su listado Global.', $count);
    $readerGlobalContext = $reader->request('GET', '/admin/contexto?contexto=global');
    http3bAssert($readerGlobalContext['status'] === 302 && $readerGlobalContext['location'] === '/admin/global', 'El selector devuelve al contexto Global autorizado.', $count);
    $readerPanContext = $reader->request('GET', '/admin/contexto?contexto=panaderia');
    http3bAssert($readerPanContext['status'] === 403, 'El selector deniega Panadería cuando no existe membresía.', $count);
    $readerRest = $reader->request('GET', '/admin/usuarios');
    http3bAssert($readerRest['status'] === 403, 'La cuenta Global abrió una URL de Restaurante sin membresía.', $count);
    $unknownApi = $reader->request('GET', '/api/3b-ruta-inexistente');
    http3bAssert($unknownApi['status'] === 403, 'Una API desconocida no queda pública para una cuenta de lectura.', $count);
    $readerWrite = $reader->request('POST', '/admin/global/usuarios/create', [
        'admin_csrf' => 'token-sintetico-de-prueba', 'username' => 'webrw_' . bin2hex(random_bytes(3)),
        'nombre' => 'Intento solo lectura', 'password' => 'SomePassword1', 'password_confirm' => 'SomePassword1',
        'ambitos' => ['global' => ['activo' => '1', 'permisos' => ['menu' => 'ver'], 'facultades' => []]],
    ]);
    http3bAssert($readerWrite['status'] === 403, 'La lectura permitió un POST de administración.', $count);

    $phase = 'login operativo por NIP y logout';
    $floor = new HttpCliente3B($base); $clients[] = $floor;
    http3bLogin($floor, '/login', ['nip' => $options['nip']], $count, 'Login operativo por NIP');
    $floorPanel = $floor->request('GET', '/admin/usuarios');
    http3bAssert($floorPanel['status'] === 403, 'La sesión de NIP abrió el panel administrativo.', $count);
    $floorAdminApi = $floor->request('GET', '/admin/api/analytics/venta-por-hora');
    http3bAssert($floorAdminApi['status'] === 403, 'La sesión operativa no abre una API administrativa.', $count);
    $floorPos = $floor->request('GET', '/punto-de-venta');
    http3bAssert($floorPos['status'] === 200 && str_contains($floorPos['body'], 'operational-main') && !str_contains($floorPos['body'], 'Fatal error'), 'El permiso operativo abre el POS de Restaurante adaptado en 3D.', $count);
    $logout = $floor->request('POST', '/logout');
    http3bAssert(in_array($logout['status'], [302,303], true) && str_starts_with($logout['location'], '/login'), 'Logout no cerró la sesión por NIP.', $count);
    $afterLogout = $floor->request('GET', '/admin/usuarios');
    http3bAssert($afterLogout['status'] === 401 && str_contains($afterLogout['body'], 'Vuelve a iniciar sesión.'), 'Tras logout, la petición devuelve 401 e invita a iniciar sesión.', $count);

    $logoutAdmin = $super->request('POST', '/logout');
    http3bAssert(in_array($logoutAdmin['status'], [302,303], true), 'Logout de contraseña no respondió con redirección.', $count);
    $afterAdminLogout = $super->request('GET', '/admin/global/usuarios');
    http3bAssert($afterAdminLogout['status'] === 401 && str_contains($afterAdminLogout['body'], 'Vuelve a iniciar sesión.'), 'Tras logout administrativo, la petición devuelve 401 e invita a iniciar sesión.', $count);
} catch (Throwable $e) {
    $failure = $e->getMessage();
} finally {
    foreach ($clients as $client) {
        try { $client->close(); } catch (Throwable) {}
    }
}

if ($failure === null) {
    echo json_encode(['ok' => true, 'comprobaciones' => $count], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}
fwrite(STDERR, json_encode(['ok' => false, 'fase' => $phase, 'comprobaciones' => $count, 'error' => $failure], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
exit(1);
