<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Nova;
use Tests\Support\FakeStripe;

abstract class TestCase extends BaseTestCase
{
    protected FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        // Documents and certificates live only on the private Blob disk; tests never reach
        // a real container (AzuriteIntegrationTest opts back in explicitly).
        Storage::fake('azure');

        // No test reaches Stripe: any request fails unless the test told the fake how to answer.
        $this->stripe = FakeStripe::install();
    }

    protected function tearDown(): void
    {
        FakeStripe::uninstall();

        // Nova keeps per-request data (e.g. the current user id, the CSP nonce) in static properties.
        Nova::flushState();
        Nova::$nonceAttribute = null;

        parent::tearDown();
    }
}
