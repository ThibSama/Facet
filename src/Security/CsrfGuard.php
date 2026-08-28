<?php

declare(strict_types=1);

namespace Facet\Security;

use Facet\Session\Session;

/**
 * Session-linked CSRF tokens.
 *
 * The property being defended is narrow and worth stating exactly: a POST is
 * accepted only when it carries a secret that was issued to *this* session and
 * that an attacker's page cannot read. Three details make that true rather than
 * approximately true.
 *
 * The token is `random_bytes()`, not `uniqid()`, `mt_rand()` or a hash of
 * anything predictable — a token an attacker can compute is not a token.
 *
 * Comparison is {@see hash_equals()}, so the time taken to reject a wrong token
 * does not depend on how much of it was right. A byte-at-a-time `===` on a
 * secret is a side channel even when the surrounding code looks correct.
 *
 * And verification fails closed on *absence*: no token in the session means no
 * token was ever issued to this visitor, which is refused rather than treated
 * as "nothing to compare against, so let it through".
 */
final class CsrfGuard
{
    /** The session key the current token lives under. */
    public const SESSION_KEY = 'csrf.token';

    /** The form field the token is submitted in. */
    public const FIELD = '_token';

    /** 32 bytes of entropy, hex-encoded to 64 characters. */
    private const BYTES = 32;

    /**
     * The token for this session, minting one if the session has none.
     *
     * Idempotent within a session: rendering the form twice does not invalidate
     * the copy the visitor already has open in another tab.
     */
    public function token(Session $session): string
    {
        $existing = $session->get(self::SESSION_KEY);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        return $this->rotate($session);
    }

    /**
     * Replace the token with a fresh one and return it.
     *
     * Called after a submission is accepted, so the exact bytes that authorised
     * one insert cannot authorise a second.
     */
    public function rotate(Session $session): string
    {
        $token = bin2hex(random_bytes(self::BYTES));

        $session->put(self::SESSION_KEY, $token);

        return $token;
    }

    /**
     * Whether a submitted value matches the token issued to this session.
     */
    public function isValid(Session $session, ?string $submitted): bool
    {
        $expected = $session->get(self::SESSION_KEY);

        if (!is_string($expected) || $expected === '') {
            return false;
        }

        if (!is_string($submitted) || $submitted === '') {
            return false;
        }

        return hash_equals($expected, $submitted);
    }
}
