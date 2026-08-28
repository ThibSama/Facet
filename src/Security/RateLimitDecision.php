<?php

declare(strict_types=1);

namespace Facet\Security;

/**
 * What a {@see RateLimiter} concluded about one attempt.
 *
 * A bool would lose the only thing a refused visitor actually needs: how long
 * the wait is. Carrying it as a value keeps the answer honest — the number is
 * computed from the recorded window rather than guessed by the caller.
 */
final class RateLimitDecision
{
    private bool $allowed;

    private int $retryAfterSeconds;

    private function __construct(bool $allowed, int $retryAfterSeconds)
    {
        $this->allowed = $allowed;
        $this->retryAfterSeconds = $retryAfterSeconds;
    }

    public static function allowed(): self
    {
        return new self(true, 0);
    }

    public static function refused(int $retryAfterSeconds): self
    {
        return new self(false, max(1, $retryAfterSeconds));
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    /** Seconds until the oldest recorded attempt leaves the window. */
    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
