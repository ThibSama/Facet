<?php

declare(strict_types=1);

namespace Facet\Tests\Database;

use Facet\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Proves that user-controlled values reach MariaDB as bound parameters and
 * never as SQL text. Run against the live server, because emulated prepares —
 * the failure mode this guards against — are invisible client-side.
 */
final class PreparedStatementTest extends TestCase
{
    protected function setUp(): void
    {
        if (!TestDatabase::isConfigured()) {
            self::markTestSkipped(TestDatabase::unavailableReason());
        }

        TestDatabase::reset();

        TestDatabase::connection()->executeTrusted(
            'CREATE TABLE prepared_probe ('
            . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'label VARCHAR(191) NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    protected function tearDown(): void
    {
        if (TestDatabase::isConfigured()) {
            TestDatabase::reset();
        }
    }

    public function testBoundValueIsStoredVerbatim(): void
    {
        $database = TestDatabase::connection();

        $database->execute('INSERT INTO prepared_probe (label) VALUES (:label)', ['label' => "O'Brien"]);

        self::assertSame(
            "O'Brien",
            $database->selectValue('SELECT label FROM prepared_probe WHERE id = :id', ['id' => 1])
        );
    }

    public function testInjectionAttemptIsTreatedAsData(): void
    {
        $database = TestDatabase::connection();

        $attack = "'; DROP TABLE prepared_probe; -- ";
        $database->execute('INSERT INTO prepared_probe (label) VALUES (:label)', ['label' => $attack]);

        // The table still exists, and the payload was stored rather than run.
        self::assertSame($attack, $database->selectValue('SELECT label FROM prepared_probe WHERE id = 1'));
        self::assertSame(1, $database->selectValue('SELECT COUNT(*) FROM prepared_probe'));
    }

    public function testExecuteReportsAffectedRows(): void
    {
        $database = TestDatabase::connection();

        foreach (['a', 'b', 'c'] as $label) {
            $database->execute('INSERT INTO prepared_probe (label) VALUES (:label)', ['label' => $label]);
        }

        self::assertSame(
            2,
            $database->execute('DELETE FROM prepared_probe WHERE label IN (:first, :second)', [
                'first' => 'a',
                'second' => 'b',
            ])
        );
    }

    public function testSelectOneReturnsNullWhenNothingMatches(): void
    {
        self::assertNull(
            TestDatabase::connection()->selectOne(
                'SELECT * FROM prepared_probe WHERE label = :label',
                ['label' => 'absent']
            )
        );
    }

    public function testIntegerColumnsAreNotStringified(): void
    {
        $database = TestDatabase::connection();
        $database->execute('INSERT INTO prepared_probe (label) VALUES (:label)', ['label' => 'x']);

        $row = $database->selectOne('SELECT id, label FROM prepared_probe');

        self::assertNotNull($row);
        self::assertIsInt($row['id']);
        self::assertIsString($row['label']);
    }
}
