<?php

declare(strict_types=1);

namespace Facet\Account;

/**
 * Where accounts are read from.
 *
 * The interface exists for the same reason {@see \Facet\Contact\ContactMessageStore}
 * does: the HTTP layer must depend on *resolving an account* rather than on
 * MariaDB, because a structural test forbids anything under `src/Http` from
 * naming the database layer at all. The guard and the login handler therefore
 * see these two methods and nothing else.
 *
 * Both methods answer null for "no such account". Neither throws to say it — an
 * absent account is an ordinary outcome of a login attempt and of a stale
 * session alike, and it must produce the same generic refusal in both cases.
 */
interface AccountRepository
{
    /**
     * The account for a login attempt, with the digest to check against.
     *
     * The address is canonicalised by the implementation, so `Ada@Example.COM `
     * and `ada@example.com` resolve to the same row — the same rule the schema
     * enforces on the way in.
     */
    public function findCredentialsByEmail(string $email): ?AccountCredentials;

    /**
     * The account an authenticated session's stored id refers to, as it is
     * *now* — role and status included.
     *
     * This is what makes a disabled or deleted account lose access without
     * anyone having to hunt down their session: authorisation re-reads the row
     * on every protected request rather than trusting what was true at login.
     */
    public function findById(int $id): ?Account;
}
