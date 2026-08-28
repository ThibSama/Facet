<?php

declare(strict_types=1);

namespace Facet\Tests\Database;

use Facet\Database\Database;
use Facet\Database\DatabaseException;
use Facet\Database\Migration\Migrator;
use Facet\Support\EmailAddress;
use Facet\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Inspects the schema the migrations actually produced on the live server.
 *
 * These assertions read from `information_schema` rather than from the SQL
 * files, so they prove what MariaDB built rather than what the migration
 * intended — and they fail if a future migration erodes a constraint.
 */
final class SchemaTest extends TestCase
{
    private Database $database;

    protected function setUp(): void
    {
        if (!TestDatabase::isConfigured()) {
            self::markTestSkipped(TestDatabase::unavailableReason());
        }

        TestDatabase::reset();
        $this->database = TestDatabase::connection();
        Migrator::default($this->database, dirname(__DIR__, 2))->migrate();
    }

    protected function tearDown(): void
    {
        if (TestDatabase::isConfigured()) {
            TestDatabase::reset();
        }
    }

    /**
     * @return array<string, array<string, mixed>> keyed by column name
     */
    private function columns(string $table): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->database->select(
            'SELECT column_name AS name, column_type AS type, is_nullable AS nullable, '
            . 'column_default AS `default`, character_maximum_length AS length '
            . 'FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND table_name = :table',
            ['table' => $table]
        );

        $columns = [];

        foreach ($rows as $row) {
            $columns[(string) $row['name']] = $row;
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function indexNames(string $table): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->database->select(
            'SELECT DISTINCT index_name AS name FROM information_schema.statistics '
            . 'WHERE table_schema = DATABASE() AND table_name = :table ORDER BY index_name',
            ['table' => $table]
        );

        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    /**
     * @return list<string>
     */
    private function checkConstraintNames(string $table): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->database->select(
            'SELECT constraint_name AS name FROM information_schema.table_constraints '
            . 'WHERE table_schema = DATABASE() AND table_name = :table AND constraint_type = :type '
            . 'ORDER BY constraint_name',
            ['table' => $table, 'type' => 'CHECK']
        );

        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    public function testUsersTableHasTheExpectedColumns(): void
    {
        $columns = $this->columns('users');

        self::assertSame(
            ['id', 'email', 'password_hash', 'role', 'status', 'created_at', 'updated_at'],
            array_keys($columns)
        );

        self::assertSame('bigint(20) unsigned', $columns['id']['type']);
        self::assertSame('NO', $columns['id']['nullable']);
        self::assertSame(254, (int) $columns['email']['length']);
        self::assertSame(255, (int) $columns['password_hash']['length']);
    }

    public function testUserRoleIsAClosedSet(): void
    {
        self::assertSame("enum('admin','client')", $this->columns('users')['role']['type']);
    }

    public function testUserStatusIsBounded(): void
    {
        self::assertSame("enum('active','disabled')", $this->columns('users')['status']['type']);
    }

    public function testUsersCarryUsefulIndexes(): void
    {
        $indexes = $this->indexNames('users');

        self::assertContains('PRIMARY', $indexes);
        self::assertContains('uniq_users_email', $indexes);
        self::assertContains('idx_users_role_status', $indexes);
    }

    public function testUsersEmailIndexIsUnique(): void
    {
        $nonUnique = $this->database->selectValue(
            'SELECT non_unique FROM information_schema.statistics '
            . 'WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i',
            ['t' => 'users', 'i' => 'uniq_users_email']
        );

        self::assertSame(0, (int) $nonUnique);
    }

    public function testContactMessagesHaveTheExpectedShape(): void
    {
        $columns = $this->columns('contact_messages');

        self::assertSame(
            ['id', 'name', 'email', 'subject', 'message', 'status', 'created_at'],
            array_keys($columns)
        );

        self::assertSame(120, (int) $columns['name']['length']);
        self::assertSame(254, (int) $columns['email']['length']);
        self::assertSame(200, (int) $columns['subject']['length']);
        self::assertSame('text', $columns['message']['type']);
    }

    public function testContactMessageStatusMatchesTheRequiredLifecycle(): void
    {
        self::assertSame(
            "enum('new','read','archived')",
            $this->columns('contact_messages')['status']['type']
        );
    }

    public function testContactMessagesCarryUsefulIndexes(): void
    {
        $indexes = $this->indexNames('contact_messages');

        self::assertContains('idx_contact_messages_status_created', $indexes);
        self::assertContains('idx_contact_messages_email', $indexes);
    }

    public function testCheckConstraintsExistOnBothTables(): void
    {
        self::assertContains('chk_users_email_normalised', $this->checkConstraintNames('users'));
        self::assertContains('chk_users_password_hash_present', $this->checkConstraintNames('users'));

        $contact = $this->checkConstraintNames('contact_messages');
        self::assertContains('chk_contact_messages_email_normalised', $contact);
        self::assertContains('chk_contact_messages_message_bounded', $contact);
    }

    public function testTablesAreInnoDbUtf8mb4(): void
    {
        foreach (['users', 'contact_messages'] as $table) {
            $row = $this->database->selectOne(
                'SELECT engine, table_collation FROM information_schema.tables '
                . 'WHERE table_schema = DATABASE() AND table_name = :t',
                ['t' => $table]
            );

            self::assertNotNull($row);
            self::assertSame('InnoDB', $row['engine']);
            self::assertStringStartsWith('utf8mb4', (string) $row['table_collation']);
        }
    }

    // --- Behavioural guarantees, not just shape ---------------------------

    private function insertUser(string $email, string $role = 'admin', string $status = 'active'): void
    {
        $this->database->execute(
            'INSERT INTO users (email, password_hash, role, status) '
            . 'VALUES (:email, :hash, :role, :status)',
            [
                'email' => $email,
                'hash' => password_hash('correct horse battery staple', PASSWORD_DEFAULT),
                'role' => $role,
                'status' => $status,
            ]
        );
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $this->insertUser('ada@example.com');

        $this->expectException(DatabaseException::class);
        $this->insertUser('ada@example.com');
    }

    public function testNormalisationMakesCasedDuplicatesCollide(): void
    {
        $this->insertUser(EmailAddress::canonical('  Ada@Example.COM '));

        self::assertSame(
            'ada@example.com',
            $this->database->selectValue('SELECT email FROM users LIMIT 1')
        );

        // The second address normalises to the identical string, so the unique
        // index — not application logic — is what stops the duplicate.
        $this->expectException(DatabaseException::class);
        $this->insertUser(EmailAddress::canonical('ADA@EXAMPLE.COM'));
    }

    public function testUnnormalisedEmailIsRejectedByTheDatabase(): void
    {
        // Bypassing EmailAddress entirely: the CHECK constraint must still hold.
        $this->expectException(DatabaseException::class);
        $this->insertUser('Ada@Example.com');
    }

    public function testInvalidRoleIsRejected(): void
    {
        $this->expectException(DatabaseException::class);
        $this->insertUser('root@example.com', 'superuser');
    }

    public function testInvalidAccountStatusIsRejected(): void
    {
        $this->expectException(DatabaseException::class);
        $this->insertUser('ghost@example.com', 'client', 'deleted');
    }

    public function testPlaintextPasswordIsRejected(): void
    {
        $this->expectException(DatabaseException::class);
        $this->database->execute(
            'INSERT INTO users (email, password_hash) VALUES (:email, :hash)',
            ['email' => 'weak@example.com', 'hash' => 'hunter2']
        );
    }

    public function testUserDefaultsAreSafe(): void
    {
        $this->database->execute(
            'INSERT INTO users (email, password_hash) VALUES (:email, :hash)',
            ['email' => 'default@example.com', 'hash' => password_hash('x', PASSWORD_DEFAULT)]
        );

        $row = $this->database->selectOne('SELECT role, status, created_at FROM users');

        self::assertNotNull($row);
        self::assertSame('client', $row['role'], 'a new account must not default to admin');
        self::assertSame('active', $row['status']);
        self::assertNotNull($row['created_at']);
    }

    public function testContactMessageDefaultsToNew(): void
    {
        $this->database->execute(
            'INSERT INTO contact_messages (name, email, subject, message) '
            . 'VALUES (:name, :email, :subject, :message)',
            [
                'name' => 'Ada',
                'email' => 'ada@example.com',
                'subject' => 'Hello',
                'message' => 'A message.',
            ]
        );

        $row = $this->database->selectOne('SELECT status, created_at FROM contact_messages');

        self::assertNotNull($row);
        self::assertSame('new', $row['status']);
        self::assertNotNull($row['created_at']);
    }

    public function testContactMessageStatusIsClosed(): void
    {
        $this->expectException(DatabaseException::class);
        $this->database->execute(
            'INSERT INTO contact_messages (name, email, subject, message, status) '
            . 'VALUES (:name, :email, :subject, :message, :status)',
            [
                'name' => 'Ada',
                'email' => 'ada@example.com',
                'subject' => 'Hello',
                'message' => 'A message.',
                'status' => 'spam',
            ]
        );
    }

    public function testOversizedContactMessageIsRejected(): void
    {
        $this->expectException(DatabaseException::class);
        $this->database->execute(
            'INSERT INTO contact_messages (name, email, subject, message) '
            . 'VALUES (:name, :email, :subject, :message)',
            [
                'name' => 'Ada',
                'email' => 'ada@example.com',
                'subject' => 'Hello',
                'message' => str_repeat('x', 5001),
            ]
        );
    }

    public function testBlankContactNameIsRejected(): void
    {
        $this->expectException(DatabaseException::class);
        $this->database->execute(
            'INSERT INTO contact_messages (name, email, subject, message) '
            . 'VALUES (:name, :email, :subject, :message)',
            ['name' => '   ', 'email' => 'ada@example.com', 'subject' => 'Hi', 'message' => 'Body']
        );
    }
}
