<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

try {
    require '/app/vendor/autoload.php';
    $app = require '/app/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $smoke = getenv('PROMERTY_LOCAL_SMOKE') === '1';
    $key = config('app.key');
    $db = config('database.connections.mariadb');
    $url = parse_url((string) config('app.url'));
    $validKey = is_string($key) && str_starts_with($key, 'base64:')
        && strlen((string) base64_decode(substr($key, 7), true)) === 32;
    $validDb = config('database.default') === 'mariadb' && blank($db['url']) && blank($db['unix_socket'])
        && filled($db['host']) && filled($db['database']) && filled($db['username'])
        && strtolower((string) $db['username']) !== 'root' && filled($db['password']);

    if (!$app->environment('production') || config('app.debug') !== false || !$validKey || !$validDb) {
        throw new RuntimeException('Invalid release configuration');
    }

    if ($smoke) {
        if (($url['scheme'] ?? '') !== 'http' || ($url['host'] ?? '') !== '127.0.0.1'
            || $db['host'] !== 'qa-db' || $db['database'] !== 'promerty_docker_smoke' || $db['username'] !== 'promerty_smoke'
            || config('mail.default') !== 'array') {
            throw new RuntimeException('Invalid smoke configuration');
        }
    } else {
        $ca = $db['options'][PDO::MYSQL_ATTR_SSL_CA] ?? null;
        if (($url['scheme'] ?? '') !== 'https' || empty($url['host']) || config('session.secure') !== true
            || config('session.http_only') !== true || config('session.encrypt') !== true
            || !is_string($ca) || !is_readable($ca)) {
            throw new RuntimeException('Invalid HTTPS, session or database TLS configuration');
        }
    }
} catch (Throwable) {
    fwrite(STDERR, "Promerty startup blocked. Check the protected runtime file, database identity and TLS settings.\n");
    exit(1);
}
