<?php

declare(strict_types=1);

namespace Facet\Tests\Support;

use Facet\Support\Clock;

/**
 * A clock that only moves when a test moves it.
 *
 * This is the entire reason {@see Clock} exists as an interface. A rate limiter
 * tested against `time()` can only be asserted approximately — a test either
 * sleeps for the window or proves nothing about its edge. With this, the window
 * boundary is a value, so "one second before it expires" and "exactly as it
 * expires" are two ordinary assertions.
 */
final class FrozenClock implements Clock
{
    private int $now;

    public function __construct(int $now = 1_700_000_000)
    {
        $this->now = $now;
    }

    public function now(): int
    {
        return $this->now;
    }

    public function advance(int $seconds): self
    {
        $this->now += $seconds;

        return $this;
    }
}
