<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Nova;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Documents and certificates live only on the private Blob disk; tests never reach
        // a real container (AzuriteIntegrationTest opts back in explicitly).
        Storage::fake('azure');
    }

    protected function tearDown(): void
    {
        // Nova keeps per-request data (e.g. the current user id, the CSP nonce) in static properties.
        Nova::flushState();
        Nova::$nonceAttribute = null;

        parent::tearDown();
    }
}
