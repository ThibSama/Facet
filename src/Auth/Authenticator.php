<?php

declare(strict_types=1);

namespace Facet\Auth;

use Facet\Account\Account;
use Facet\Account\AccountRepository;
use Facet\Security\CsrfGuard;
use Facet\Session\Session;

/**
 * The bridge between a session and a principal.
 *
 * What is written into the session when someone signs in is one thing: the
 * account's id, as a decimal string. Not the role, not the email, not the
 * status, not a serialized user. That is not minimalism for its own sake — it
 * is the property requirement 13 and 14 are made of. A role kept in the session
 * would be a role the application trusts without re-reading it, so disabling an
 * account, demoting it or deleting it would leave every open session with the
 * privileges it had at login. Storing only the id makes the stored row the sole
 * authority, on every single request.
 *
 * The two mutating operations own the identifier lifecycle:
 *
 * - {@see login()} regenerates the identifier *before* writing the id, so the
 *   session that becomes authenticated is never one whose name was known to
 *   anyone else beforehand.
 * - {@see logout()} clears the key, then destroys the session outright, so the
 *   cookie the browser was holding names nothing that can be resumed.
 */
final class Authenticator
{
    /** The only key authentication owns in the session. */
    public const SESSION_KEY = 'auth.account';

    private AccountRepository $accounts;

    private Session $session;

    private CsrfGuard $csrf;

    /**
     * The resolution for this request, memoised.
     *
     * `false` means "not looked up yet"; `null` means "looked up, nobody".
     * Distinguishing them is what keeps a guard, a handler and a view from
     * making three round trips to answer the same question — while still
     * guaranteeing at least one, because a cached principal from a *previous*
     * request is exactly the staleness this class exists to prevent.
     */
    private Account|false|null $resolved = false;

    public function __construct(AccountRepository $accounts, Session $session, ?CsrfGuard $csrf = null)
    {
        $this->accounts = $accounts;
        $this->session = $session;
        $this->csrf = $csrf ?? new CsrfGuard();
    }

    /**
     * The account this request is authenticated as, or null.
     *
     * Every rejection also clears the stored id. A session pointing at an
     * account that is gone, disabled or unreadable is not merely unauthorised
     * for this request — it is broken, and leaving the key in place would make
     * every later request repeat the same failed lookup.
     */
    public function current(): ?Account
    {
        if ($this->resolved !== false) {
            return $this->resolved;
        }

        $this->resolved = null;

        $stored = $this->session->get(self::SESSION_KEY);

        // No key at all is the common case — an anonymous visitor — and it must
        // not cost a query.
        if (!is_string($stored) || $stored === '' || !ctype_digit($stored)) {
            if ($stored !== null) {
                $this->session->forget(self::SESSION_KEY);
            }

            return null;
        }

        $account = $this->accounts->findById((int) $stored);

        if ($account === null || !$account->isActive()) {
            $this->session->forget(self::SESSION_KEY);

            return null;
        }

        return $this->resolved = $account;
    }

    public function isAuthenticated(): bool
    {
        return $this->current() !== null;
    }

    /**
     * Adopt an account as this session's principal.
     *
     * The order of the three statements is the fixation defence, and it only
     * works in this order. Re-keying first means the identifier that carries
     * the privilege has never been seen by anyone — least of all by an attacker
     * who planted the previous one — and the old record is destroyed as it goes,
     * so it cannot be resumed either.
     *
     * The CSRF token is rotated afterwards for the same reason a successful
     * contact submission rotates it: the secret that a visitor held while
     * anonymous should not remain valid for actions they can now perform.
     */
    public function login(Account $account): void
    {
        $this->session->regenerate();

        $this->session->put(self::SESSION_KEY, (string) $account->id());

        $this->csrf->rotate($this->session);

        $this->resolved = $account;
    }

    /**
     * End the session entirely.
     *
     * Forgetting the key alone would be enough for this application's own
     * checks and would still leave a live session behind the same cookie —
     * carrying a CSRF token, a throttle window and whatever a later checkpoint
     * adds. Destroying it is what makes "logged out" a statement about the
     * server rather than about this codebase's opinion of one key.
     */
    public function logout(): void
    {
        $this->session->forget(self::SESSION_KEY);
        $this->session->destroy();

        $this->resolved = null;
    }
}
