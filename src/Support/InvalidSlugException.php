<?php

declare(strict_types=1);

namespace Facet\Support;

use InvalidArgumentException;

/**
 * Raised when a slug does not satisfy the canonical slug grammar.
 */
final class InvalidSlugException extends InvalidArgumentException
{
    public static function malformed(string $candidate, string $reason): self
    {
        return new self(sprintf(
            'Invalid slug "%s": %s. Expected %s.',
            $candidate,
            $reason,
            Slug::GRAMMAR_DESCRIPTION
        ));
    }
}
