<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\SecurityAudit;
use App\Support\NewPassword;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

// Private operator entry point. Run from the repository root, outside a browser.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');

$accounts = [
    1 => ['James Goh', 'james@gmail.com', 'customer'],
    2 => ['Hendrik Huang', 'hendrikhuang51@gmail.com', 'agent'],
    3 => ['Azlina Putri', 'putri@gmail.com', 'branch_manager'],
    4 => ['Admin', 'admin@gmail.com', 'admin'],
    5 => ['Pandu Dinata', 'pandu@gmail.com', 'owner'],
];

$mode = $argv[1] ?? '';
$id = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT);
$phase = 'arguments';
$secret = '';
$exit = 0;

$readInput = static function () use (&$phase): string {
    $phase = 'hidden input framing';
    if (stream_isatty(STDIN)) {
        throw new RuntimeException();
    }
    $lineLimit = NewPassword::MAX_BYTES + 2;
    $lines = [fgets(STDIN, $lineLimit), fgets(STDIN, $lineLimit)];
    foreach ($lines as $line) {
        if (!is_string($line) || !str_ends_with($line, "\n") || str_contains($line, "\0")
            || str_contains($line, "\r") || !mb_check_encoding($line, 'UTF-8')) {
            throw new RuntimeException();
        }
    }

    $phase = 'password confirmation';
    if (!hash_equals($lines[0], $lines[1])) {
        throw new RuntimeException();
    }

    $phase = 'password policy';
    $password = substr($lines[0], 0, -1);
    if (Validator::make(['password' => $password], ['password' => NewPassword::rules(confirmed: false)])->fails()) {
        throw new RuntimeException();
    }
    return $password;
};

try {
    if (!((in_array($mode, ['--preflight', '--check-input'], true) && $argc === 2)
        || ($mode === '--apply' && $argc === 3 && isset($accounts[$id])))) {
        throw new RuntimeException();
    }

    $phase = 'local MariaDB target';
    require __DIR__.'/../vendor/autoload.php';
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if ($mode === '--check-input') {
        $readInput();
        echo "PASS - input policy only. No account rotation.\n";
        exit(0);
    }
    $db = DB::connection();
    $config = $db->getConfig();
    if (!$app->environment('local') || $db->getName() !== 'mysql' || $db->getDriverName() !== 'mysql'
        || $db->getDatabaseName() !== 'promerty' || $config['host'] !== '127.0.0.1' || (string) $config['port'] !== '3306'
        || !empty($config['url']) || !empty($config['unix_socket']) || isset($config['read']) || isset($config['write'])) {
        throw new RuntimeException();
    }
    $server = $db->selectOne('SELECT DATABASE() AS db, VERSION() AS version, @@port AS port, @@basedir AS base');
    if ($server->db !== 'promerty' || !str_contains($server->version, 'MariaDB') || (int) $server->port !== 3306
        || strtolower(rtrim(str_replace('\\', '/', $server->base), '/')) !== 'c:/xampp/mysql'
        || config('security.qa_rollout') || config('session.driver') !== 'database'
        || !in_array(config('session.connection'), [null, 'mysql'], true) || config('session.table') !== 'sessions'
        || config('auth.passwords.users.table') !== 'password_reset_tokens') {
        throw new RuntimeException();
    }
    $tables = ['users', 'user_auth_providers', 'sessions', 'personal_access_tokens', 'password_reset_tokens', 'audit_logs'];
    $engines = $db->table('information_schema.TABLES')->where('TABLE_SCHEMA', 'promerty')->whereIn('TABLE_NAME', $tables)->pluck('ENGINE', 'TABLE_NAME');
    if ($engines->count() !== count($tables) || $engines->contains(fn ($engine) => $engine !== 'InnoDB')) {
        throw new RuntimeException();
    }

    $getAccount = static function (int $userId, bool $lock = false) use ($accounts): User {
        $query = User::with('role')->whereKey($userId);
        $user = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        if (!$user->isActive() || [$user->name, $user->email, $user->role?->name] !== $accounts[$userId]) {
            throw new RuntimeException();
        }
        return $user;
    };

    $phase = 'account identity';
    if ($mode === '--preflight') {
        foreach (array_keys($accounts) as $userId) {
            $user = $getAccount($userId);
            echo json_encode(['id' => $userId, 'role' => $user->role->name, 'active' => true,
                'emailVerified' => $user->email_verified_at !== null], JSON_THROW_ON_ERROR).PHP_EOL;
        }
    } else {
        $getAccount($id);
        $secret = $readInput();

        $phase = 'account rotation';
        $result = $db->transaction(function () use ($id, $getAccount, $secret, $db): array {
            $user = $getAccount($id, true);
            $before = $user->getAttributes();
            $providers = $user->authProviders()->orderBy('id')->lockForUpdate()->get()->map->getAttributes()->all();
            if (Hash::check($secret, $user->getAuthPassword())) {
                throw new RuntimeException();
            }
            $user->forceFill(['password' => Hash::make($secret), 'remember_token' => Str::random(60)])->saveOrFail();
            $sessions = $db->table('sessions')->where('user_id', $id)->delete();
            $tokens = $user->tokens()->delete();
            $resets = $db->table('password_reset_tokens')->where('email', $user->email)->delete();

            $current = $getAccount($id);
            $allowed = array_flip(['password', 'remember_token', 'updated_at']);
            $identity = Auth::guard('web')->getProvider();
            $credentials = ['email' => $user->email, 'password' => $secret, 'status' => 'active'];
            $candidate = $identity->retrieveByCredentials($credentials);
            if (array_diff_key($before, $allowed) !== array_diff_key($current->getAttributes(), $allowed)
                || $providers !== $current->authProviders()->orderBy('id')->get()->map->getAttributes()->all()
                || !$candidate || (int) $candidate->getAuthIdentifier() !== $id
                || !$identity->validateCredentials($candidate, $credentials)
                || $db->table('sessions')->where('user_id', $id)->exists() || $current->tokens()->exists()
                || $db->table('password_reset_tokens')->where('email', $user->email)->exists()) {
                throw new RuntimeException();
            }

            $audit = app(SecurityAudit::class)->recordLocalPasswordRotation($current);
            return ['status' => 'PASS', 'id' => $id, 'emailVerified' => $current->email_verified_at !== null,
                'credentialProviderVerified' => true, 'sessionsRevoked' => $sessions, 'tokensRevoked' => $tokens,
                'resetTokensRevoked' => $resets, 'auditId' => $audit->id];
        });
        echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
    }
} catch (Throwable) {
    fwrite(STDERR, 'BLOCKED during '.$phase.'. No password or raw diagnostics recorded.'.PHP_EOL);
    $exit = 1;
} finally {
    unset($secret);
}
exit($exit);
