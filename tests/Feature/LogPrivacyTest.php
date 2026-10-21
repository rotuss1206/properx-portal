<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use PDOException;
use Tests\TestCase;

/**
 * Logs carry IDs only: no emails, names or NPNs (KEENEY platform rule).
 */
class LogPrivacyTest extends TestCase
{
    public function test_query_exceptions_are_logged_without_bindings(): void
    {
        Log::spy();

        report(new QueryException(
            'mysql',
            'select * from users where email = ? limit 1',
            ['jane.doe@example.com'],
            new PDOException('SQLSTATE[HY000]: General error'),
        ));

        Log::shouldHaveReceived('error')->withArgs(function ($message, $context) {
            return $message === 'Database query failed'
                && $context['sql'] === 'select * from users where email = ? limit 1'
                && ! str_contains(json_encode($context), 'jane.doe@example.com');
        })->once();
    }
}
