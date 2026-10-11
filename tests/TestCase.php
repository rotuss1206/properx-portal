<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Nova\Nova;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        // Nova keeps per-request data (e.g. the current user id) in static properties.
        Nova::flushState();

        parent::tearDown();
    }
}
