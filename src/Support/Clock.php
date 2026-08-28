<?php

declare(strict_types=1);

namespace Facet\Support;

/**
 * The current time, as a dependency rather than as a global fact.
 *
 * Anything that expires, throttles or windows has to ask something for the
 * time, and if that something is `time()` the behaviour cannot be tested
 * deterministically — a test either sleeps or asserts nothing. Injecting the
 * clock is what lets {@see \Facet\Security\RateLimiter} be proved at the
 * boundary of its window instead of near it.
 */
interface Clock
{
    /** Unix timestamp, in seconds. */
    public function now(): int;
}
