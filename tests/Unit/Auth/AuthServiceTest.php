<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Auth;

use Facet\Account\AccountStatus;
use Facet\Account\Role;
use Facet\Account\UnavailableAccountRepository;
use Facet\Auth\AuthService;
use Facet\Tests\Support\InMemoryAccountRepository;
use PHPUnit\Framework\TestCase;

/**
 * The credential decision, and the single failure it is allowed to express.
 *
 * The property under test is not "a wrong password is rejected" — that is the
 * easy half. It is that four different facts about an attempt are
 * indistinguishable from the outside: no account, wrong password, disabled
 * account, unusable address. A login form that answers those differently is an
 * account enumerator, and the difference is usually introduced by someone
 * trying to be helpful.
 */
final class AuthServiceTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private InMemoryAccountRepository $accounts;

    private AuthService $auth;

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAccountRepository();
        $this->auth = new AuthService($this->accounts);
    }

    public function testCorrectCredentialsIdentifyTheAccount(): void
    {
        $created = $this->accounts->add('ada@example.com', self::PASSWORD, Role::Admin);

        $account = $this->auth->attempt('ada@example.com', self::PASSWORD);

        self::assertNotNull($account);
        self::assertSame($created->id(), $account->id());
        self::assertSame(Role::Admin, $account->role());
        self::assertTrue($account->isActive());
    }

    /**
     * The address is canonicalised the same way it was stored, so the case a
     * person types is not a reason to be refused.
     */
    public function testTheAddressIsMatchedCanonically(): void
    {
        $this->accounts->add('ada@example.com', self::PASSWORD);

        foreach (['ada@example.com', 'Ada@Example.COM', '  ADA@EXAMPLE.com  '] as $spelling) {
            self::assertNotNull($this->auth->attempt($spelling, self::PASSWORD), $spelling);
        }
    }

    /**
     * The heart of it: every way to fail returns exactly the same value.
     *
     * @return array<string, array{string, string}>
     */
    public static function refusals(): array
    {
        return [
            'no such account' => ['nobody@example.com', self::PASSWORD],
            'wrong password' => ['ada@example.com', 'not the password'],
            'blank password' => ['ada@example.com', ''],
            'disabled account' => ['grace@example.com', self::PASSWORD],
            'unusable address' => ['not-an-address', self::PASSWORD],
            'empty address' => ['', self::PASSWORD],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testEveryFailureIsTheSameGenericRefusal(string $email, string $password): void
    {
        $this->accounts->add('ada@example.com', self::PASSWORD, Role::Admin);
        $this->accounts->add('grace@example.com', self::PASSWORD, Role::Client, AccountStatus::Disabled);

        self::assertNull($this->auth->attempt($email, $password));
    }

    /**
     * A disabled account with a *correct* password is still nobody. Stated on
     * its own because it is the case a naive implementation gets wrong: the
     * password matched, so it feels like a success with a caveat.
     */
    public function testADisabledAccountWithTheRightPasswordIsStillRefused(): void
    {
        $account = $this->accounts->add('grace@example.com', self::PASSWORD, Role::Client);

        self::assertNotNull($this->auth->attempt('grace@example.com', self::PASSWORD));

        $this->accounts->setStatus($account->id(), AccountStatus::Disabled);

        self::assertNull($this->auth->attempt('grace@example.com', self::PASSWORD));
    }

    /**
     * A row with no digest is not an account with an empty password.
     *
     * `password_verify()` is called on a stored hash or not at all — handing it
     * an empty string is not a check that fails safely, it is a check that was
     * never performed.
     */
    public function testAnAccountWithNoStoredHashCannotBeSignedInTo(): void
    {
        $this->accounts->add('empty@example.com', '');

        foreach (['', ' ', 'anything at all'] as $attempt) {
            self::assertNull($this->auth->attempt('empty@example.com', $attempt));
        }
    }

    /**
     * With no database configured there are no accounts, so nobody can sign in.
     * An unconfigured deployment is a public site, not an open one.
     */
    public function testWithNoRepositoryConfiguredNobodyCanSignIn(): void
    {
        $auth = new AuthService(new UnavailableAccountRepository());

        self::assertNull($auth->attempt('ada@example.com', self::PASSWORD));
    }

    /**
     * The verification is a real digest comparison and not a string equality:
     * the stored value is a hash, and the submitted plaintext never equals it.
     */
    public function testTheStoredValueIsADigestRatherThanThePassword(): void
    {
        $this->accounts->add('ada@example.com', self::PASSWORD);

        $credentials = $this->accounts->findCredentialsByEmail('ada@example.com');

        self::assertNotNull($credentials);
        self::assertNotSame(self::PASSWORD, $credentials->passwordHash());
        self::assertStringNotContainsString(self::PASSWORD, $credentials->passwordHash());
        self::assertTrue(password_verify(self::PASSWORD, $credentials->passwordHash()));
    }
}
