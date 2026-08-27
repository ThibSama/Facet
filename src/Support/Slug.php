<?php

declare(strict_types=1);

namespace Facet\Support;

use Stringable;

/**
 * The single definition of what a Facet slug is.
 *
 * Every place that accepts a slug — the `/projects/{slug}` route parameter and
 * every corpus entry — validates through this class, so the grammar can never
 * drift between routing and content. Slugs are part of the public URL surface
 * and are therefore treated as stable identifiers: they are validated at
 * construction and are immutable afterwards.
 */
final class Slug implements Stringable
{
    /**
     * Lowercase alphanumerics separated by single hyphens.
     *
     * Anchored, and intentionally strict: no uppercase, no underscores, no
     * leading/trailing/repeated hyphens, no Unicode.
     */
    public const PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 64;

    public const GRAMMAR_DESCRIPTION = 'lowercase letters, digits and single hyphens, '
        . self::MIN_LENGTH . '-' . self::MAX_LENGTH . ' characters, no leading or trailing hyphen';

    private string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * @throws InvalidSlugException when the candidate is not a canonical slug
     */
    public static function fromString(string $candidate): self
    {
        $reason = self::rejectionReason($candidate);

        if ($reason !== null) {
            throw InvalidSlugException::malformed($candidate, $reason);
        }

        return new self($candidate);
    }

    public static function isValid(string $candidate): bool
    {
        return self::rejectionReason($candidate) === null;
    }

    /**
     * The deterministic reason a candidate is rejected, or null if it is valid.
     *
     * Reasons are ordered from most to least specific so a given input always
     * produces the same message.
     */
    public static function rejectionReason(string $candidate): ?string
    {
        if ($candidate === '') {
            return 'it is empty';
        }

        $length = strlen($candidate);

        if ($length < self::MIN_LENGTH) {
            return sprintf('it is shorter than %d characters', self::MIN_LENGTH);
        }

        if ($length > self::MAX_LENGTH) {
            return sprintf('it is longer than %d characters', self::MAX_LENGTH);
        }

        if (trim($candidate) !== $candidate) {
            return 'it has leading or trailing whitespace';
        }

        if (strtolower($candidate) !== $candidate) {
            return 'it contains uppercase characters';
        }

        if (str_starts_with($candidate, '-') || str_ends_with($candidate, '-')) {
            return 'it starts or ends with a hyphen';
        }

        if (str_contains($candidate, '--')) {
            return 'it contains consecutive hyphens';
        }

        if (preg_match('/^' . self::PATTERN . '$/', $candidate) !== 1) {
            return 'it contains characters outside a-z, 0-9 and hyphen';
        }

        return null;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
