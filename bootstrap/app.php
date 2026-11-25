<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Arr;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: ['email_otp', 'digits.*']);
        $middleware->redirectGuestsTo('/?panel=profile');
        $middleware->append(\App\Http\Middleware\SecurityResponses::class);
        $middleware->append(\App\Http\Middleware\AuditAuthorizationDenials::class);
        $middleware->appendToGroup('web', [\App\Http\Middleware\SanitizeGoogleCallback::class, \App\Http\Middleware\EnforceSessionVersion::class]);
        $middleware->alias([
            'active' => \App\Http\Middleware\ActiveUserMiddleware::class,
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'permission' => \App\Http\Middleware\PermissionMiddleware::class,
            'phone.verified' => \App\Http\Middleware\PhoneVerifiedMiddleware::class,
            'verified.agent' => \App\Http\Middleware\VerifiedAgentMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (\Illuminate\Http\Request $request, \Throwable $error) =>
            $request->is('api/*') || $request->expectsJson());
        $exceptions->dontFlash(['password','password_confirmation','current_password','otp','mfa_code',
            'recovery_code','recovery_codes','mfa_secret','id_token','token','email_otp','digits']);
    })->create();

$app->afterBootstrapping(LoadConfiguration::class, static function (Application $app): void {
    // Laravel merges stock service options into app configuration, including after a cache rebuild.
    $config = $app->make('config');
    foreach ([
        'services' => ['ses', 'postmark', 'resend', 'slack'],
        'mail.mailers' => ['ses', 'ses-v2', 'postmark', 'resend', 'mailgun', 'failover', 'roundrobin'],
        'filesystems.disks' => ['s3'],
        'queue.connections' => ['sqs'],
        'cache.stores' => ['dynamodb'],
        'logging.channels' => ['slack', 'papertrail'],
        'broadcasting.connections' => ['pusher', 'ably'],
    ] as $path => $disabled) {
        $config->set($path, Arr::except($config->get($path, []), $disabled));
    }
});

return $app;
