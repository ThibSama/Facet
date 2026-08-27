<?php

declare(strict_types=1);

namespace Facet\Content\Exception;

/**
 * Raised when stored content does not satisfy the canonical structure.
 */
final class InvalidContentException extends ContentException
{
    public static function missingKey(string $source, string $key): self
    {
        return new self(sprintf('Content source "%s" is missing required key "%s".', $source, $key));
    }

    public static function wrongType(string $source, string $key, string $expected): self
    {
        return new self(sprintf(
            'Content source "%s" key "%s" must be %s.',
            $source,
            $key,
            $expected
        ));
    }

    public static function unreadable(string $source): self
    {
        return new self(sprintf('Content source "%s" is missing or unreadable.', $source));
    }

    public static function notJson(string $source): self
    {
        return new self(sprintf('Content source "%s" does not contain a JSON object.', $source));
    }

    public static function because(string $source, string $reason): self
    {
        return new self(sprintf('Content source "%s" is invalid: %s.', $source, $reason));
    }
}
