<?php

declare(strict_types=1);

namespace Facet\Tests\Support;

use Facet\Account\Account;
use Facet\Account\AccountCredentials;
use Facet\Account\AccountRepository;
use Facet\Account\AccountStatus;
use Facet\Account\Role;
use Facet\Support\EmailAddress;

/**
 * Accounts held in an array, so the guard, the policy and the login handler can
 * be driven in process without a database.
 *
 * It is deliberately not a stub that returns whatever a test asked for: it
 * canonicalises addresses and hashes passwords exactly as the real repository
 * and the bootstrap command do, so a test that passes here is testing the same
 * comparison the live one performs. What it does not have is MariaDB — and the
 * live behaviour that only MariaDB can settle is asserted against the real
 * instance in {@see \Facet\Tests\Database\AccountRepositoryTest}.
 *
 * Digests are computed at run time and never written down: a repository that
 * ships a password hash is a repository that ships a credential.
 */
final class InMemoryAccountRepository implements AccountRepository
{
    /**
     * The cheapest bcrypt cost the algorithm accepts.
     *
     * A work factor is chosen to be slow, which is right in production and
     * pure waste in a suite that hashes on every case. `password_verify()`
     * reads the cost out of the digest, so the comparison under test is
     * identical — only the deliberate expense is gone.
     */
    private const COST = 4;

    /** @var array<int, array{account: Account, hash: string}> */
    private array $rows = [];

    private int $nextId = 1;

    /** How many times a lookup by id has been performed. */
    private int $resolutions = 0;

    public function add(
        string $email,
        string $password,
        Role $role = Role::Admin,
        AccountStatus $status = AccountStatus::Active
    ): Account {
        $id = $this->nextId++;

        $account = new Account($id, EmailAddress::normalise($email), $role, $status);

        $this->rows[$id] = [
            'account' => $account,
            'hash' => $password === '' ? '' : password_hash($password, PASSWORD_BCRYPT, ['cost' => self::COST]),
        ];

        return $account;
    }

    /**
     * Remove an account, as a deletion between two requests would.
     */
    public function remove(int $id): void
    {
        unset($this->rows[$id]);
    }

    /**
     * Change a status, as an administrator disabling somebody would.
     */
    public function setStatus(int $id, AccountStatus $status): void
    {
        if (!isset($this->rows[$id])) {
            return;
        }

        $existing = $this->rows[$id]['account'];

        $this->rows[$id]['account'] = new Account($existing->id(), $existing->email(), $existing->role(), $status);
    }

    public function findCredentialsByEmail(string $email): ?AccountCredentials
    {
        $canonical = EmailAddress::normalise($email);

        foreach ($this->rows as $row) {
            if ($row['account']->email() === $canonical) {
                return new AccountCredentials($row['account'], $row['hash']);
            }
        }

        return null;
    }

    public function findById(int $id): ?Account
    {
        $this->resolutions++;

        return $this->rows[$id]['account'] ?? null;
    }

    public function resolutions(): int
    {
        return $this->resolutions;
    }
}
