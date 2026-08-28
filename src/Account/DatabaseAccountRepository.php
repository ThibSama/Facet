<?php

declare(strict_types=1);

namespace Facet\Account;

use Facet\Database\Database;
use Facet\Support\EmailAddress;
use Facet\Support\InvalidEmailAddressException;

/**
 * Accounts, read from MariaDB with prepared statements.
 *
 * Every value that came from outside the codebase — the submitted address, the
 * id held in the session — is bound as a parameter and never concatenated into
 * SQL. With `ATTR_EMULATE_PREPARES` off, {@see Database} has the server do the
 * binding, so these queries are injection-proof structurally rather than by
 * careful quoting.
 *
 * The two lookups select the same four columns plus, for the login path only,
 * the digest. Nothing selects `*`: a column added later must be asked for
 * deliberately before it can start travelling through the application.
 */
final class DatabaseAccountRepository implements AccountRepository
{
    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function findCredentialsByEmail(string $email): ?AccountCredentials
    {
        try {
            // The same normalisation the row was written under. An address that
            // cannot be canonicalised at all matches nothing, and says so the
            // same way an unknown address does.
            $canonical = EmailAddress::canonical($email);
        } catch (InvalidEmailAddressException) {
            return null;
        }

        $row = $this->database->selectOne(
            'SELECT id, email, role, status, password_hash FROM users WHERE email = :email LIMIT 1',
            ['email' => $canonical]
        );

        if ($row === null) {
            return null;
        }

        $account = self::hydrate($row);

        if ($account === null) {
            return null;
        }

        $hash = $row['password_hash'] ?? null;

        return new AccountCredentials($account, is_string($hash) ? $hash : '');
    }

    public function findById(int $id): ?Account
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->database->selectOne(
            'SELECT id, email, role, status FROM users WHERE id = :id LIMIT 1',
            ['id' => $id]
        );

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * A row becomes an account only if it is completely legible.
     *
     * A missing id or an unrecognised role yields null rather than a principal
     * with a default privilege — the one thing a hydration step must never do
     * is invent an answer for a column it could not read.
     *
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): ?Account
    {
        $id = $row['id'] ?? null;
        $role = Role::fromStorage($row['role'] ?? null);

        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }

        if ($role === null) {
            return null;
        }

        $email = $row['email'] ?? null;

        return new Account(
            (int) $id,
            is_string($email) ? $email : '',
            $role,
            AccountStatus::fromStorage($row['status'] ?? null)
        );
    }
}
