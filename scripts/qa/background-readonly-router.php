<?php
/** Isolated visual QA router: guarded QA schema, private file sessions, array cache. */
declare(strict_types=1);
$qaPort = getenv('PROMERTY_QA_PORT') ?: '8012';
if (PHP_SAPI !== 'cli-server' || ($_SERVER['SERVER_PORT'] ?? '') !== $qaPort) {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__, 2);
$private = $root.'/storage/app/private/phase4-20261110-73e56946';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Synthetic view tracking is intentionally a no-op in this read-only visual run.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && preg_match('#^/api/posts/[0-9]+/views$#D', $path)) {
    http_response_code(204);
    exit;
}
if (in_array($path, ['/storage/qa-only/synthetic-placeholder.jpg', '/storage/qa-only/synthetic-placeholder.mp4'], true)) {
    $qaPublic = realpath($private.'/customer-runtime/app/public/qa-only');
    $file = $qaPublic ? realpath($qaPublic.'/'.basename($path)) : false;
    if (!$file || !str_starts_with($file, $qaPublic.DIRECTORY_SEPARATOR) || !is_file($file)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: '.(mime_content_type($file) ?: 'application/octet-stream'));
    header('Content-Length: '.filesize($file));
    readfile($file);
    exit;
}
if (str_starts_with($path, '/build/') || str_starts_with($path, '/images/') || $path === '/favicon.ico' || preg_match('#^/favicon-(16|32|48|192|512)x\1\.png$#D', $path)) {
    $public = realpath($root.'/public');
    $file = $public ? realpath($public.$path) : false;
    if (!$file || !str_starts_with($file, $public.DIRECTORY_SEPARATOR) || !is_file($file)) {
        http_response_code(404);
        exit;
    }
    $type = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
        'js', 'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        default => mime_content_type($file) ?: 'application/octet-stream',
    };
    header('Content-Type: '.$type);
    readfile($file);
    exit;
}
$config = json_decode(file_get_contents($private.'/qa-connection.json'), true, 512, JSON_THROW_ON_ERROR);
if (($config['database'] ?? '') !== 'promerty_qa' || ($config['host'] ?? '') !== '127.0.0.1' || (int)($config['port'] ?? 0) !== 3306) {
    throw new RuntimeException('QA database isolation guard failed');
}

$storage = $root.'/storage/app/private/search-drawer-runtime';
foreach(['framework/views','framework/sessions','framework/cache/data','logs'] as $directory)if(!is_dir($storage.'/'.$directory))mkdir($storage.'/'.$directory,0777,true);
$key = file_get_contents($private.'/customer-runtime/key');
foreach ([
    'APP_ENV' => 'customer_qa',
    'APP_DEBUG' => 'false',
    'APP_URL' => 'http://127.0.0.1:'.$qaPort,
    'APP_KEY' => $key,
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => $config['host'],
    'DB_PORT' => (string)$config['port'],
    'DB_DATABASE' => $config['database'],
    'DB_USERNAME' => $config['user'],
    'DB_PASSWORD' => $config['password'],
    'DB_URL' => '',
    'DATABASE_URL' => '',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'file',
    'SESSION_COOKIE' => 'promerty_search_drawer_qa',
    'SESSION_DOMAIN' => '',
    'SESSION_SECURE_COOKIE' => 'false',
    'SANCTUM_STATEFUL_DOMAINS' => 'localhost:'.$qaPort.',127.0.0.1:'.$qaPort,
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    'LOG_CHANNEL' => 'stderr',
    'VIEW_COMPILED_PATH' => $root.'/storage/app/private/search-drawer-runtime/views',
    'APP_CONFIG_CACHE' => $private.'/customer-runtime/bootstrap/config.php',
    'APP_PACKAGES_CACHE' => $private.'/customer-runtime/bootstrap/packages.php',
    'APP_SERVICES_CACHE' => $private.'/customer-runtime/bootstrap/services.php',
    'APP_ROUTES_CACHE' => $private.'/customer-runtime/bootstrap/routes.php',
    'APP_EVENTS_CACHE' => $private.'/customer-runtime/bootstrap/events.php',
] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->addAbsoluteCachePathPrefix(substr($storage, 0, 3));
$app->useEnvironmentPath($private);
$app->loadEnvironmentFrom('background-qa-no-env');
$app->useStoragePath($storage);

if (str_starts_with(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/build/')) {
    return false;
}

try {
    $app->handleRequest(Illuminate\Http\Request::capture());
} catch (Throwable $error) {
    $log = $storage.'/logs/background-runtime.log';
    file_put_contents($log, date(DATE_ATOM).' '.get_class($error).': '.$error->getMessage()."\n".$error->getTraceAsString()."\n", FILE_APPEND | LOCK_EX);
    http_response_code(500);
    echo 'QA runtime error; inspect private diagnostic log.';
}
