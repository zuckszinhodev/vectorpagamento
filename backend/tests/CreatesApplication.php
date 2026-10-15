<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    public function createApplication(): \Illuminate\Foundation\Application
    {
        $app = require __DIR__ . '/../bootstrap/app.php';
        // Never inherit local production credentials during the ordinary suite.
        if (!filter_var(getenv('RUN_REAL_PIX_TESTS'), FILTER_VALIDATE_BOOL)
            && !filter_var(getenv('RUN_EXTERNAL_PIX_TESTS'), FILTER_VALIDATE_BOOL)) {
            $app->useEnvironmentPath(__DIR__)->loadEnvironmentFrom('testing.env');
        }
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
