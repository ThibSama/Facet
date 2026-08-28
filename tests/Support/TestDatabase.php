<?php

declare(strict_types=1);

namespace Facet\Tests\Support;

use Facet\Database\Database;
use Facet\Database\DatabaseCredentials;
use PHPUnit\Framework\Assert;

/**
 * Access to the disposable MariaDB instance the integration tests run against.
 *
 * The credentials come from `FACET_TEST_DB_*` — deliberately distinct from the
 * application's own `DB_*` keys, so that running the suite can never reach the
 * database a developer has configured for real use.
 */
final class TestDatabase
{
    public const DSN = 'FACET_TEST_DB_DSN';
    public const USERNAME = 'FACET_TEST_DB_USER';
    public const PASSWORD = 'FACET_TEST_DB_PASSWORD';

    public static function isConfigured(): bool
    {
        foreach ([self::DSN, self::USERNAME, self::PASSWORD] as $key) {
            if (self::value($key) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Why the suite cannot run, phrased for a skip message. Never includes a
     * value — only the names of the variables that are missing.
     */
    public static function unavailableReason(): string
    {
        $missing = [];

        foreach ([self::DSN, self::USERNAME, self::PASSWORD] as $key) {
            if (self::value($key) === null) {
                $missing[] = $key;
            }
        }

        return sprintf(
            'No MariaDB integration database configured (missing: %s). '
            . 'Export them, or create .env.testing, to run the database gates.',
            implode(', ', $missing)
        );
    }

    public static function credentials(): DatabaseCredentials
    {
        Assert::assertTrue(self::isConfigured(), self::unavailableReason());

        return DatabaseCredentials::create(
            (string) self::value(self::DSN),
            (string) self::value(self::USERNAME),
            (string) self::value(self::PASSWORD)
        );
    }

    public static function connection(): Database
    {
        return new Database(self::credentials());
    }

    /**
     * Return the instance to a known-empty state.
     *
     * Drops every base table in the current schema rather than dropping the
     * schema itself, so the grant the test account holds stays sufficient.
     */
    public static function reset(): void
    {
        $database = self::connection();

        /** @var list<array<string, mixed>> $tables */
        $tables = $database->select(
            'SELECT table_name AS name FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_type = :type',
            ['type' => 'BASE TABLE']
        );

        if ($tables === []) {
            return;
        }

        $database->executeTrusted('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $table) {
            $name = (string) $table['name'];

            // Identifier, not a value: it cannot be bound, so it is quoted and
            // constrained to what information_schema just reported.
            $database->executeTrusted(sprintf('DROP TABLE IF EXISTS `%s`', str_replace('`', '``', $name)));
        }

        $database->executeTrusted('SET FOREIGN_KEY_CHECKS = 1');
    }

    private static function value(string $key): ?string
    {
        $fromProcess = getenv($key);

        if (is_string($fromProcess) && $fromProcess !== '') {
            return $fromProcess;
        }

        $fromFile = $_ENV[$key] ?? null;

        return is_string($fromFile) && $fromFile !== '' ? $fromFile : null;
    }
}
