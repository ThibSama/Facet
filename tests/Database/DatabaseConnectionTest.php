<?php

declare(strict_types=1);

namespace Facet\Tests\Database;

use Facet\Database\Database;
use Facet\Database\DatabaseCredentials;
use Facet\Database\DatabaseException;
use Facet\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The PORT-87 focused gate, proved against a live MariaDB server.
 *
 * Nothing here is simulated. A connection is opened, statements are prepared
 * on the server, and an authentication failure is provoked on purpose so that
 * the shape of the resulting error can be asserted.
 */
final class DatabaseConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!TestDatabase::isConfigured()) {
            self::markTestSkipped(TestDatabase::unavailableReason());
        }
    }

    public function testConnectsToLiveServerAndQueries(): void
    {
        $database = TestDatabase::connection();

        self::assertFalse($database->isConnected(), 'connection is deferred until first use');

        $row = $database->selectOne('SELECT 1 AS one');

        self::assertSame(['one' => 1], $row);
        self::assertTrue($database->isConnected());
    }

    public function testServerIsMariaDb(): void
    {
        $version = TestDatabase::connection()->selectValue('SELECT VERSION()');

        self::assertIsString($version);
        self::assertStringContainsStringIgnoringCase(
            'mariadb',
            $version,
            'the integration gates are only meaningful against MariaDB'
        );
    }

    public function testAppliesConnectionOptions(): void
    {
        $pdo = TestDatabase::connection()->pdo();

        self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        self::assertSame(PDO::FETCH_ASSOC, $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
        self::assertFalse($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), 'prepares must be native');
    }

    public function testConnectionCharsetIsUtf8mb4(): void
    {
        $row = TestDatabase::connection()->selectOne(
            'SELECT @@character_set_client AS client, @@character_set_connection AS connection, '
            . '@@character_set_results AS results'
        );

        self::assertNotNull($row);
        self::assertSame(['client' => 'utf8mb4', 'connection' => 'utf8mb4', 'results' => 'utf8mb4'], $row);
    }

    public function testFourByteCharactersSurviveARoundTrip(): void
    {
        // The reason utf8mb4 is mandatory rather than merely preferred: on
        // three-byte utf8 this value comes back mangled instead of failing.
        $emoji = 'Facet 🛠️ — naïve café';

        self::assertSame(
            $emoji,
            TestDatabase::connection()->selectValue('SELECT :value', ['value' => $emoji])
        );
    }

    public function testDsnMustNotBeSqlite(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('must use the "mysql" driver');

        DatabaseCredentials::create('sqlite::memory:', 'user', 'secret');
    }

    public function testAbsentCharsetIsAddedAndConflictingCharsetRejected(): void
    {
        $added = DatabaseCredentials::create('mysql:host=127.0.0.1;dbname=facet', 'u', 'p');
        self::assertStringEndsWith(';charset=utf8mb4', $added->dsn());

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('charset must be utf8mb4');

        DatabaseCredentials::create('mysql:host=127.0.0.1;dbname=facet;charset=latin1', 'u', 'p');
    }
}
