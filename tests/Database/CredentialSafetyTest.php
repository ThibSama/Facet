<?php

declare(strict_types=1);

namespace Facet\Tests\Database;

use Facet\Config\Config;
use Facet\Config\MissingConfigurationException;
use Facet\Database\Database;
use Facet\Database\DatabaseCredentials;
use Facet\Database\DatabaseException;
use Facet\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * A failing connection is the moment credentials leak.
 *
 * MariaDB's own message for a rejected login names the account
 * (`Access denied for user 'facet'@'10.0.0.1'`), and an unguarded wrapper
 * copies that into logs and error pages. These tests provoke real failures and
 * assert on everything the application could plausibly print.
 */
final class CredentialSafetyTest extends TestCase
{
    private const WRONG_PASSWORD = 'definitely-not-the-password-9f3a2b';

    protected function setUp(): void
    {
        if (!TestDatabase::isConfigured()) {
            self::markTestSkipped(TestDatabase::unavailableReason());
        }
    }

    private static function rejectedConnection(): DatabaseException
    {
        $real = TestDatabase::credentials();

        $database = new Database(DatabaseCredentials::create(
            $real->dsn(),
            $real->username(),
            self::WRONG_PASSWORD
        ));

        try {
            $database->selectValue('SELECT 1');
        } catch (DatabaseException $e) {
            return $e;
        }

        self::fail('connecting with a wrong password must fail');
    }

    public function testAuthenticationFailureIsWrapped(): void
    {
        $exception = self::rejectedConnection();

        self::assertSame('Could not establish a database connection.', $exception->getMessage());
        // MariaDB reports a rejected login as HY000/1045 through PDO rather
        // than the 28000 the SQL standard reserves for it.
        self::assertSame('HY000', $exception->sqlState(), 'causality is preserved as SQLSTATE');
    }

    public function testNothingRenderedFromTheFailureNamesACredential(): void
    {
        $real = TestDatabase::credentials();
        $exception = self::rejectedConnection();

        $rendered = implode("\n", [
            $exception->getMessage(),
            $exception->getTraceAsString(),
            (string) $exception,
            $exception->driverDetail(),
            (string) $exception->sqlState(),
            print_r($exception, true),
            var_export($exception->driverDetail(), true),
        ]);

        foreach ([self::WRONG_PASSWORD, $real->password(), $real->username(), $real->dsn()] as $secret) {
            self::assertStringNotContainsString($secret, $rendered);
        }
    }

    public function testDriverDetailStillExplainsTheFailure(): void
    {
        $exception = self::rejectedConnection();

        self::assertStringContainsString('Access denied', $exception->driverDetail());
        self::assertStringContainsString('<redacted>', $exception->driverDetail());
    }

    public function testExceptionIsNotChainedToTheRawDriverError(): void
    {
        // Chaining would put the unscrubbed driver message back into
        // `(string) $exception` and into any log that walks getPrevious().
        self::assertNull(self::rejectedConnection()->getPrevious());
    }

    public function testVarDumpOfTheConnectionRevealsNothing(): void
    {
        $database = TestDatabase::connection();

        ob_start();
        var_dump($database);
        $dumped = (string) ob_get_clean();

        $real = TestDatabase::credentials();

        foreach ([$real->password(), $real->username(), $real->dsn()] as $secret) {
            self::assertStringNotContainsString($secret, $dumped);
        }
    }

    public function testQueryFailureNamesTheSqlButNoCredential(): void
    {
        $database = TestDatabase::connection();

        try {
            $database->select('SELECT * FROM a_table_that_does_not_exist');
            self::fail('querying a missing table must fail');
        } catch (DatabaseException $e) {
            self::assertStringContainsString('a_table_that_does_not_exist', $e->getMessage());
            self::assertSame('42S02', $e->sqlState());
            self::assertStringNotContainsString(TestDatabase::credentials()->password(), (string) $e);
        }
    }

    public function testCredentialsHaveNoUsableDefault(): void
    {
        $empty = Config::fromArray([]);

        foreach ([DatabaseCredentials::DSN_KEY, DatabaseCredentials::USERNAME_KEY, DatabaseCredentials::PASSWORD_KEY] as $key) {
            self::assertTrue($empty->isSensitive($key), $key . ' must be a sensitive key');
        }

        $this->expectException(MissingConfigurationException::class);
        DatabaseCredentials::fromConfig($empty);
    }

    public function testConfigRefusesToInventADatabaseCredential(): void
    {
        $config = Config::fromArray([]);

        $this->expectException(MissingConfigurationException::class);
        $config->get(DatabaseCredentials::PASSWORD_KEY, 'root');
    }
}
