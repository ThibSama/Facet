<?php

declare(strict_types=1);

namespace Facet\Support;

/**
 * The real clock. The only implementation that reads the host's time.
 */
final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
