<?php

namespace Tests\Feature;

use Pdo\Mysql;
use Tests\TestCase;

/**
 * The platform's MySQL is reached over TLS with a verified certificate; the CA path
 * arrives as MYSQL_ATTR_SSL_CA. A local database without it keeps working without TLS.
 */
class DatabaseTlsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('MYSQL_ATTR_SSL_CA');

        parent::tearDown();
    }

    /**
     * @return array<int, mixed>
     */
    private function mysqlOptions(): array
    {
        return (require config_path('database.php'))['connections']['mysql']['options'];
    }

    public function test_tls_with_certificate_verification_when_the_ca_is_set(): void
    {
        putenv('MYSQL_ATTR_SSL_CA=/etc/ssl/certs/mysql-ca.pem');

        $this->assertSame([
            Mysql::ATTR_SSL_CA => '/etc/ssl/certs/mysql-ca.pem',
            Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true,
        ], $this->mysqlOptions());
    }

    public function test_no_tls_options_without_a_ca(): void
    {
        putenv('MYSQL_ATTR_SSL_CA');

        $this->assertSame([], $this->mysqlOptions());
    }
}
