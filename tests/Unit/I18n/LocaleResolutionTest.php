<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\I18n;

use Facet\Http\Request;
use Facet\I18n\Locale;
use Facet\I18n\LocalePreference;
use Facet\I18n\LocaleResolver;
use PHPUnit\Framework\TestCase;

/**
 * The precedence an unprefixed entry URL is answered by: a remembered
 * preference, then the browser's own header, then French.
 *
 * What is *not* here is as much the contract as what is: no IP address, no
 * country, no network call, and no reading of a URL — a URL that names its
 * language never reaches this class at all, which is asserted where it belongs,
 * in the dispatcher.
 */
final class LocaleResolutionTest extends TestCase
{
    /**
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     */
    private static function resolve(array $cookies = [], array $headers = []): Locale
    {
        return (new LocaleResolver())->resolve(
            Request::create('GET', '/', [], [], $cookies, $headers)
        );
    }

    public function testAValidRememberedPreferenceDecides(): void
    {
        self::assertSame(Locale::Fr, self::resolve([LocalePreference::COOKIE => 'fr']));
        self::assertSame(Locale::En, self::resolve([LocalePreference::COOKIE => 'en']));
    }

    public function testTheRememberedPreferenceBeatsTheBrowsersHeader(): void
    {
        self::assertSame(
            Locale::En,
            self::resolve([LocalePreference::COOKIE => 'en'], ['accept-language' => 'fr-FR,fr;q=0.9'])
        );

        self::assertSame(
            Locale::Fr,
            self::resolve([LocalePreference::COOKIE => 'fr'], ['accept-language' => 'en-US,en;q=0.9'])
        );
    }

    /**
     * A cookie holding anything but a supported language decides nothing. It is
     * not repaired, not partially matched, and not an error: the site simply
     * falls through to the next signal, which is what keeps a tampered or a
     * stale cookie from being able to break the site.
     */
    public function testAnInvalidRememberedPreferenceDecidesNothing(): void
    {
        foreach (['de', 'zz', 'fr-FR', 'FR', '', 'true', '../../etc/passwd'] as $stored) {
            self::assertSame(
                Locale::En,
                self::resolve([LocalePreference::COOKIE => $stored], ['accept-language' => 'en']),
                $stored
            );

            self::assertSame(Locale::Fr, self::resolve([LocalePreference::COOKIE => $stored]), $stored);
        }
    }

    public function testWithNoPreferenceTheBrowsersHeaderDecides(): void
    {
        self::assertSame(Locale::En, self::resolve([], ['accept-language' => 'en-US,en;q=0.9,fr;q=0.8']));
        self::assertSame(Locale::Fr, self::resolve([], ['accept-language' => 'fr-FR,fr;q=0.9,en;q=0.8']));
        self::assertSame(Locale::En, self::resolve([], ['accept-language' => 'de-DE,de;q=0.9,en;q=0.8']));
    }

    public function testEverythingElseFallsBackToFrench(): void
    {
        self::assertSame(Locale::Fr, self::resolve());
        self::assertSame(Locale::Fr, self::resolve([], ['accept-language' => 'de-DE']));
        self::assertSame(Locale::Fr, self::resolve([], ['accept-language' => '']));
        self::assertSame(Locale::Fr, self::resolve([], ['accept-language' => '@@@ malformed ???']));
    }

    /**
     * The cookie is one value from a closed set, and nothing else. It carries
     * no identifier, no session and no personal data — it exists so a returning
     * visitor lands in the language they last read the site in.
     */
    public function testThePreferenceCookieIsMinimalAndBounded(): void
    {
        // Not `facet.locale`: PHP rewrites `.` to `_` in the keys of
        // `$_COOKIE`, so a dotted name is one the server can set and can
        // never read back. The rest of the application already uses
        // underscores — `facet_session`, `facet_skin` — for the same reason.
        self::assertSame('facet_locale', LocalePreference::COOKIE);
        self::assertStringNotContainsString('.', LocalePreference::COOKIE);

        $header = LocalePreference::header(Locale::En, false);

        self::assertStringStartsWith('facet_locale=en;', $header);
        self::assertStringContainsString('Path=/', $header);
        self::assertStringContainsString('SameSite=Lax', $header);
        self::assertStringContainsString('Max-Age=' . LocalePreference::MAX_AGE, $header);

        // No script on this site reads it: the language is decided on the
        // server and carried by the links in the page.
        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringNotContainsString('Secure', $header);

        // Secure follows the deployment's own canonical scheme, never a header
        // a client can set.
        self::assertStringContainsString('Secure', LocalePreference::header(Locale::En, true));
    }

    public function testOnlyASupportedLanguageCanBeRemembered(): void
    {
        foreach (Locale::supported() as $locale) {
            self::assertSame(
                $locale,
                LocalePreference::read([LocalePreference::COOKIE => $locale->value])
            );
        }

        self::assertNull(LocalePreference::read([]));
        self::assertNull(LocalePreference::read([LocalePreference::COOKIE => 'de']));
    }
}
