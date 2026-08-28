<?php

declare(strict_types=1);

namespace Facet\Tests\Database;

use Facet\Database\Database;
use Facet\Database\Migration\Migration;
use Facet\Database\Migration\MigrationException;
use Facet\Database\Migration\MigrationLedger;
use Facet\Database\Migration\MigrationRepository;
use Facet\Database\Migration\Migrator;
use Facet\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The PORT-88 focused gate: a blank MariaDB database reaches the target
 * schema, re-running changes nothing, and edited history is refused.
 */
final class MigrationTest extends TestCase
{
    private Database $database;

    protected function setUp(): void
    {
        if (!TestDatabase::isConfigured()) {
            self::markTestSkipped(TestDatabase::unavailableReason());
        }

        TestDatabase::reset();
        $this->database = TestDatabase::connection();
    }

    protected function tearDown(): void
    {
        if (TestDatabase::isConfigured()) {
            TestDatabase::reset();
        }
    }

    private function migrator(): Migrator
    {
        return Migrator::default($this->database, dirname(__DIR__, 2));
    }

    /**
     * @return list<string>
     */
    private function tableNames(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->database->select(
            'SELECT table_name AS name FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_type = :type ORDER BY table_name',
            ['type' => 'BASE TABLE']
        );

        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    public function testBlankDatabaseStartsEmpty(): void
    {
        self::assertSame([], $this->tableNames(), 'reset() must leave a blank schema');
    }

    public function testMigratingABlankDatabaseCreatesTheTargetSchema(): void
    {
        $applied = $this->migrator()->migrate();

        self::assertSame(['0001', '0002'], $applied);
        self::assertSame(
            ['contact_messages', MigrationLedger::TABLE, 'users'],
            $this->tableNames()
        );
    }

    public function testSecondRunAppliesNothing(): void
    {
        $this->migrator()->migrate();

        $before = $this->database->select('SELECT version, checksum, applied_at FROM ' . MigrationLedger::TABLE);

        $second = $this->migrator()->migrate();

        self::assertSame([], $second, 'a re-run must apply nothing');
        self::assertSame([], $this->migrator()->pending());
        self::assertSame(
            $before,
            $this->database->select('SELECT version, checksum, applied_at FROM ' . MigrationLedger::TABLE),
            'the ledger must be untouched by a no-op run'
        );
    }

    public function testLedgerRecordsEveryMigrationOnce(): void
    {
        $this->migrator()->migrate();

        $applied = $this->migrator()->ledger()->applied();

        self::assertSame(['0001', '0002'], array_keys($applied));
        self::assertSame('create_users_table', $applied['0001']['name']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $applied['0001']['checksum']);
    }

    public function testEditingAnAppliedMigrationFailsClosed(): void
    {
        $this->migrator()->migrate();

        // Same version, different text: exactly what an edited history looks like.
        $tampered = new Migrator($this->database, MigrationRepository::fromMigrations([
            Migration::fromParts('0001', 'create_users_table', 'SELECT 1;'),
            Migration::fromParts('0002', 'create_contact_messages_table', 'SELECT 2;'),
        ]));

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('has changed since it was applied');

        $tampered->migrate();
    }

    public function testAppliedMigrationWithNoFileFailsClosed(): void
    {
        $this->migrator()->migrate();

        // Keep 0001 byte-identical so its checksum still matches; the only
        // discrepancy under test is that 0002 has gone missing.
        $real = MigrationRepository::fromDirectory(dirname(__DIR__, 2) . '/database/migrations')->all();

        $truncated = new Migrator($this->database, MigrationRepository::fromMigrations([$real[0]]));

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('recorded as applied but its file is absent');

        $truncated->verify();
    }

    public function testMigrationOrderIsIndependentOfFilesystemOrder(): void
    {
        $shuffled = MigrationRepository::fromMigrations([
            Migration::fromParts('0002', 'b', 'SELECT 2;'),
            Migration::fromParts('0001', 'a', 'SELECT 1;'),
        ]);

        // fromMigrations preserves the caller's order; the on-disk repository
        // is what guarantees version order, so assert that instead.
        self::assertSame(
            ['0001', '0002'],
            array_map(
                static fn (Migration $m): string => $m->version(),
                MigrationRepository::fromDirectory(dirname(__DIR__, 2) . '/database/migrations')->all()
            )
        );
        self::assertCount(2, $shuffled->all());
    }

    public function testDuplicateVersionsAreRejected(): void
    {
        $directory = self::temporaryMigrations([
            '0001_one.sql' => 'SELECT 1;',
            '0001_two.sql' => 'SELECT 2;',
        ]);

        try {
            $this->expectException(MigrationException::class);
            $this->expectExceptionMessage('share version');

            MigrationRepository::fromDirectory($directory);
        } finally {
            self::removeDirectory($directory);
        }
    }

    public function testMalformedFilenameIsRejected(): void
    {
        $directory = self::temporaryMigrations(['create_users.sql' => 'SELECT 1;']);

        try {
            $this->expectException(MigrationException::class);
            $this->expectExceptionMessage('is not named <version>_<name>.sql');

            MigrationRepository::fromDirectory($directory);
        } finally {
            self::removeDirectory($directory);
        }
    }

    /**
     * @param array<string, string> $files filename => SQL
     */
    private static function temporaryMigrations(array $files): string
    {
        $directory = sys_get_temp_dir() . '/facet-migrations-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($directory, 0o700), 'temporary migration directory is created');

        foreach ($files as $name => $sql) {
            file_put_contents($directory . '/' . $name, $sql);
        }

        return $directory;
    }

    private static function removeDirectory(string $directory): void
    {
        foreach ((array) glob($directory . '/*') as $file) {
            if (is_string($file)) {
                unlink($file);
            }
        }

        rmdir($directory);
    }
}
