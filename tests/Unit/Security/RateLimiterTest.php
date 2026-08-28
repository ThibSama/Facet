<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Security;

use Facet\Security\RateLimiter;
use Facet\Session\ArraySession;
use Facet\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

/**
 * The throttle, asserted at the edges of its window.
 *
 * Every assertion here would be a sleep without the injected clock, which is
 * why the clock is injected. "Refused at the limit", "allowed one second after
 * the window closes" and "still refused one second before it does" are the
 * three facts that distinguish a rolling window from a counter that happens to
 * reset sometimes, and none of them can be observed by a test that cannot move
 * time.
 */
final class RateLimiterTest extends TestCase
{
    private const KEY = 'contact';

    public function testAttemptsWithinTheAllowanceAreAllowed(): void
    {
        $limiter = new RateLimiter(new FrozenClock(), 3, 60);
        $session = new ArraySession();

        for ($i = 1; $i <= 3; $i++) {
            self::assertTrue($limiter->attempt($session, self::KEY)->isAllowed(), 'Attempt ' . $i);
        }
    }

    public function testTheAttemptPastTheAllowanceIsRefused(): void
    {
        $limiter = new RateLimiter(new FrozenClock(), 3, 60);
        $session = new ArraySession();

        for ($i = 0; $i < 3; $i++) {
            $limiter->attempt($session, self::KEY);
        }

        $decision = $limiter->attempt($session, self::KEY);

        self::assertFalse($decision->isAllowed());
        self::assertGreaterThan(0, $decision->retryAfterSeconds());
        self::assertLessThanOrEqual(60, $decision->retryAfterSeconds());
    }

    /**
     * The rolling half of "rolling window": the allowance returns as the
     * oldest attempt ages out, and not a moment earlier.
     */
    public function testTheWindowRollsRatherThanResetting(): void
    {
        $clock = new FrozenClock();
        $limiter = new RateLimiter($clock, 2, 60);
        $session = new ArraySession();

        self::assertTrue($limiter->attempt($session, self::KEY)->isAllowed());

        $clock->advance(30);
        self::assertTrue($limiter->attempt($session, self::KEY)->isAllowed());

        $clock->advance(1);
        self::assertFalse($limiter->attempt($session, self::KEY)->isAllowed(), 'Both attempts are still in the window');

        // One second before the first attempt leaves the window.
        $clock->advance(28);
        self::assertFalse($limiter->attempt($session, self::KEY)->isAllowed());

        // And the moment it does.
        $clock->advance(2);
        self::assertTrue($limiter->attempt($session, self::KEY)->isAllowed());
    }

    public function testAnExhaustedWindowClearsCompletelyAfterItElapses(): void
    {
        $clock = new FrozenClock();
        $limiter = new RateLimiter($clock, 2, 60);
        $session = new ArraySession();

        $limiter->attempt($session, self::KEY);
        $limiter->attempt($session, self::KEY);

        self::assertTrue($limiter->isThrottled($session, self::KEY));

        $clock->advance(61);

        self::assertFalse($limiter->isThrottled($session, self::KEY));
        self::assertTrue($limiter->attempt($session, self::KEY)->isAllowed());
    }

    /**
     * A throttle, not a lockout: retrying does not extend the wait.
     *
     * If refused attempts were recorded, a client that kept trying would push
     * the window ahead of itself and never be let back in. The bound has to be
     * reachable for the limiter to be able to tell the visitor when it ends.
     */
    public function testRetryingDoesNotExtendTheWaitAndTheCountdownIsHonest(): void
    {
        $clock = new FrozenClock();
        $limiter = new RateLimiter($clock, 2, 60);
        $session = new ArraySession();

        $limiter->attempt($session, self::KEY);
        $limiter->attempt($session, self::KEY);

        for ($elapsed = 1; $elapsed < 60; $elapsed++) {
            $clock->advance(1);

            $decision = $limiter->attempt($session, self::KEY);

            self::assertFalse($decision->isAllowed(), 'Second ' . $elapsed);
            self::assertSame(
                60 - $elapsed,
                $decision->retryAfterSeconds(),
                'The countdown must shrink with the clock, not restart with the retry'
            );
        }

        $clock->advance(1);

        self::assertTrue(
            $limiter->attempt($session, self::KEY)->isAllowed(),
            'The wait the limiter promised must actually end'
        );
    }

    /**
     * The session must not grow with the attack: the stored window is capped
     * at the allowance however many attempts arrive.
     */
    public function testTheStoredWindowIsBounded(): void
    {
        $limiter = new RateLimiter(new FrozenClock(), 3, 600);
        $session = new ArraySession();

        for ($i = 0; $i < 500; $i++) {
            $limiter->attempt($session, self::KEY);
        }

        $stored = (string) $session->get('throttle.' . self::KEY);

        self::assertCount(3, explode(',', $stored), 'At most `limit` timestamps are kept');
        self::assertLessThan(120, strlen($stored));
    }

    public function testBucketsAreIndependent(): void
    {
        $limiter = new RateLimiter(new FrozenClock(), 1, 60);
        $session = new ArraySession();

        self::assertTrue($limiter->attempt($session, 'contact')->isAllowed());
        self::assertTrue($limiter->attempt($session, 'other')->isAllowed());
        self::assertFalse($limiter->attempt($session, 'contact')->isAllowed());
    }

    public function testSessionsAreIndependent(): void
    {
        $limiter = new RateLimiter(new FrozenClock(), 1, 60);

        self::assertTrue($limiter->attempt(new ArraySession(), self::KEY)->isAllowed());
        self::assertTrue($limiter->attempt(new ArraySession(), self::KEY)->isAllowed());
    }

    /**
     * A tampered or unparseable window is read as "no history" rather than
     * repaired or trusted. It is our own value, so a malformed one means the
     * store was edited.
     */
    public function testATamperedWindowIsDiscardedAndNotTrusted(): void
    {
        $clock = new FrozenClock();
        $limiter = new RateLimiter($clock, 2, 60);

        foreach ([
            'garbage' => 'not,a,window',
            'far future' => (string) ($clock->now() + 10_000),
            'negative' => '-1,-2',
            'empty' => '',
        ] as $why => $stored) {
            $session = new ArraySession(['throttle.' . self::KEY => $stored]);

            self::assertFalse($limiter->isThrottled($session, self::KEY), $why);
            self::assertTrue($limiter->attempt($session, self::KEY)->isAllowed(), $why);
        }
    }

    public function testResetClearsTheWindow(): void
    {
        $limiter = new RateLimiter(new FrozenClock(), 1, 60);
        $session = new ArraySession();

        $limiter->attempt($session, self::KEY);
        self::assertTrue($limiter->isThrottled($session, self::KEY));

        $limiter->reset($session, self::KEY);

        self::assertFalse($limiter->isThrottled($session, self::KEY));
    }

    /**
     * The privacy constraint, asserted structurally because it is a constraint
     * on what the class is *able* to do. Nothing here may read an address, a
     * header or a cookie: an anonymous session window is the whole mechanism,
     * and adding a persistent identifier to throttle by would be introducing
     * tracking under a security label.
     */
    public function testTheLimiterCannotSeeAnIdentifyingSignal(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Security/RateLimiter.php');

        foreach ([
            'REMOTE_ADDR',
            'X-Forwarded-For',
            '$_SERVER',
            '$_COOKIE',
            'setcookie',
            'User-Agent',
            'Facet\\Http\\Request',
        ] as $identifier) {
            self::assertStringNotContainsString($identifier, $source, 'The limiter must not reach for ' . $identifier);
        }
    }
}
