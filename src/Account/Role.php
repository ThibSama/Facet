<?php

declare(strict_types=1);

namespace Facet\Account;

/**
 * What an account is allowed to be.
 *
 * The set is closed here and in the `users.role` column, and those are the only
 * two places it exists. Nothing derives a role from a request field, a cookie,
 * a template variable or a URL: a role is read from the row the session's
 * account id resolves to, or the request is not authorised at all.
 *
 * The enum is backed by exactly the strings the ENUM column stores, so a value
 * that survives {@see fromStorage()} is one the database could have produced —
 * and one it did.
 */
enum Role: string
{
    /** Maintains the site: the admin area and the message inbox. */
    case Admin = 'admin';

    /** Sees their own work, and nothing of the site's administration. */
    case Client = 'client';

    /**
     * The role a stored value names, or null when it names none.
     *
     * Null rather than an exception, because the caller that asks this is
     * resolving a row into a principal, and a row it cannot understand must
     * make that request unauthenticated rather than a 500. A column constrained
     * by an ENUM should never produce one; if a migration ever loosens it, this
     * fails closed instead of inventing a privilege.
     */
    public static function fromStorage(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }
}
