<?php

declare(strict_types=1);

namespace Facet\Session;

/**
 * The per-visitor store, and the lifecycle of the identifier it hangs from.
 *
 * Deliberately a string map plus two lifecycle operations, and nothing more. A
 * CSRF token, a one-shot flash, a throttle window and — since PORT-92 — the id
 * of the signed-in account are all this application has to remember, and a
 * narrow interface is what keeps it testable: {@see ArraySession} is a complete
 * implementation in a few dozen lines, so every session-dependent branch is
 * exercised in process with no SAPI, no cookie and no `session_start()`.
 *
 * What is *not* here is as deliberate as what is. There is no notion of a user,
 * a role or a password anywhere in this namespace: the seam stores strings and
 * manages an identifier, and {@see \Facet\Auth\Authenticator} is the only thing
 * that knows one of those strings means "signed in". Roles are never read from
 * here at all — they are re-resolved from the stored account on every request.
 */
interface Session
{
    public function has(string $key): bool;

    public function get(string $key): ?string;

    public function put(string $key, string $value): void;

    public function forget(string $key): void;

    /**
     * Read a value and remove it in the same breath — the flash idiom.
     *
     * It is one method rather than a get/forget pair because a flash that is
     * read and not cleared is exactly the bug that makes a PRG confirmation
     * reappear on the next page view.
     */
    public function pull(string $key): ?string;

    /**
     * Issue a new identifier for this session, keeping the data it holds.
     *
     * This is the fixation defence, and it has exactly one correct moment: the
     * instant a session's privilege changes, before the new privilege is
     * written into it. An identifier an attacker planted in the victim's
     * browser before login must not be the identifier that is authenticated
     * after it, and re-keying is the only way to guarantee that.
     *
     * Data is preserved on purpose. The token the login form was submitted
     * with, and anything else the anonymous visit accumulated, belongs to the
     * same person; it is the *name* of the session that is untrusted, not its
     * contents.
     */
    public function regenerate(): void;

    /**
     * End this session: forget everything, and make the identifier unusable.
     *
     * Stronger than clearing the keys one by one, and that is the point of it
     * being on the interface rather than being a loop in the caller. A logout
     * that only removed the authentication key would leave a live session the
     * old cookie still names; an implementation of this method must leave the
     * old cookie naming nothing at all.
     */
    public function destroy(): void;
}
