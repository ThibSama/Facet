<?php

declare(strict_types=1);

namespace Facet\Account;

/**
 * The repository used when no database is configured.
 *
 * The public site is file-backed and must keep rendering with no credentials at
 * all, so an absent account store cannot be a boot failure. It is instead a
 * store in which no account exists: every login attempt fails generically and
 * every session id resolves to nobody, which is precisely the fail-closed
 * behaviour wanted. Nothing is granted on the strength of a lookup that could
 * not be performed.
 */
final class UnavailableAccountRepository implements AccountRepository
{
    public function findCredentialsByEmail(string $email): ?AccountCredentials
    {
        return null;
    }

    public function findById(int $id): ?Account
    {
        return null;
    }
}
