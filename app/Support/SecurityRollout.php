<?php

namespace App\Support;

final class SecurityRollout
{
    public static function enabled(): bool
    {
        if (!config('security.qa_rollout')) return false;
        $driver = config('database.default');
        $isolatedTest = app()->environment('testing') && $driver === 'sqlite'
            && config('database.connections.sqlite.database') === ':memory:'
            && !config('database.connections.sqlite.url');
        $isolatedQa = app()->environment('local') && $driver === 'mysql'
            && config('database.connections.mysql.host') === '127.0.0.1'
            && (string)config('database.connections.mysql.port') === '3306'
            && config('database.connections.mysql.database') === 'promerty_qa'
            && !config('database.connections.mysql.url')
            && !config('database.connections.mysql.unix_socket')
            && !config('database.connections.mysql.read')
            && !config('database.connections.mysql.write');
        abort_unless($isolatedTest || $isolatedQa, 503, 'Security rollout requires an isolated QA environment.');
        return true;
    }
}
