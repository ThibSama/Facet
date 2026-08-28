<?php

declare(strict_types=1);

namespace Facet\Account;

/**
 * A signed-in principal, as the rest of the application is allowed to see it.
 *
 * Four fields, and not a fifth. There is no password hash on this object: the
 * digest exists only inside {@see AccountCredentials}, which never leaves the
 * verification path, so no handler, guard or template can hold one even by
 * accident.
 *
 * An Account is always the product of a row that was just read. It is not
 * stored in the session and not reconstructed from one — the session carries an
 * id, and this is what that id resolved to *on this request*.
 */
final class Account
{
    private int $id;

    private string $email;

    private Role $role;

    private AccountStatus $status;

    public function __construct(int $id, string $email, Role $role, AccountStatus $status)
    {
        $this->id = $id;
        $this->email = $email;
        $this->role = $role;
        $this->status = $status;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function role(): Role
    {
        return $this->role;
    }

    public function status(): AccountStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function is(Role $role): bool
    {
        return $this->role === $role;
    }
}
