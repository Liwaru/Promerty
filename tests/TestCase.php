<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));
        // Tests must never load the owner's OAuth/database credentials or operational APP_KEY.
        $sentinel = '__promerty_no_environment_file_for_tests__';
        if (is_file(dirname(__DIR__).'/'.$sentinel) || $app->configurationIsCached()) {
            throw new \LogicException('Tests require uncached configuration and no environment file.');
        }
        $app->loadEnvironmentFrom($sentinel);
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
        return $app;
    }
}
