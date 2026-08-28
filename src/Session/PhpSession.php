<?php

declare(strict_types=1);

namespace Facet\Session;

/**
 * The adapter, and the only file in Facet that touches PHP's session machinery.
 *
 * Every side effect the native session has — the cookie, the file on disk, the
 * `$_SESSION` superglobal — is contained here and is started from the
 * entrypoint. Nothing above this class knows a session cookie exists, which is
 * what keeps {@see \Facet\Http\Application} a pure function of its Request even
 * though the contact form is now stateful.
 *
 * The cookie is configured before the session starts, because
 * `session_set_cookie_params()` has no effect afterwards:
 *
 * - `HttpOnly`, so a script cannot read the identifier.
 * - `SameSite=Lax`, so the identifier is not sent on a cross-site POST. That is
 *   a second, independent line behind the CSRF token rather than a replacement
 *   for it.
 * - `Secure` whenever the request actually arrived over HTTPS. It is
 *   conditional and not unconditional on purpose: setting it on a plain-HTTP
 *   development origin makes the browser discard the cookie, and a session that
 *   silently never persists is worse than one that is honest about its
 *   transport.
 *
 * Since PORT-92 the identifier's whole lifecycle lives here too. Regeneration,
 * server-side destruction and the expiry of the cookie itself are three
 * different mechanisms, and all three are needed for a logout to mean anything
 * — so all three are in this file and in no other, behind the two lifecycle
 * methods {@see Session} declares.
 */
final class PhpSession implements Session
{
    public const COOKIE_NAME = 'facet_session';

    /** Two hours: long enough to write a message, short enough to expire. */
    private const LIFETIME_SECONDS = 7200;

    private function __construct()
    {
    }

    /**
     * Start (or resume) the native session and return it as a {@see Session}.
     *
     * Returns null rather than throwing when the SAPI cannot carry a session at
     * all — the CLI, or a request whose headers have already gone out. The
     * caller then falls back to a non-persistent session, and the CSRF guard
     * refuses the POST instead of accepting it without state.
     */
    public static function start(bool $secure): ?self
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return null;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            return new self();
        }

        if (session_status() === PHP_SESSION_DISABLED) {
            return null;
        }

        session_name(self::COOKIE_NAME);
        session_set_cookie_params([
            'lifetime' => self::LIFETIME_SECONDS,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return session_start() ? new self() : null;
    }

    /**
     * Whether the request that is being answered arrived over HTTPS.
     *
     * The server array is a parameter for the same reason it is one on
     * {@see \Facet\Http\Request::fromGlobals()}: a function that reads
     * `$_SERVER` itself cannot be exercised for a transport the test process
     * does not have. Only the direct signals are trusted — a forwarding header
     * is client-supplied unless a proxy is configured to be believed, and none
     * is at this checkpoint.
     *
     * @param array<array-key, mixed> $server
     */
    public static function isSecureRequest(array $server): bool
    {
        $https = $server['HTTPS'] ?? null;

        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        return ($server['SERVER_PORT'] ?? null) === '443';
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function get(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;

        // Anything that is not a string was not written through this seam, and
        // is not something the caller's contract allows us to hand back.
        return is_string($value) ? $value : null;
    }

    public function put(string $key, string $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function pull(string $key): ?string
    {
        $value = $this->get($key);

        $this->forget($key);

        return $value;
    }

    /**
     * Re-key the session, destroying the file the old identifier named.
     *
     * `true` is the argument that matters. Without it the previous session
     * record is left on the server, and an attacker holding the old identifier
     * keeps a live, still-readable session — which is most of what fixation was
     * trying to achieve in the first place. With it, the old name refers to
     * nothing the moment this returns.
     *
     * PHP moves `$_SESSION` across for us, so the CSRF token minted for the
     * login form survives the change of identifier.
     */
    public function regenerate(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        session_regenerate_id(true);
    }

    /**
     * End the session on the server *and* in the browser.
     *
     * Three steps, none of which is redundant. Emptying `$_SESSION` drops the
     * data this request could still read. `session_destroy()` removes the
     * record the identifier names, so replaying the old cookie resumes nothing.
     * And re-sending the cookie with a past expiry is what stops the browser
     * from presenting a name that no longer exists on every subsequent request.
     *
     * The expiry cookie is sent with the same path, domain and flags the
     * session cookie was set with: a browser matches on those attributes, and a
     * deletion that differs in any of them simply sets a second cookie beside
     * the one it meant to remove.
     */
    public function destroy(): void
    {
        $_SESSION = [];

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $parameters = session_get_cookie_params();

        // Headers already sent means the cookie cannot be expired; the session
        // is still destroyed below, so the identifier the browser keeps
        // presenting names nothing.
        if (!headers_sent()) {
            setcookie(self::COOKIE_NAME, '', [
                'expires' => 1,
                'path' => $parameters['path'],
                'domain' => $parameters['domain'],
                'secure' => $parameters['secure'],
                'httponly' => $parameters['httponly'],
                'samesite' => $parameters['samesite'],
            ]);
        }

        session_destroy();
    }
}
