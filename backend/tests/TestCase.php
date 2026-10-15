<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Ordinary tests must never send real payments or notifications.
        if (!filter_var(env('RUN_REAL_PIX_TESTS', false), FILTER_VALIDATE_BOOL)
            && !filter_var(env('RUN_EXTERNAL_PIX_TESTS', false), FILTER_VALIDATE_BOOL)) {
            \Illuminate\Support\Facades\Http::preventStrayRequests();
        }
    }
}
