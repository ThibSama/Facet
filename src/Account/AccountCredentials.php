<?php

declare(strict_types=1);

namespace Facet\Account;

use SensitiveParameter;

/**
 * An account together with the digest a password is checked against.
 *
 * This pairing exists so that the hash has somewhere to live that is narrower
 * than {@see Account}. Only {@see \Facet\Auth\AuthService} ever holds one, only
 * for the length of one verification, and what it hands on afterwards is the
 * bare Account — so the digest cannot reach a session, a view or a log by being
 * carried along in an object that was passed around for something else.
 */
final class AccountCredentials
{
    private Account $account;

    private string $passwordHash;

    public function __construct(Account $account, #[SensitiveParameter] string $passwordHash)
    {
        $this->account = $account;
        $this->passwordHash = $passwordHash;
    }

    public function account(): Account
    {
        return $this->account;
    }

    /**
     * Whether a stored digest exists at all.
     *
     * `password_verify()` is called only when this is true. Handing it an empty
     * string is not a check that fails safely, it is a check that was never
     * performed — and the caller must reject on the absence itself rather than
     * on its result.
     */
    public function hasPasswordHash(): bool
    {
        return $this->passwordHash !== '';
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }
}
