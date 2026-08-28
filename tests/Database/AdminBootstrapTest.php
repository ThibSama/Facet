<?php

declare(strict_types=1);

namespace Facet\Tests\Database;

use Facet\Account\AccountException;
use Facet\Account\AdminBootstrapper;
use Facet\Database\Database;
use Facet\Database\Migration\Migrator;
use Facet\Support\InvalidEmailAddressException;
use Facet\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The PORT-89 focused gate: an administrator can be minted from a shell, the
 * stored digest verifies, and bad input is refused without a partial write.
 */
final class AdminBootstrapTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private Database $database;

    private AdminBootstrapper $bootstrapper;

    protected function setUp(): void
    {
        if (!TestDatabase::isConfigured()) {
            self::markTestSkipped(TestDatabase::unavailableReason());
        }

        TestDatabase::reset();
        $this->database = TestDatabase::connection();
        Migrator::default($this->database, dirname(__DIR__, 2))->migrate();
        $this->bootstrapper = new AdminBootstrapper($this->database);
    }

    protected function tearDown(): void
    {
        if (TestDatabase::isConfigured()) {
            TestDatabase::reset();
        }
    }

    public function testCreatesAnAdministrator(): void
    {
        $id = $this->bootstrapper->create('ada@example.com', self::PASSWORD);

        self::assertGreaterThan(0, $id);

        $row = $this->database->selectOne('SELECT * FROM users WHERE id = :id', ['id' => $id]);

        self::assertNotNull($row);
        self::assertSame('ada@example.com', $row['email']);
        self::assertSame('admin', $row['role']);
        self::assertSame('active', $row['status']);
    }

    public function testStoredHashVerifiesAgainstThePassword(): void
    {
        $this->bootstrapper->create('ada@example.com', self::PASSWORD);

        $hash = $this->database->selectValue('SELECT password_hash FROM users WHERE email = :e', [
            'e' => 'ada@example.com',
        ]);

        self::assertIsString($hash);
        self::assertTrue(password_verify(self::PASSWORD, $hash), 'the stored digest must verify');
        self::assertFalse(password_verify('the wrong password', $hash));
    }

    public function testPasswordIsNeverStoredInPlaintext(): void
    {
        $this->bootstrapper->create('ada@example.com', self::PASSWORD);

        $hash = (string) $this->database->selectValue('SELECT password_hash FROM users LIMIT 1');

        self::assertStringNotContainsString(self::PASSWORD, $hash);
        self::assertNotSame(self::PASSWORD, $hash);

        $info = password_get_info($hash);
        self::assertNotNull($info['algo'], 'the column must hold a recognised password hash');
    }

    public function testEmailIsNormalisedOnInsert(): void
    {
        $this->bootstrapper->create('  Ada@Example.COM ', self::PASSWORD);

        self::assertSame(
            'ada@example.com',
            $this->database->selectValue('SELECT email FROM users LIMIT 1')
        );
    }

    public function testDuplicateAddressFailsSafely(): void
    {
        $this->bootstrapper->create('ada@example.com', self::PASSWORD);

        try {
            // Different casing, same account.
            $this->bootstrapper->create('ADA@Example.com', self::PASSWORD);
            self::fail('a duplicate address must be refused');
        } catch (AccountException $e) {
            self::assertStringContainsString('already exists', $e->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
        }

        self::assertSame(1, $this->bootstrapper->adminCount(), 'no second row may be written');
    }

    public function testMalformedAddressIsRefusedWithoutWriting(): void
    {
        try {
            $this->bootstrapper->create('not-an-address', self::PASSWORD);
            self::fail('a malformed address must be refused');
        } catch (InvalidEmailAddressException) {
            // expected
        }

        self::assertSame(0, $this->bootstrapper->adminCount());
    }

    public function testShortPasswordIsRefusedWithoutWriting(): void
    {
        try {
            $this->bootstrapper->create('ada@example.com', 'short');
            self::fail('a short password must be refused');
        } catch (AccountException $e) {
            self::assertStringContainsString('at least', $e->getMessage());
        }

        self::assertSame(0, $this->bootstrapper->adminCount());
    }

    public function testBlankPasswordIsRefused(): void
    {
        $this->expectException(AccountException::class);
        $this->bootstrapper->create('ada@example.com', '                 ');
    }

    public function testExistsMatchesRegardlessOfCase(): void
    {
        $this->bootstrapper->create('ada@example.com', self::PASSWORD);

        self::assertTrue($this->bootstrapper->exists('ADA@EXAMPLE.COM'));
        self::assertFalse($this->bootstrapper->exists('grace@example.com'));
    }

    public function testTwoDistinctAdminsAreAllowed(): void
    {
        $this->bootstrapper->create('ada@example.com', self::PASSWORD);
        $this->bootstrapper->create('grace@example.com', self::PASSWORD);

        self::assertSame(2, $this->bootstrapper->adminCount());
    }

    public function testEachAccountGetsADistinctHash(): void
    {
        $this->bootstrapper->create('ada@example.com', self::PASSWORD);
        $this->bootstrapper->create('grace@example.com', self::PASSWORD);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->database->select('SELECT password_hash FROM users ORDER BY id');

        // Identical passwords must not produce identical digests: the salt is
        // what stops one leaked hash from identifying every reused password.
        self::assertNotSame($rows[0]['password_hash'], $rows[1]['password_hash']);
    }
}
