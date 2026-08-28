<?php

declare(strict_types=1);

namespace Facet\Account;

use RuntimeException;

/**
 * Raised when an account cannot be created as asked.
 *
 * Messages here are shown to whoever is running the bootstrap command, so they
 * name the problem plainly — but never the password, and never the driver text
 * that produced them.
 */
final class AccountException extends RuntimeException
{
    public static function alreadyExists(string $email): self
    {
        return new self(sprintf('An account already exists for %s.', $email));
    }

    public static function passwordTooShort(int $minimum): self
    {
        return new self(sprintf('The password must be at least %d characters.', $minimum));
    }

    public static function passwordIsBlank(): self
    {
        return new self('A password is required.');
    }

    public static function notCreated(string $email): self
    {
        return new self(sprintf('The account for %s was not created.', $email));
    }
}
