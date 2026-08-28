<?php

declare(strict_types=1);

namespace Facet\Account;

/**
 * Whether an account may currently be used.
 *
 * Separate from the role on purpose. Disabling an account must not require
 * deleting it or changing what it is, and an account that is disabled between
 * one request and the next must stop working on the next one — which is why the
 * status is re-read with the role rather than captured into the session at
 * login.
 */
enum AccountStatus: string
{
    case Active = 'active';

    case Disabled = 'disabled';

    /**
     * Anything that is not recognisably `active` is treated as disabled.
     *
     * The asymmetry is the safety property: an unreadable status must never be
     * the one that grants access.
     */
    public static function fromStorage(mixed $value): self
    {
        return is_string($value) && self::tryFrom($value) === self::Active
            ? self::Active
            : self::Disabled;
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
