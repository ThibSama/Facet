<?php

declare(strict_types=1);

namespace Facet\Tests\Database;

use Facet\Account\AccountStatus;
use Facet\Account\AdminBootstrapper;
use Facet\Account\DatabaseAccountRepository;
use Facet\Account\Role;
use Facet\Auth\AuthService;
use Facet\Database\Database;
use Facet\Database\Migration\Migrator;
use Facet\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The PORT-92 account lookups against the real MariaDB instance.
 *
 * The in-process tests prove the decisions; this one proves they are made about
 * rows the database actually produced. Three things only a live instance can
 * settle: that the SQL is accepted at all, that the `users` ENUM columns arrive
 * as values the enums recognise, and that an address is matched under the same
 * normalisation the schema's CHECK constraint enforces on the way in.
 *
 * Passwords are hashed here at run time by {@see AdminBootstrapper}, the same
 * command an operator uses. No digest is written down in this repository.
 */
final class AccountRepositoryTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private Database $database;

    private DatabaseAccountRepository $accounts;

    private AuthService $auth;

    protected function setUp(): void
    {
        if (!TestDatabase::isConfigured()) {
            self::markTestSkipped(TestDatabase::unavailableReason());
        }

        TestDatabase::reset();
        $this->database = TestDatabase::connection();
        Migrator::default($this->database, dirname(__DIR__, 2))->migrate();

        $this->accounts = new DatabaseAccountRepository($this->database);
        $this->auth = new AuthService($this->accounts);
    }

    protected function tearDown(): void
    {
        if (TestDatabase::isConfigured()) {
            TestDatabase::reset();
        }
    }

    /**
     * Creates a row directly, so a client and a disabled account exist — the
     * bootstrap command only mints active administrators, by design.
     */
    private function insert(string $email, string $password, Role $role, AccountStatus $status): int
    {
        $this->database->execute(
            'INSERT INTO users (email, password_hash, role, status) VALUES (:email, :hash, :role, :status)',
            [
                'email' => $email,
                'hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role->value,
                'status' => $status->value,
            ]
        );

        return (int) $this->database->lastInsertId();
    }

    // ------------------------------------------------------------- lookup

    public function testAnAdministratorIsResolvedWithTheirStoredRole(): void
    {
        $id = (new AdminBootstrapper($this->database))->create('ada@example.com', self::PASSWORD);

        $account = $this->accounts->findById($id);

        self::assertNotNull($account);
        self::assertSame($id, $account->id());
        self::assertSame('ada@example.com', $account->email());
        self::assertSame(Role::Admin, $account->role());
        self::assertSame(AccountStatus::Active, $account->status());
        self::assertTrue($account->isActive());
    }

    public function testAClientIsResolvedWithTheirStoredRole(): void
    {
        $id = $this->insert('grace@example.com', self::PASSWORD, Role::Client, AccountStatus::Active);

        $account = $this->accounts->findById($id);

        self::assertNotNull($account);
        self::assertSame(Role::Client, $account->role());
        self::assertTrue($account->isActive());
    }

    public function testADisabledAccountIsResolvedAndReportsItself(): void
    {
        $id = $this->insert('alan@example.com', self::PASSWORD, Role::Client, AccountStatus::Disabled);

        $account = $this->accounts->findById($id);

        self::assertNotNull($account, 'The row exists and must be legible');
        self::assertSame(AccountStatus::Disabled, $account->status());
        self::assertFalse($account->isActive(), 'A disabled row must never be an active principal');
    }

    public function testADeletedAccountResolvesToNobody(): void
    {
        $id = (new AdminBootstrapper($this->database))->create('ada@example.com', self::PASSWORD);

        self::assertNotNull($this->accounts->findById($id));

        $this->database->execute('DELETE FROM users WHERE id = :id', ['id' => $id]);

        self::assertNull($this->accounts->findById($id), 'A stale session id must resolve to nobody');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function impossibleIdentifiers(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'absent' => [987654321]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('impossibleIdentifiers')]
    public function testAnIdentifierThatCannotExistResolvesToNobody(int $id): void
    {
        self::assertNull($this->accounts->findById($id));
    }

    // ------------------------------------------------------- verification

    public function testCredentialsAreFoundUnderTheCanonicalAddress(): void
    {
        (new AdminBootstrapper($this->database))->create('ada@example.com', self::PASSWORD);

        foreach (['ada@example.com', 'Ada@Example.COM', '  ADA@EXAMPLE.COM '] as $spelling) {
            $credentials = $this->accounts->findCredentialsByEmail($spelling);

            self::assertNotNull($credentials, $spelling);
            self::assertTrue($credentials->hasPasswordHash());
            self::assertTrue(password_verify(self::PASSWORD, $credentials->passwordHash()));
        }
    }

    /**
     * The whole authentication decision, against live rows.
     */
    public function testTheLiveMatrixOfLoginOutcomes(): void
    {
        (new AdminBootstrapper($this->database))->create('ada@example.com', self::PASSWORD);
        $this->insert('grace@example.com', self::PASSWORD, Role::Client, AccountStatus::Active);
        $this->insert('alan@example.com', self::PASSWORD, Role::Client, AccountStatus::Disabled);

        $admin = $this->auth->attempt('ada@example.com', self::PASSWORD);
        self::assertNotNull($admin);
        self::assertSame(Role::Admin, $admin->role());

        $client = $this->auth->attempt('grace@example.com', self::PASSWORD);
        self::assertNotNull($client);
        self::assertSame(Role::Client, $client->role());

        // Disabled, wrong password, unknown address: all the same answer.
        self::assertNull($this->auth->attempt('alan@example.com', self::PASSWORD));
        self::assertNull($this->auth->attempt('ada@example.com', 'the wrong password'));
        self::assertNull($this->auth->attempt('nobody@example.com', self::PASSWORD));
    }

    /**
     * Disabling an account is effective for the next lookup, without touching
     * anything the session holds — which is the whole reason status is re-read
     * rather than captured at login.
     */
    public function testDisablingAnAccountTakesEffectOnTheNextResolution(): void
    {
        $id = (new AdminBootstrapper($this->database))->create('ada@example.com', self::PASSWORD);

        $before = $this->accounts->findById($id);
        self::assertNotNull($before);
        self::assertTrue($before->isActive());

        $this->database->execute('UPDATE users SET status = :s WHERE id = :id', ['s' => 'disabled', 'id' => $id]);

        $after = $this->accounts->findById($id);
        self::assertNotNull($after);
        self::assertFalse($after->isActive());
        self::assertNull($this->auth->attempt('ada@example.com', self::PASSWORD));
    }

    // ---------------------------------------------------------- injection

    /**
     * Every value that came from outside is bound, never interpolated. With
     * emulation off the server does the binding, so these are not clever
     * strings that happen to be escaped — they are parameters, and cannot be
     * SQL at all.
     *
     * @return array<string, array{string}>
     */
    public static function hostileAddresses(): array
    {
        return [
            'tautology' => ["ada@example.com' OR '1'='1"],
            'comment' => ["ada@example.com'--"],
            'union' => ["' UNION SELECT id, email, role, status, password_hash FROM users --"],
            'drop' => ["x'; DROP TABLE users; --"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileAddresses')]
    public function testAHostileAddressMatchesNothingAndLeavesTheTableIntact(string $email): void
    {
        (new AdminBootstrapper($this->database))->create('ada@example.com', self::PASSWORD);

        self::assertNull($this->accounts->findCredentialsByEmail($email));
        self::assertNull($this->auth->attempt($email, self::PASSWORD));

        self::assertSame(1, (int) $this->database->selectValue('SELECT COUNT(*) FROM users'));
    }
}
