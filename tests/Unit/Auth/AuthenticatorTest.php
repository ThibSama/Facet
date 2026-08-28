<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Auth;

use Facet\Account\AccountStatus;
use Facet\Account\Role;
use Facet\Auth\Authenticator;
use Facet\Security\CsrfGuard;
use Facet\Session\ArraySession;
use Facet\Tests\Support\InMemoryAccountRepository;
use PHPUnit\Framework\TestCase;

/**
 * What a session is allowed to remember, and what it must ask again.
 *
 * Two properties are asserted here, and they are the two the whole
 * authorisation design rests on.
 *
 * The first is ordering: the identifier is re-keyed *before* the authenticated
 * state is written. A session that is authenticated first and re-keyed second
 * has, for that instant, been authenticated under a name someone else may have
 * chosen — and if the second step is ever dropped, the first has already done
 * the damage.
 *
 * The second is that the session holds an id and nothing else. No role, no
 * email, no status. Everything about the principal is re-read from the store on
 * every request, which is what makes a disabled, demoted or deleted account
 * lose access without anyone hunting down their session.
 */
final class AuthenticatorTest extends TestCase
{
    private InMemoryAccountRepository $accounts;

    private ArraySession $session;

    private Authenticator $authenticator;

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAccountRepository();
        $this->session = new ArraySession();
        $this->authenticator = new Authenticator($this->accounts, $this->session, new CsrfGuard());
    }

    public function testAnEmptySessionIsAnonymousAndCostsNoLookup(): void
    {
        self::assertNull($this->authenticator->current());
        self::assertFalse($this->authenticator->isAuthenticated());
        self::assertSame(0, $this->accounts->resolutions(), 'An anonymous visitor must not query the store');
    }

    public function testLoginRegeneratesTheIdentifierBeforeWritingAuthenticatedState(): void
    {
        $account = $this->accounts->add('ada@example.com', 'a password', Role::Admin);

        // A session that already carries something, as a visitor who opened the
        // login form does.
        $token = (new CsrfGuard())->token($this->session);

        self::assertSame(0, $this->session->regenerations());
        self::assertFalse($this->session->has(Authenticator::SESSION_KEY));

        $this->authenticator->login($account);

        self::assertSame(1, $this->session->regenerations(), 'The identifier must be re-keyed exactly once');
        self::assertSame((string) $account->id(), $this->session->get(Authenticator::SESSION_KEY));

        // And the token that was valid while anonymous is not the token that is
        // valid now that a privilege exists.
        self::assertNotSame($token, $this->session->get(CsrfGuard::SESSION_KEY));
    }

    /**
     * The session's whole authenticated state, enumerated. If a role or an
     * email ever appears here, authorisation has acquired a second source of
     * truth — and the one in the session is the forgeable one.
     */
    public function testTheSessionStoresAnIdentifierAndNothingElse(): void
    {
        $account = $this->accounts->add('ada@example.com', 'a password', Role::Admin);

        $this->authenticator->login($account);

        $stored = $this->session->all();
        unset($stored[CsrfGuard::SESSION_KEY]);

        self::assertSame([Authenticator::SESSION_KEY => (string) $account->id()], $stored);

        foreach ($stored as $value) {
            self::assertStringNotContainsStringIgnoringCase('admin', $value);
            self::assertStringNotContainsStringIgnoringCase('example.com', $value);
        }
    }

    public function testTheRoleIsReReadFromTheStoreOnEveryRequest(): void
    {
        $account = $this->accounts->add('ada@example.com', 'a password', Role::Admin);
        $this->authenticator->login($account);

        // A second request: a fresh authenticator over the same session data.
        $next = new Authenticator($this->accounts, $this->session, new CsrfGuard());

        $current = $next->current();

        self::assertNotNull($current);
        self::assertSame(Role::Admin, $current->role());
        self::assertSame(1, $this->accounts->resolutions(), 'The principal must be resolved from the store');
    }

    public function testResolutionIsMemoisedWithinOneRequest(): void
    {
        $account = $this->accounts->add('ada@example.com', 'a password');
        $this->session->put(Authenticator::SESSION_KEY, (string) $account->id());

        $this->authenticator->current();
        $this->authenticator->current();
        $this->authenticator->isAuthenticated();

        self::assertSame(1, $this->accounts->resolutions());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableSessionValues(): array
    {
        return [
            'empty' => [''],
            'not a number' => ['admin'],
            'negative' => ['-1'],
            'injection-shaped' => ["1 OR 1=1"],
            'float' => ['1.5'],
            'padded' => [' 1'],
        ];
    }

    /**
     * A session value that is not an identifier is not treated as one — and it
     * is dropped, so it cannot be retried on the next request.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusableSessionValues')]
    public function testAMalformedStoredIdentifierIsUnauthenticated(string $value): void
    {
        $this->accounts->add('ada@example.com', 'a password');
        $this->session->put(Authenticator::SESSION_KEY, $value);

        self::assertNull($this->authenticator->current());
        self::assertFalse($this->session->has(Authenticator::SESSION_KEY));
        self::assertSame(0, $this->accounts->resolutions(), 'A malformed id must not reach the store');
    }

    public function testASessionPointingAtADeletedAccountIsUnauthenticated(): void
    {
        $account = $this->accounts->add('ada@example.com', 'a password');
        $this->authenticator->login($account);

        $this->accounts->remove($account->id());

        $next = new Authenticator($this->accounts, $this->session, new CsrfGuard());

        self::assertNull($next->current());
        self::assertFalse($this->session->has(Authenticator::SESSION_KEY), 'A broken session must not be retried');
    }

    public function testASessionPointingAtADisabledAccountIsUnauthenticated(): void
    {
        $account = $this->accounts->add('grace@example.com', 'a password', Role::Client);
        $this->authenticator->login($account);

        self::assertTrue((new Authenticator($this->accounts, $this->session, new CsrfGuard()))->isAuthenticated());

        $this->accounts->setStatus($account->id(), AccountStatus::Disabled);

        $next = new Authenticator($this->accounts, $this->session, new CsrfGuard());

        self::assertNull($next->current());
        self::assertFalse($this->session->has(Authenticator::SESSION_KEY));
    }

    /**
     * Logout is not "forget one key". The session itself is ended, so nothing
     * the old identifier named survives it.
     */
    public function testLogoutDestroysTheWholeSession(): void
    {
        $account = $this->accounts->add('ada@example.com', 'a password');
        $this->authenticator->login($account);

        $this->session->put('contact.flash', 'sent');

        $this->authenticator->logout();

        self::assertNull($this->authenticator->current());
        self::assertTrue($this->session->wasDestroyed());
        self::assertSame([], $this->session->all(), 'Nothing may survive a logout');
    }
}
