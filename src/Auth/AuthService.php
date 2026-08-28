<?php

declare(strict_types=1);

namespace Facet\Auth;

use Facet\Account\Account;
use Facet\Account\AccountRepository;
use SensitiveParameter;

/**
 * Decides whether a submitted email and password identify a usable account.
 *
 * The return type is the design. There is no result object carrying a reason,
 * no enum of failure kinds and no boolean pair, because there is exactly one
 * failure this class is permitted to express: `null`. An address with no
 * account, an account whose password does not match, an account that is
 * disabled and an address that is not an address at all are four different
 * facts, and telling them apart is precisely what an attacker wants — the first
 * distinction alone turns a login form into an account enumerator. Collapsing
 * them at the point of decision means no caller can leak a difference it was
 * never handed.
 *
 * What remains distinguishable is timing, and the shape below is honest about
 * how far it goes: {@see password_verify()} is called only when a stored digest
 * exists, so a request for an unknown address does measurably less work than
 * one for a known address with a wrong password. Hashing a decoy would flatten
 * that, at the cost of running a verification against something that is not a
 * credential; this checkpoint takes the explicit rule — verify a stored hash or
 * nothing — and accepts the residual timing signal.
 */
final class AuthService
{
    private AccountRepository $accounts;

    public function __construct(AccountRepository $accounts)
    {
        $this->accounts = $accounts;
    }

    /**
     * The account these credentials identify, or null for every failure.
     *
     * The order is the cheapest safe one: resolve, then verify, then check that
     * the account may be used at all. The status check is deliberately *after*
     * verification — refusing a disabled account before checking its password
     * would answer faster for a disabled address than for an active one, which
     * re-introduces the enumeration the generic failure exists to prevent.
     */
    public function attempt(string $email, #[SensitiveParameter] string $password): ?Account
    {
        if ($password === '') {
            // Nothing to verify. A blank password must never be compared
            // against a digest, whatever the digest is.
            return null;
        }

        $credentials = $this->accounts->findCredentialsByEmail($email);

        if ($credentials === null || !$credentials->hasPasswordHash()) {
            return null;
        }

        if (!password_verify($password, $credentials->passwordHash())) {
            return null;
        }

        $account = $credentials->account();

        return $account->isActive() ? $account : null;
    }
}
