<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\Auth\LoginAuthenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');
$recipient = 'hendrikhuang51@gmail.com';
$mode = $argv[1] ?? '';
$check = $mode === '--check';

try {
    if ($argc !== 2 || !in_array($mode, ['--check', '--send'], true)
        || !extension_loaded('openssl') || (!$check && stream_isatty(STDIN))) {
        throw new RuntimeException('PREFLIGHT_BLOCKED');
    }
    $root = dirname(__DIR__);
    $qa = $root.'/storage/app/private/smtp-live-qa-20261125';
    $isolatedEnv = [
        'APP_ENV'=>'testing', 'APP_DEBUG'=>'false', 'DB_CONNECTION'=>'sqlite',
        'DB_DATABASE'=>':memory:', 'DB_URL'=>'', 'QUEUE_CONNECTION'=>'sync',
        'SESSION_DRIVER'=>'array', 'CACHE_STORE'=>'array', 'MAIL_MAILER'=>'array',
        'LOG_CHANNEL'=>'"null"', 'APP_CONFIG_CACHE'=>$qa.'/no-config-cache.php',
        'PROMERTY_EMAIL_OTP_ROLLOUT'=>'false', 'PROMERTY_SECURITY_QA_ROLLOUT'=>'false',
    ];
    foreach ($isolatedEnv as $name => $value) {
        putenv($name.'='.$value);
        $_SERVER[$name] = $_ENV[$name] = $value;
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.smtp-qa-disabled');
    if (file_exists($root.'/.env.smtp-qa-disabled') || file_exists($root.'/.env.smtp-qa-disabled.testing')
        || $app->configurationIsCached()) {
        throw new RuntimeException('PREFLIGHT_BLOCKED');
    }
    $app->make(Kernel::class)->bootstrap();
    if (!$app->environment('testing') || config('database.default') !== 'sqlite'
        || config('database.connections.sqlite.database') !== ':memory:'
        || config('database.connections.sqlite.url') || config('queue.default') !== 'sync'
        || config('logging.default') !== 'null' || config('mail.default') !== 'array') {
        throw new RuntimeException('PREFLIGHT_BLOCKED');
    }

    $send = static fn (string $mailer) => Mail::mailer($mailer)->raw(
        "Ini adalah satu pesan uji pengiriman email Promerty. Pesan ini tidak berisi kode atau tautan.",
        static fn (Message $message) => $message->to($recipient)->subject('Promerty — Pengujian Pengiriman Email'),
    );
    $sender = 'qa@example.test';
    $password = null;
    if (!$check) {
        $input = fgets(STDIN, 1024);
        $credentials = is_string($input) ? json_decode($input, true) : null;
        unset($input);
        $sender = $credentials['sender'] ?? '';
        if (!is_string($sender) || !filter_var($sender, FILTER_VALIDATE_EMAIL)
            || !str_ends_with(strtolower($sender), '@gmail.com')
            || !is_string($credentials['appPassword'] ?? null)
            || !preg_match('/\A[a-zA-Z0-9]{16}\z/D', $password = str_replace(' ', '', $credentials['appPassword']))) {
            throw new RuntimeException('INPUT_BLOCKED');
        }
        unset($credentials);
        if ((!is_dir($qa) && !mkdir($qa)) || is_link($qa)
            || strtolower(str_replace('\\', '/', (string) realpath($qa))) !== strtolower(str_replace('\\', '/', $qa))) {
            throw new RuntimeException('PREFLIGHT_BLOCKED');
        }
    }
    config([
        'mail.from.address' => $sender,
        'mail.from.name' => 'Promerty QA',
        'mail.mailers.qa_probe' => ['transport' => 'smtp', 'scheme' => 'smtps', 'url' => null,
            'host' => 'smtp.gmail.com', 'port' => 465, 'timeout' => 12,
            'username' => $sender, 'password' => $password],
    ]);
    unset($password);
    $transport = Mail::mailer('qa_probe')->getSymfonyTransport();
    if (!$transport instanceof EsmtpTransport || !$transport->getStream()->isTLS()) {
        throw new RuntimeException('PREFLIGHT_BLOCKED');
    }
    $transport->getStream()->setStreamOptions(['ssl' => [
        'verify_peer'=>true, 'verify_peer_name'=>true, 'peer_name'=>'smtp.gmail.com', 'allow_self_signed'=>false,
    ]]);
    $transport->setAuthenticators([new LoginAuthenticator]);
    if ($check) {
        $send('array');
        if (Mail::mailer('array')->getSymfonyTransport()->messages()->count() !== 1) {
            throw new RuntimeException('PREFLIGHT_BLOCKED');
        }
        echo "CHECK_PASS_NO_NETWORK\n";
        exit(0);
    }

    // Reserve one actual submission. Preserve this marker even on ambiguous delivery.
    $marker = @fopen($qa.'/probe-attempted', 'xb');
    if (!$marker) {
        throw new RuntimeException('ATTEMPT_ALREADY_RESERVED');
    }
    fclose($marker);

    try {
        $sent = $send('qa_probe');
        echo $sent ? "SMTP_ACCEPTED_INBOX_UNCONFIRMED\n" : "SMTP_DELIVERY_UNCONFIRMED\n";
    } catch (\Throwable $error) {
        $details = $error->getMessage();
        $result = match (true) {
            preg_match('/\b(534|535)\b/', $details) === 1 => 'SMTP_AUTH_REJECTED',
            preg_match('/certificate|crypto|tls/i', $details) === 1 => 'SMTP_TLS_OR_CERTIFICATE_FAILED',
            preg_match('/timed out|unable to connect|getaddrinfo|connection refused/i', $details) === 1 => 'SMTP_CONNECTION_FAILED',
            default => 'SMTP_DELIVERY_UNCONFIRMED',
        };
        echo $result."\n";
        exit(2);
    } finally {
        config(['mail.mailers.qa_probe' => null]);
    }
    exit($sent ? 0 : 2);
} catch (\Throwable $error) {
    echo (in_array($error->getMessage(), ['INPUT_BLOCKED','ATTEMPT_ALREADY_RESERVED','PREFLIGHT_BLOCKED'], true)
        ? $error->getMessage() : 'PREFLIGHT_BLOCKED')."\n";
    exit(2);
}
