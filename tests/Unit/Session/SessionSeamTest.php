<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Session;

use Facet\Session\ArraySession;
use Facet\Session\PhpSession;
use Facet\Session\Session;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * The session seam, and the boundary it is supposed to keep.
 *
 * Two things are asserted. The store behaves like a store — including the flash
 * idiom, where a value read once must be gone, because a confirmation that
 * survives its own reading is how a PRG page keeps announcing a message that
 * was already acknowledged.
 *
 * And the seam stays a seam: exactly one class in `src/` may touch PHP's
 * session machinery or a superglobal, so the application above it remains a
 * pure function of its Request. That is a structural property, and a structural
 * test is the only kind that keeps holding as the code grows.
 */
final class SessionSeamTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * A file's code with every comment removed, so a structural assertion is
     * about what runs and not about what the prose is free to discuss.
     */
    private static function code(string $path): string
    {
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

    public function testAValueSurvivesUntilItIsForgotten(): void
    {
        $session = new ArraySession();

        self::assertFalse($session->has('k'));
        self::assertNull($session->get('k'));

        $session->put('k', 'v');

        self::assertTrue($session->has('k'));
        self::assertSame('v', $session->get('k'));
        self::assertSame('v', $session->get('k'), 'A plain read is not destructive');

        $session->forget('k');

        self::assertFalse($session->has('k'));
        self::assertNull($session->get('k'));
    }

    public function testAnEmptyStringIsAStoredValueAndNotAnAbsentOne(): void
    {
        $session = new ArraySession();
        $session->put('k', '');

        self::assertTrue($session->has('k'));
        self::assertSame('', $session->get('k'));
    }

    /**
     * The flash contract, which the PRG confirmation depends on entirely.
     */
    public function testPullReadsOnceAndClears(): void
    {
        $session = new ArraySession(['flash' => 'sent']);

        self::assertSame('sent', $session->pull('flash'));
        self::assertNull($session->pull('flash'), 'A flash is consumed by being read');
        self::assertFalse($session->has('flash'));
    }

    public function testPullingSomethingAbsentIsNotAFailure(): void
    {
        self::assertNull((new ArraySession())->pull('nothing'));
    }

    // ------------------------------------------------------- the boundary

    /**
     * `PhpSession` is the only class in the source tree allowed to name PHP's
     * session functions or `$_SESSION`. If a second one appears, the promise
     * that the application is drivable without a SAPI has quietly stopped
     * being true.
     */
    public function testOnlyTheAdapterTouchesPhpSessionMachinery(): void
    {
        $offenders = [];
        $allowed = self::root() . '/src/Session/PhpSession.php';

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::root() . '/src', FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);

            if ($file->getExtension() !== 'php' || $file->getPathname() === $allowed) {
                continue;
            }

            $source = self::code($file->getPathname());

            foreach (['$_SESSION', 'session_start(', 'session_id(', 'session_name(', 'setcookie('] as $forbidden) {
                if (str_contains($source, $forbidden)) {
                    $offenders[] = $file->getFilename() . ' uses ' . $forbidden;
                }
            }
        }

        self::assertSame([], $offenders);
    }

    /**
     * The cookie the adapter configures. Asserted on the source rather than by
     * starting a session, because a started session in a CLI test process is
     * both unavailable and irreversible — and what matters is the parameters,
     * which are set before the start or not at all.
     */
    public function testTheNativeCookieIsHttpOnlyAndSameSiteLax(): void
    {
        $source = self::code(self::root() . '/src/Session/PhpSession.php');

        self::assertStringContainsString("'httponly' => true", $source);
        self::assertStringContainsString("'samesite' => 'Lax'", $source);
        self::assertStringContainsString("'secure' => \$secure", $source);
        self::assertStringContainsString("'path' => '/'", $source);
    }

    /**
     * `Secure` is set exactly when the request really arrived over HTTPS.
     * Unconditionally would make the browser discard the cookie on a plain
     * development origin — a session that silently never persists.
     */
    public function testSecureIsDecidedByTheActualTransport(): void
    {
        self::assertTrue(PhpSession::isSecureRequest(['HTTPS' => 'on']));
        self::assertTrue(PhpSession::isSecureRequest(['HTTPS' => '1']));
        self::assertTrue(PhpSession::isSecureRequest(['SERVER_PORT' => '443']));

        self::assertFalse(PhpSession::isSecureRequest([]));
        self::assertFalse(PhpSession::isSecureRequest(['HTTPS' => 'off']));
        self::assertFalse(PhpSession::isSecureRequest(['HTTPS' => '']));
        self::assertFalse(PhpSession::isSecureRequest(['SERVER_PORT' => '80']));

        // A client-supplied forwarding header is not evidence of anything
        // until a proxy is configured to be believed, and none is.
        self::assertFalse(PhpSession::isSecureRequest(['HTTP_X_FORWARDED_PROTO' => 'https']));
    }

    /**
     * The seam is deliberately not an authentication seam. PORT-92 owns login,
     * roles and fixation; introducing any of it here would be building the next
     * checkpoint's surface without its tests.
     */
    public function testTheSeamCarriesNoAuthenticationConcern(): void
    {
        foreach (['Session', 'ArraySession', 'PhpSession'] as $class) {
            $source = self::code(self::root() . '/src/Session/' . $class . '.php');

            foreach (['login', 'logout', 'password', 'role', 'authenticate', 'user_id'] as $concern) {
                self::assertStringNotContainsString(
                    $concern . '(',
                    $source,
                    $class . ' must not grow an authentication surface at this checkpoint'
                );
            }
        }

        // And the interface stays the four operations the contact form needs.
        self::assertSame(
            ['has', 'get', 'put', 'forget', 'pull'],
            array_map(
                static fn (\ReflectionMethod $method): string => $method->getName(),
                (new ReflectionClass(Session::class))->getMethods()
            )
        );
    }
}
