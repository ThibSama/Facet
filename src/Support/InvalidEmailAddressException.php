<?php

declare(strict_types=1);

namespace Facet\Support;

use InvalidArgumentException;

/**
 * Raised when a value cannot be stored as an email address.
 */
final class InvalidEmailAddressException extends InvalidArgumentException
{
    public static function empty(): self
    {
        return new self('An email address is required.');
    }

    public static function malformed(string $candidate): self
    {
        return new self(sprintf('"%s" is not a valid email address.', $candidate));
    }

    public static function tooLong(string $candidate, int $maximum): self
    {
        return new self(sprintf(
            'Email address is %d characters; the maximum is %d.',
            strlen($candidate),
            $maximum
        ));
    }
}
