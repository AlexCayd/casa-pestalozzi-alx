<?php
declare(strict_types=1);

/**
 * Front controller para el servidor integrado de PHP durante desarrollo.
 * Apache ignora este archivo y continúa usando public/.htaccess.
 */
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$publicRoot = realpath(__DIR__ . '/../public');
if ($publicRoot === false) {
    http_response_code(500);
    exit('No se encontró el directorio público.');
}

$frontController = $publicRoot . DIRECTORY_SEPARATOR . 'index.php';
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$requestPath = parse_url($requestUri, PHP_URL_PATH);
$requestPath = is_string($requestPath) && $requestPath !== ''
    ? rawurldecode($requestPath)
    : '/';
$hasHiddenSegment = false;
foreach (explode('/', str_replace('\\', '/', $requestPath)) as $segment) {
    if ($segment !== '' && $segment[0] === '.') {
        $hasHiddenSegment = true;
        break;
    }
}

// El servidor integrado no lee .htaccess. Solo se dejan al servidor los
// archivos reales contenidos en public; rutas dinámicas, incluso las que
// terminan en .pdf, pasan por el front controller. Los segmentos ocultos
// tampoco se sirven (por ejemplo public/.htaccess). realpath evita escapar
// del document root mediante segmentos .. o enlaces simbólicos.
if (!$hasHiddenSegment && $requestPath !== '/' && $requestPath[0] === '/') {
    $relativePath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $requestPath), DIRECTORY_SEPARATOR);
    $staticFile = realpath($publicRoot . DIRECTORY_SEPARATOR . $relativePath);
    $publicPrefix = rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $insidePublic = $staticFile !== false
        && (PHP_OS_FAMILY === 'Windows'
            ? strncasecmp($staticFile, $publicPrefix, strlen($publicPrefix)) === 0
            : str_starts_with($staticFile, $publicPrefix));
    $isFrontController = $staticFile !== false && $staticFile === realpath($frontController);
    $extension = $staticFile !== false ? strtolower(pathinfo($staticFile, PATHINFO_EXTENSION)) : '';
    $isExecutable = in_array($extension, ['php', 'phtml', 'phar'], true);

    if ($insidePublic && is_file($staticFile) && (!$isExecutable || $isFrontController)) {
        return false;
    }
}

// REQUEST_URI/QUERY_STRING no se reescriben; PHP conserva $_GET tal como llegó.
$_SERVER['PATH_INFO'] = $requestPath;
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $frontController;

require $frontController;
