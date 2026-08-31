<?php

declare(strict_types=1);

namespace Facet\I18n;

/**
 * The remembered public language, as one minimal cookie.
 *
 * It holds a two-letter value from a closed set and nothing else: no identifier,
 * no session, no personal data. Its only job is to let an unprefixed entry URL —
 * `/`, `/projects` — send a returning visitor to the language they last read the
 * site in, and it is deliberately never consulted on a URL that already names a
 * locale.
 *
 * `HttpOnly` because no script on this site reads it. The locale is decided on
 * the server, rendered into the HTML and carried by the links in the page, so a
 * cookie the client can read would only be a value that could disagree with the
 * page around it. `SameSite=Lax` because it must survive an ordinary
 * cross-origin link into the site — that is precisely the case it exists for —
 * and `Secure` follows the deployment's own canonical scheme rather than a
 * request header a client can set.
 */
final class LocalePreference
{
    /**
     * The cookie's name, and the underscore in it is not a style choice.
     *
     * PHP rewrites `.` to `_` in the keys of `$_COOKIE` — a survival from the
     * register_globals era that still applies — so a cookie actually named
     * `facet.locale` arrives as `$_COOKIE['facet_locale']` and a server looking
     * for the name it set would never find it. The preference would be written
     * on every request and read on none.
     *
     * It is also what the rest of this application already does: the session
     * cookie is `facet_session` and the skin preference is `facet_skin`.
     */
    public const COOKIE = 'facet_locale';

    /** A year. Long enough to be a preference, short enough to expire. */
    public const MAX_AGE = 31_536_000;

    /**
     * The remembered locale, or null when nothing valid is remembered.
     *
     * An unknown value is not repaired and not partially matched: a cookie
     * saying `de`, `fr-CA` or an arbitrary string is simply not a preference
     * this site stored, so it decides nothing.
     *
     * @param array<string, string> $cookies
     */
    public static function read(array $cookies): ?Locale
    {
        $value = $cookies[self::COOKIE] ?? null;

        return is_string($value) ? Locale::fromSegment($value) : null;
    }

    /**
     * The `Set-Cookie` value that remembers this locale.
     *
     * Built here rather than through `setcookie()` so it is a value the
     * application returns and a test can assert on in full, exactly like every
     * other header in the response.
     */
    public static function header(Locale $locale, bool $secure): string
    {
        $attributes = [
            self::COOKIE . '=' . $locale->value,
            'Path=/',
            'Max-Age=' . self::MAX_AGE,
            'SameSite=Lax',
            'HttpOnly',
        ];

        if ($secure) {
            $attributes[] = 'Secure';
        }

        return implode('; ', $attributes);
    }
}
