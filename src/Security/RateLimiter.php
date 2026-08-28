<?php

declare(strict_types=1);

namespace Facet\Security;

use Facet\Session\Session;
use Facet\Support\Clock;

/**
 * A fixed number of attempts per rolling window, counted in the session.
 *
 * The design constraint here is a privacy one, and it decided the shape of the
 * class. Throttling by IP address means storing or hashing an IP address, which
 * is personal data the site has no other reason to hold; minting a device
 * identifier to throttle by is worse, because it is a tracking cookie wearing a
 * security hat. So the counter lives in the anonymous session that CSRF already
 * requires. That is a weaker bound — clearing a cookie resets it — and it is
 * the honest one for a portfolio contact form: it stops the accidental
 * double-submit and the casual repeat, it costs no new identifier, and it
 * claims nothing more.
 *
 * The window is a list of timestamps rather than a counter and a reset time, so
 * it is genuinely rolling, and it is truncated to the limit so the session
 * cannot be grown without bound by hammering the form.
 *
 * Time comes from an injected {@see Clock}, which is what lets a test prove the
 * behaviour exactly at the window boundary instead of sleeping through it.
 */
final class RateLimiter
{
    /** Attempts permitted inside one window. */
    public const DEFAULT_LIMIT = 5;

    /** The window, in seconds. */
    public const DEFAULT_WINDOW = 600;

    private Clock $clock;

    private int $limit;

    private int $window;

    public function __construct(Clock $clock, int $limit = self::DEFAULT_LIMIT, int $window = self::DEFAULT_WINDOW)
    {
        $this->clock = $clock;
        $this->limit = max(1, $limit);
        $this->window = max(1, $window);
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function window(): int
    {
        return $this->window;
    }

    /**
     * Weigh one attempt against the allowance, recording it if it fits.
     *
     * A refused attempt is deliberately *not* recorded, and that is the
     * difference between a throttle and a lockout. If retries extended the
     * window, a client that kept trying would never be let back in — the wait
     * would grow for as long as it lasted, which is unbounded by construction
     * and impossible to state honestly to the visitor. Not recording refusals
     * means the window holds only accepted attempts, so the answer to "when may
     * I try again" is a fixed point in time that {@see RateLimitDecision}
     * reports and the page can print.
     *
     * It also bounds storage for free: the recorded window can never hold more
     * than `limit` entries, however many requests arrive.
     */
    public function attempt(Session $session, string $key): RateLimitDecision
    {
        $now = $this->clock->now();
        $timestamps = $this->recent($session, $key, $now);

        if (count($timestamps) >= $this->limit) {
            // $timestamps[0] is the oldest accepted attempt still in the
            // window; the allowance returns the moment it leaves.
            return RateLimitDecision::refused($timestamps[0] + $this->window - $now);
        }

        $timestamps[] = $now;
        $session->put($this->sessionKey($key), implode(',', $timestamps));

        return RateLimitDecision::allowed();
    }

    /**
     * Whether the allowance is currently exhausted, recording nothing.
     */
    public function isThrottled(Session $session, string $key): bool
    {
        return count($this->recent($session, $key, $this->clock->now())) >= $this->limit;
    }

    public function reset(Session $session, string $key): void
    {
        $session->forget($this->sessionKey($key));
    }

    /**
     * The recorded attempts still inside the window, oldest first.
     *
     * Anything unparseable is discarded rather than repaired: the value is
     * ours, so a malformed one means the session was tampered with or is from
     * an older format, and in both cases the safe reading is "no history".
     *
     * @return list<int>
     */
    private function recent(Session $session, string $key, int $now): array
    {
        $stored = $session->get($this->sessionKey($key));

        if (!is_string($stored) || $stored === '') {
            return [];
        }

        $timestamps = [];

        foreach (explode(',', $stored) as $candidate) {
            if (preg_match('/^\d{1,19}$/', $candidate) !== 1) {
                continue;
            }

            $timestamp = (int) $candidate;

            // A timestamp in the future is not evidence of anything; drop it
            // rather than let it hold the window open indefinitely.
            if ($timestamp <= $now && $timestamp > $now - $this->window) {
                $timestamps[] = $timestamp;
            }
        }

        sort($timestamps);

        return $timestamps;
    }

    private function sessionKey(string $key): string
    {
        return 'throttle.' . $key;
    }
}
