<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\I18n;

use Facet\I18n\Locale;
use PHPUnit\Framework\TestCase;

/**
 * The supported-language set, which is closed on purpose.
 *
 * Every other part of the i18n layer reads this enum rather than a list of its
 * own — the route parameter, the cookie, the translator, the sitemap and the
 * `hreflang` block all do — so what this file pins is the one place a third
 * language could ever appear.
 */
final class LocaleTest extends TestCase
{
    public function testExactlyTwoLanguagesAreSupportedAndFrenchIsTheDefault(): void
    {
        self::assertSame([Locale::Fr, Locale::En], Locale::supported());
        self::assertSame(Locale::Fr, Locale::default());
        self::assertCount(2, Locale::cases());
    }

    public function testASegmentResolvesOnlyWhenItIsExactlyASupportedLanguage(): void
    {
        self::assertSame(Locale::Fr, Locale::fromSegment('fr'));
        self::assertSame(Locale::En, Locale::fromSegment('en'));

        // Deliberately unforgiving. Repairing "FR" or "fr-FR" here would create
        // a second spelling of a canonical URL, and "de" must stay a 404 rather
        // than becoming French under a German-looking address.
        foreach (['FR', 'EN', 'fr-FR', 'de', 'es', '', ' fr', 'fr ', 'français'] as $rejected) {
            self::assertNull(Locale::fromSegment($rejected), $rejected);
        }
    }

    public function testALanguageTagMatchesOnItsPrimarySubtagOnly(): void
    {
        foreach (['fr', 'FR', 'fr-FR', 'fr-CA', 'fr-BE'] as $tag) {
            self::assertSame(Locale::Fr, Locale::fromLanguageTag($tag), $tag);
        }

        foreach (['en', 'EN', 'en-US', 'en-GB', 'en-AU'] as $tag) {
            self::assertSame(Locale::En, Locale::fromLanguageTag($tag), $tag);
        }

        foreach (['de', 'de-DE', 'es', 'zz', ''] as $tag) {
            self::assertNull(Locale::fromLanguageTag($tag), $tag);
        }
    }

    public function testEveryLanguageDescribesItselfCompletely(): void
    {
        foreach (Locale::supported() as $locale) {
            self::assertSame($locale->value, $locale->htmlLang());
            self::assertSame($locale->value, $locale->segment());
            self::assertSame(strtoupper($locale->value), $locale->shortLabel());
            self::assertNotSame('', $locale->endonym());
            self::assertMatchesRegularExpression('/^[a-z]{2}_[A-Z]{2}$/', $locale->openGraphLocale());
            self::assertNotSame($locale, $locale->counterpart());
            self::assertSame($locale, $locale->counterpart()->counterpart());
        }
    }
}
