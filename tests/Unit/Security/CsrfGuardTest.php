<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Security;

use Facet\Security\CsrfGuard;
use Facet\Session\ArraySession;
use PHPUnit\Framework\TestCase;

/**
 * The CSRF guard, asserted on the four properties it exists for.
 *
 * A token that is guessable, a token that is not tied to a session, a
 * comparison that leaks timing, and an absent token treated as "nothing to
 * check" are four different ways to ship a guard that is decorative. Each has
 * a test below, and the third is asserted structurally — a timing assertion is
 * flaky by nature, so what is checked is that the comparison is
 * {@see hash_equals()} rather than how long it took.
 */
final class CsrfGuardTest extends TestCase
{
    /**
     * A source file with every comment removed — the code as it runs, so a
     * structural assertion is about behaviour and not about prose.
     */
    private static function code(string $class): string
    {
        $path = dirname(__DIR__, 3) . '/src/Security/' . $class . '.php';
        self::assertFileExists($path);

        $tokens = token_get_all((string) file_get_contents($path));
        $code = '';

        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    public function testATokenIsIssuedAndIsStableForTheSameSession(): void
    {
        $guard = new CsrfGuard();
        $session = new ArraySession();

        $first = $guard->token($session);

        self::assertNotSame('', $first);
        self::assertSame($first, $guard->token($session), 'Rendering the form twice must not invalidate the first tab');
        self::assertSame($first, $session->get(CsrfGuard::SESSION_KEY), 'The token is held by the session');
    }

    /**
     * 32 bytes hex-encoded. The length is asserted because a token that
     * shrinks silently is a token that becomes brute-forceable silently.
     */
    public function testTokensAreLongRandomAndNotRepeated(): void
    {
        $guard = new CsrfGuard();
        $tokens = [];

        for ($i = 0; $i < 200; $i++) {
            $token = $guard->token(new ArraySession());

            self::assertSame(64, strlen($token));
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

            $tokens[$token] = true;
        }

        self::assertCount(200, $tokens, 'Every session must receive a distinct token');
    }

    public function testTheTokenComesFromACryptographicSource(): void
    {
        // Structural, and deliberately so: the property "unpredictable" cannot
        // be asserted by sampling output, only by checking that the source is
        // the CSPRNG and not a weak generator. Comments are stripped first, so
        // the class is free to *name* the weak alternatives while explaining
        // why it does not use them.
        $source = self::code('CsrfGuard');

        self::assertStringContainsString('random_bytes(', $source);

        foreach (['mt_rand(', 'rand(', 'uniqid(', 'microtime(', 'session_id('] as $weak) {
            self::assertStringNotContainsString($weak, $source, 'A token must not be derived from ' . $weak);
        }
    }

    public function testComparisonIsConstantTime(): void
    {
        $source = self::code('CsrfGuard');

        self::assertStringContainsString('hash_equals(', $source);
        self::assertStringNotContainsString('$expected === $submitted', $source);
        self::assertStringNotContainsString('strcmp(', $source);
    }

    public function testAValidTokenIsAccepted(): void
    {
        $guard = new CsrfGuard();
        $session = new ArraySession();

        self::assertTrue($guard->isValid($session, $guard->token($session)));
    }

    /**
     * Every way a token can fail to be the right one, in one place.
     */
    public function testEveryWrongTokenIsRefused(): void
    {
        $guard = new CsrfGuard();
        $session = new ArraySession();
        $token = $guard->token($session);

        foreach ([
            'absent' => null,
            'empty' => '',
            'wrong' => str_repeat('0', 64),
            'truncated' => substr($token, 0, 63),
            'extended' => $token . '0',
            'prefix only' => substr($token, 0, 8),
            'upper cased' => strtoupper($token),
        ] as $why => $candidate) {
            self::assertFalse($guard->isValid($session, $candidate), $why . ' must be refused');
        }
    }

    /**
     * The fail-closed case: a session that was never issued a token refuses
     * everything, including the empty string that a naive `===` against a null
     * expectation would accept.
     */
    public function testASessionWithNoIssuedTokenRefusesEverything(): void
    {
        $guard = new CsrfGuard();
        $session = new ArraySession();

        foreach ([null, '', '0', str_repeat('a', 64)] as $candidate) {
            self::assertFalse($guard->isValid($session, $candidate));
        }
    }

    public function testATokenIsNotValidInAnotherSession(): void
    {
        $guard = new CsrfGuard();

        $mine = new ArraySession();
        $theirs = new ArraySession();

        $token = $guard->token($mine);
        $guard->token($theirs);

        self::assertFalse($guard->isValid($theirs, $token), 'A token is bound to the session it was issued to');
    }

    public function testRotationReplacesTheTokenAndInvalidatesTheOldOne(): void
    {
        $guard = new CsrfGuard();
        $session = new ArraySession();

        $before = $guard->token($session);
        $after = $guard->rotate($session);

        self::assertNotSame($before, $after);
        self::assertFalse($guard->isValid($session, $before), 'The rotated-out token must stop working');
        self::assertTrue($guard->isValid($session, $after));
        self::assertSame($after, $guard->token($session), 'The new token is now the issued one');
    }
}
