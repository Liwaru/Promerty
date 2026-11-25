<?php

declare(strict_types=1);

// Local CLI inventory. Never render database values, connection details or raw errors.
set_exception_handler(static function (Throwable $error): void {
    $stage = $GLOBALS['stage'] ?? 'initialization';
    fwrite(STDERR, "R2 inventory blocked at $stage.\n");
    exit(1);
});

function rows(PDO $db, string $sql, array $args = []): array
{
    $query = $db->prepare($sql);
    $query->execute($args);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function fingerprint(mixed $value): string
{
    return hash('sha256', serialize($value));
}

function safeFile(string $root, string $relative): string
{
    $path = $root;
    foreach (explode('/', $relative) as $component) {
        if (!preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.-]*$/D', $component)
            || str_ends_with($component, '.') || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $component)) {
            throw new RuntimeException('Unsafe media component');
        }
        $path .= DIRECTORY_SEPARATOR.$component;
        if (is_link($path) || realpath($path) !== $path) {
            throw new RuntimeException('Missing or redirected media');
        }
    }
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Unreadable media');
    }
    return $path;
}

$stage = 'tool and source configuration';
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
if (PHP_SAPI !== 'cli' || is_file($root.'/bootstrap/cache/config.php')) {
    throw new RuntimeException('CLI/config guard');
}
$env = Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
    'DB_URL', 'DATABASE_URL', 'DB_SOCKET'] as $key) {
    $override = getenv($key);
    if ($override !== false && $override !== (string) ($env[$key] ?? '')) {
        throw new RuntimeException('Unreviewed override');
    }
}
if (($env['APP_ENV'] ?? '') !== 'local' || ($env['DB_CONNECTION'] ?? '') !== 'mysql'
    || ($env['DB_HOST'] ?? '') !== '127.0.0.1' || (string) ($env['DB_PORT'] ?? '') !== '3306'
    || ($env['DB_DATABASE'] ?? '') !== 'promerty' || empty($env['DB_USERNAME'])
    || !preg_match('/^[A-Za-z0-9_.-]+$/D', $env['DB_USERNAME'])
    || (string) ($env['DB_PASSWORD'] ?? '') !== '' || !empty($env['DB_URL'])
    || !empty($env['DATABASE_URL']) || !empty($env['DB_SOCKET']) || getenv('MYSQL_PWD') !== false) {
    throw new RuntimeException('Approved local configuration changed');
}
$stage = 'source identity';
$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=promerty;charset=utf8mb4', $env['DB_USERNAME'], '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_STRINGIFY_FETCHES => true, PDO::ATTR_TIMEOUT => 5,
]);
$server = rows($db, 'SELECT DATABASE() AS db, VERSION() AS version, @@port AS port, @@basedir AS basedir,
    @@character_set_database AS charset, @@collation_database AS collation')[0];
if ($server['db'] !== 'promerty' || $server['version'] !== '10.4.32-MariaDB' || (int) $server['port'] !== 3306
    || strtolower(rtrim(str_replace('\\', '/', $server['basedir']), '/')) !== 'c:/xampp/mysql') {
    throw new RuntimeException('Server guard');
}
$db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
$stage = 'schema inventory';
$tables = rows($db, 'SELECT TABLE_NAME,TABLE_TYPE,ENGINE,TABLE_COLLATION FROM information_schema.TABLES
    WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME');
$columns = rows($db, 'SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,
    CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION');
$indexes = rows($db, 'SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,COLLATION
    FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX');
$constraintRows = rows($db, 'SELECT TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME');
$foreignKeys = rows($db, 'SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.COLUMN_NAME,
    k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,k.POSITION_IN_UNIQUE_CONSTRAINT,
    r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k
    JOIN information_schema.REFERENTIAL_CONSTRAINTS r
      ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
    WHERE k.TABLE_SCHEMA=DATABASE() ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION');
$checks = rows($db, 'SELECT TABLE_NAME,CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME');
$objects = [];
foreach (['views' => ['VIEWS', 'TABLE_SCHEMA'], 'triggers' => ['TRIGGERS', 'TRIGGER_SCHEMA'],
    'routines' => ['ROUTINES', 'ROUTINE_SCHEMA'], 'events' => ['EVENTS', 'EVENT_SCHEMA']] as $name => [$table, $schema]) {
    $objects[$name] = (int) rows($db, "SELECT COUNT(*) AS n FROM information_schema.$table WHERE $schema=DATABASE()")[0]['n'];
}
$counts = [];
$tableDigests = [];
foreach ($tables as $table) {
    $name = $table['TABLE_NAME'];
    if (!preg_match('/^[a-z0-9_]+$/D', $name)) {
        throw new RuntimeException('Unexpected table identifier');
    }
    if ($table['TABLE_TYPE'] !== 'BASE TABLE') {
        continue;
    }
    $stage = 'table counts and digests';
    $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    $query = $db->query("SELECT * FROM `$name`");
    $hashes = [];
    while (($row = $query->fetch(PDO::FETCH_NUM)) !== false) {
        if (count($hashes) >= 100_000) {
            throw new RuntimeException('Inventory exceeds bounded digest capacity');
        }
        $hashes[] = fingerprint($row);
    }
    $query->closeCursor();
    $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    sort($hashes, SORT_STRING);
    $counts[$name] = count($hashes);
    $tableDigests[$name] = fingerprint($hashes);
    unset($hashes);
}
$stage = 'media inventory';
$expectedRoles = ['customer', 'agent', 'branch_manager', 'admin', 'owner'];
$accountRoles = rows($db, 'SELECT r.name FROM users u JOIN roles r ON r.id=u.role_id
    WHERE u.id BETWEEN 1 AND 5 AND u.deleted_at IS NULL ORDER BY u.id');
$agentMatches = (int) rows($db, 'SELECT COUNT(*) AS n FROM users u JOIN agent_profiles a ON a.user_id=u.id
    JOIN branches b ON b.id=u.branch_id
    WHERE u.id=2 AND u.email=? AND u.status=? AND a.verification_status=? AND b.name=? AND b.status=?',
    ['hendrikhuang51@gmail.com', 'active', 'verified', 'Promex Batam City', 'active'])[0]['n'] === 1;
$tamaramaPublished = (int) rows($db, 'SELECT COUNT(*) AS n FROM listings l JOIN projects p ON p.id=l.project_id
    JOIN posts t ON t.listing_id=l.id JOIN project_assignments a ON a.project_id=p.id AND a.branch_id=l.branch_id
    WHERE l.id=1 AND t.id=1 AND p.id=1 AND l.agent_id=2 AND t.user_id=2 AND a.agent_id=2 AND a.active=1
    AND l.status=? AND t.status=? AND t.moderation_status=? AND p.status=? AND l.deleted_at IS NULL AND t.deleted_at IS NULL',
    ['active', 'published', 'active', 'active'])[0]['n'] === 1;
$media = rows($db, 'SELECT id, post_id, media_type, file_url, thumbnail_url FROM post_media ORDER BY id');
$references = [];
foreach ($media as $item) {
    $references[] = $item['file_url'];
    $references[] = $item['thumbnail_url'];
}
foreach (rows($db, 'SELECT photo FROM users WHERE photo IS NOT NULL') as $user) {
    $references[] = $user['photo'];
}
$files = [];
$external = 0;
$storage = realpath($root.'/storage/app');
if ($storage === false) {
    throw new RuntimeException('Storage unavailable');
}
$tamarama = 'EFFA4A686C2B3AE6E04AC8842DC0ADB0A711C7D5CDE2C530D25DEC4BB851AA4A';
$tamaramaVerified = false;
$referencedMedia = [];
foreach ($references as $value) {
    if ($value === null || $value === '') {
        continue;
    }
    if (preg_match('~^https?://~i', $value) && filter_var($value, FILTER_VALIDATE_URL)) {
        $external++;
        continue;
    }
    if (str_starts_with($value, 'agent-private/')) {
        if (!preg_match('~^agent-private/[a-f0-9]{48}\.(mp4|jpg|png|webp)$~D', $value)) {
            throw new RuntimeException('Unsupported private media reference');
        }
        $relative = 'private/agent-listings/'.substr($value, 14);
    } else {
        if (str_starts_with($value, '//')) {
            throw new RuntimeException('Ambiguous media reference');
        }
        $relative = 'public/'.preg_replace('~^storage/~', '', ltrim(rawurldecode($value), '/'));
    }
    $referencedMedia[$value] = $relative;
    $paths = [$relative];
    if (str_starts_with($relative, 'private/') && str_ends_with($relative, '.mp4')
        && file_exists($storage.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative.'.playback'))) {
        $paths[] = $relative.'.playback';
    }
    foreach ($paths as $path) {
        if (isset($files[$path])) {
            continue;
        }
        $file = safeFile($storage, $path);
        $hash = hash_file('sha256', $file);
        $bytes = filesize($file);
        if ($hash === false || $bytes === false || $bytes < 1) {
            throw new RuntimeException('Media integrity unavailable');
        }
        $files[$path] = ['bytes' => $bytes, 'sha256' => strtoupper($hash)];
    }
}
foreach ($media as $item) {
    if ((int) $item['id'] === 2 && (int) $item['post_id'] === 1 && $item['media_type'] === 'video') {
        $relative = $referencedMedia[$item['file_url']] ?? '';
        $tamaramaVerified = ($files[$relative]['sha256'] ?? '') === $tamarama;
    }
}
ksort($files, SORT_STRING);
$db->exec('ROLLBACK');
$output = [
    'status' => 'PASS', 'database' => 'promerty', 'version' => $server['version'],
    'charset' => $server['charset'], 'collation' => $server['collation'],
    'tables' => count($counts), 'columns' => count($columns), 'indexEntries' => count($indexes),
    'foreignKeys' => count(array_filter($foreignKeys, static fn (array $fk): bool => (int) $fk['ORDINAL_POSITION'] === 1)),
    'crossSchemaFkColumns' => count(array_filter($foreignKeys, static fn (array $fk): bool => $fk['REFERENCED_TABLE_SCHEMA'] !== 'promerty')),
    'enumColumns' => count(array_filter($columns, static fn (array $column): bool => str_starts_with($column['COLUMN_TYPE'], 'enum('))),
    'constraints' => count($constraintRows), 'checks' => count($checks), 'objects' => $objects,
    'nonTransactionalTables' => count(array_filter($tables, static fn (array $table): bool => $table['TABLE_TYPE'] === 'BASE TABLE' && $table['ENGINE'] !== 'InnoDB')),
    'rowCounts' => $counts, 'schemaFingerprint' => fingerprint([$tables, $columns, $indexes, $constraintRows, $foreignKeys, $checks, $objects]),
    'dataFingerprint' => fingerprint($tableDigests), 'mediaRows' => count($media), 'externalReferences' => $external,
    'files' => $files, 'tamaramaVerified' => $tamaramaVerified, 'tamaramaPublishedAndAssigned' => $tamaramaPublished,
    'fiveAccountRolesMatch' => array_column($accountRoles, 'name') === $expectedRoles, 'agentMatches' => $agentMatches,
];
if (($argv[1] ?? '') === '--backup-runtime') {
    $output['principalIdentity'] = $env['DB_USERNAME'];
} else {
    unset($output['files'], $output['dataFingerprint'], $output['schemaFingerprint']);
    $output['filesVerified'] = count($files);
    $output['mediaBytes'] = array_sum(array_column($files, 'bytes'));
}
echo json_encode($output, JSON_THROW_ON_ERROR), "\n";
