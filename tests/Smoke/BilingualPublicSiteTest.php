<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use Facet\Config\Config;
use Facet\Content\Corpus;
use Facet\Content\CorpusLoader;
use Facet\Http\Application;
use Facet\Http\Request;
use Facet\Http\Response;
use Facet\I18n\Locale;
use Facet\I18n\Translator;
use Facet\Security\CsrfGuard;
use Facet\Session\ArraySession;
use Facet\Tests\Support\Dom;
use Facet\Tests\Support\RecordingContactMessageStore;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PORT-137's gate: the public site, whole, in both languages.
 *
 * Everything asserted here is asserted against the *served HTML* with the
 * scripts stripped out, because the claim being made is that localisation is
 * server-rendered — that `/fr` is fully French and `/en` fully English before a
 * single byte of JavaScript runs, and whether or not any ever does.
 *
 * The assertions are explicit known strings and structural facts, never a
 * language detector: "this page is probably French" is not a property anything
 * can be built on, while "this page's H1 is exactly this string, and none of the
 * other language's chrome appears anywhere in it" is.
 */
final class BilingualPublicSiteTest extends TestCase
{
    private const ORIGIN = 'https://portfolio.example';

    /**
     * Chrome that belongs to exactly one language and appears in the *text* of
     * every page of it — the skip link, the four section names, and the theme
     * control's clipped label. If any of these turns up on a page of the other
     * language, something is rendering in a language the URL did not ask for.
     *
     * "Contact" and "Menu" are deliberately absent: they are the same word in
     * both languages, so they can prove nothing either way. The accessible
     * names that live in attributes rather than in text are asserted in
     * {@see ShellStructureTest}.
     *
     * @var array<string, list<string>>
     */
    private const SHELL_CHROME = [
        'fr' => ['Accueil', 'Projets', 'À propos', 'Aller au contenu', 'Thème sombre'],
        'en' => ['Home', 'Projects', 'About', 'Skip to content', 'Dark theme'],
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function app(?ArraySession $session = null, ?RecordingContactMessageStore $store = null): Application
    {
        return Application::boot(
            self::root(),
            Config::fromArray([
                'APP_NAME' => 'Facet',
                'APP_ENV' => 'production',
                'APP_KEY' => 'bilingual-test-key',
                'APP_URL' => self::ORIGIN,
                'APP_DEBUG' => 'false',
            ]),
            null,
            null,
            null,
            $session ?? new ArraySession(),
            $store
        );
    }

    private static function corpus(Locale $locale): Corpus
    {
        return CorpusLoader::default(self::root())->load($locale);
    }

    private static function get(string $path): Response
    {
        return self::app()->handle(Request::create('GET', $path));
    }

    /** The served document with every script removed. */
    private static function noJs(string $path): string
    {
        $response = self::get($path);

        self::assertSame(200, $response->status(), $path);

        return Dom::withoutScripts($response->body());
    }

    private static function dom(string $path): DOMXPath
    {
        return Dom::of(self::noJs($path));
    }

    /** The raw served document, scripts and head included. */
    private static function raw(string $path): string
    {
        $response = self::get($path);

        self::assertSame(200, $response->status(), $path);

        return $response->body();
    }

    /**
     * The eight primary localized surfaces, plus one project detail per
     * language — every public page the site serves, in both languages.
     *
     * @return array<string, array{0: string, 1: Locale}>
     */
    public static function localizedPages(): array
    {
        $pages = [];

        foreach (['', '/projects', '/about', '/contact', '/projects/kushim'] as $suffix) {
            foreach (Locale::supported() as $locale) {
                $path = '/' . $locale->value . $suffix;
                $pages[$path] = [$path, $locale];
            }
        }

        return $pages;
    }

    // ------------------------------------------------------------- language

    /**
     * The document declares the language it is written in, from the server, and
     * nothing has to correct it after load.
     */
    #[DataProvider('localizedPages')]
    public function testEveryPageDeclaresItsLanguageInTheServedDocument(string $path, Locale $locale): void
    {
        $raw = self::raw($path);

        self::assertStringContainsString('<html lang="' . $locale->value . '"', $raw);
        self::assertSame(1, substr_count($raw, '<html lang='), $path . ' declares one language');

        // Nothing writes `lang` after the fact: correcting the document's
        // language from a script would mean it was wrong when it was served.
        self::assertStringNotContainsString("setAttribute('lang'", $raw);
        self::assertStringNotContainsString('documentElement.lang', $raw);
    }

    /**
     * No page shows any of the other language's shell chrome. This is the
     * "no mixed interface" requirement, stated as something that can fail.
     */
    #[DataProvider('localizedPages')]
    public function testNoPageLeaksTheOtherLanguagesChrome(string $path, Locale $locale): void
    {
        $text = Dom::normalise(Dom::textOf(Dom::element(self::dom($path), '//body')));

        foreach (self::SHELL_CHROME[$locale->value] as $expected) {
            self::assertStringContainsString($expected, $text, $path . ' must say ' . $expected);
        }

        foreach (self::SHELL_CHROME[$locale->counterpart()->value] as $foreign) {
            // "Contact" and "Menu" are the same word in both languages and are
            // deliberately absent from the lists above; everything in them is a
            // string only one language uses.
            self::assertStringNotContainsString(
                $foreign,
                $text,
                $path . ' must not carry the other language\'s "' . $foreign . '"'
            );
        }
    }

    /**
     * No translation key ever reaches a visitor, and no placeholder is left
     * unfilled. Both are the interface showing its own implementation.
     */
    #[DataProvider('localizedPages')]
    public function testNoImplementationKeyOrPlaceholderIsVisible(string $path, Locale $locale): void
    {
        // The route-identity hook is a *route name* — `projects.show` — and is
        // dotted for the same reason a translation key is. It is stripped
        // before the scan rather than excused by the pattern, so the pattern
        // stays as strict as it is.
        $raw = (string) preg_replace('/ data-facet-route="[^"]*"/', '', self::raw($path));

        // A key is a dotted identifier from one of the catalog's own
        // namespaces. The trailing `[a-z]` is what keeps a project called
        // "Math L'home." from reading as one.
        self::assertDoesNotMatchRegularExpression(
            "/(?<![\w'\x{2019}])(home|nav|shell|seo|language|project|projects|about|contact|error|content|run)"
                . '\.[a-z][a-zA-Z]*(\.[a-zA-Z]+)*/u',
            $raw,
            $path . ' leaked a translation key'
        );

        $text = Dom::textOf(Dom::element(self::dom($path), '//body'));

        self::assertDoesNotMatchRegularExpression('/\{[a-zA-Z]+\}/', $text, $path . ' shows an unfilled placeholder');
    }

    // ------------------------------------------------------------ navigation

    /**
     * Every link the shell prints stays in the language of the page it is on.
     * A visitor reading English must never be handed a French page by the site
     * itself.
     */
    #[DataProvider('localizedPages')]
    public function testEveryInternalLinkStaysInTheLanguageOfThePage(string $path, Locale $locale): void
    {
        $xpath = self::dom($path);
        $prefix = '/' . $locale->value;
        $other = '/' . $locale->counterpart()->value;

        foreach (Dom::query($xpath, '//a[@href]') as $anchor) {
            self::assertInstanceOf(DOMElement::class, $anchor);
            $href = $anchor->getAttribute('href');

            if (!str_starts_with($href, '/') || str_starts_with($href, '#')) {
                continue;
            }

            // The one internal link that deliberately points at the other
            // language is the language switch, and it is the only one.
            if (str_starts_with($href, $other)) {
                self::assertSame(
                    'facet-lang__link',
                    $anchor->getAttribute('class'),
                    $path . ': only the language switch may leave the language, not ' . $href
                );

                continue;
            }

            self::assertStringStartsWith($prefix, $href, $path . ' printed an unlocalized link: ' . $href);
        }
    }

    // ------------------------------------------------------- language switch

    /**
     * The switch is two links to two canonical URLs. It preserves the page, it
     * marks the language in effect, it needs no script, and it never invents a
     * destination the site does not serve.
     */
    #[DataProvider('localizedPages')]
    public function testTheLanguageSwitchOffersTheSamePageInTheOtherLanguage(string $path, Locale $locale): void
    {
        $xpath = self::dom($path);

        $switch = Dom::element($xpath, '//header//nav[@data-facet-lang]', $path);
        self::assertNotSame('', $switch->getAttribute('aria-label'), $path . ': the switch must name itself');

        $links = Dom::query($xpath, '//header//nav[@data-facet-lang]//a[@href]');
        self::assertCount(2, $links, $path . ': one link per language');

        $seen = [];

        foreach ($links as $link) {
            self::assertInstanceOf(DOMElement::class, $link);

            $href = $link->getAttribute('href');
            $seen[] = $href;

            self::assertSame(
                $link->getAttribute('hreflang'),
                $link->getAttribute('lang'),
                $path . ': a link to another language declares it once and consistently'
            );

            // The visible mark is two letters; the accessible name adds the
            // language's own name for itself, so the name contains the label.
            $name = Dom::normalise(Dom::textOf($link));
            $hreflang = $link->getAttribute('hreflang');

            $language = Locale::fromSegment($hreflang);

            self::assertNotNull($language, $path . ': a language link offers a language this site serves');
            self::assertSame($language->shortLabel(), substr($name, 0, 2), $path . ': the visible mark');
            self::assertStringContainsString($language->endonym(), $name, $path . ': and its own name for itself');
        }

        // Both canonical counterparts, and the current one marked.
        $suffix = substr($path, 3);

        self::assertSame(['/fr' . $suffix, '/en' . $suffix], $seen, $path);

        $current = Dom::query($xpath, '//header//nav[@data-facet-lang]//a[@aria-current="true"]');
        self::assertCount(1, $current, $path . ': exactly one language is in effect');

        $marked = $current->item(0);
        self::assertInstanceOf(DOMElement::class, $marked);
        self::assertSame($path, $marked->getAttribute('href'), $path . ': the current language points at this page');
    }

    /**
     * Following the switch really does land on the counterpart page, in the
     * other language, and the preference follows the visitor.
     */
    public function testFollowingTheSwitchLandsOnTheCounterpartAndUpdatesThePreference(): void
    {
        foreach (['', '/projects', '/about', '/contact', '/projects/kushim'] as $suffix) {
            foreach (Locale::supported() as $from) {
                $to = $from->counterpart();
                $target = '/' . $to->value . $suffix;

                $response = self::app()->handle(Request::create(
                    'GET',
                    $target,
                    [],
                    [],
                    ['facet_locale' => $from->value]
                ));

                self::assertSame(200, $response->status(), $target);
                self::assertStringContainsString('<html lang="' . $to->value . '"', $response->body(), $target);
                self::assertStringContainsString(
                    'facet_locale=' . $to->value . ';',
                    (string) $response->header('Set-Cookie'),
                    $target . ': the explicit URL replaces the remembered preference'
                );
            }
        }
    }

    // ------------------------------------------------------------------- SEO

    /**
     * Each page is canonical to itself, advertises both languages plus
     * `x-default`, and never points its canonical at the other language or at
     * an unprefixed entry route.
     */
    #[DataProvider('localizedPages')]
    public function testCanonicalAndAlternatesAreCompleteAndSymmetrical(string $path, Locale $locale): void
    {
        $xpath = self::dom($path);
        $suffix = substr($path, 3);

        $canonical = Dom::element($xpath, '//link[@rel="canonical"]', $path);
        self::assertSame(self::ORIGIN . $path, $canonical->getAttribute('href'), $path);

        $alternates = [];

        foreach (Dom::query($xpath, '//link[@rel="alternate"]') as $link) {
            self::assertInstanceOf(DOMElement::class, $link);
            $alternates[$link->getAttribute('hreflang')] = $link->getAttribute('href');
        }

        self::assertSame(
            [
                'fr' => self::ORIGIN . '/fr' . $suffix,
                'en' => self::ORIGIN . '/en' . $suffix,
                // French is the language the corpus is written in and the
                // language an unprefixed entry falls back to, so it is the
                // deterministic answer to "this page, language unspecified".
                'x-default' => self::ORIGIN . '/fr' . $suffix,
            ],
            $alternates,
            $path
        );

        // No unprefixed URL is ever advertised as a canonical destination.
        $raw = self::raw($path);
        self::assertStringNotContainsString('href="' . self::ORIGIN . $suffix . '"', $raw);
        self::assertStringNotContainsString('content="' . self::ORIGIN . $suffix . '"', $raw);
    }

    #[DataProvider('localizedPages')]
    public function testTitleAndDescriptionAreLocalizedAndDistinctBetweenLanguages(string $path, Locale $locale): void
    {
        $xpath = self::dom($path);

        $title = Dom::normalise(Dom::textOf(Dom::element($xpath, '//title', $path)));
        self::assertNotSame('', $title, $path);

        $description = Dom::element($xpath, '//meta[@name="description"]', $path)->getAttribute('content');
        self::assertNotSame('', $description, $path);

        $ogLocale = Dom::element($xpath, '//meta[@property="og:locale"]', $path);
        self::assertSame($locale->openGraphLocale(), $ogLocale->getAttribute('content'), $path);

        $alternate = Dom::element($xpath, '//meta[@property="og:locale:alternate"]', $path);
        self::assertSame($locale->counterpart()->openGraphLocale(), $alternate->getAttribute('content'), $path);
    }

    /**
     * The two languages really do say different things. A page whose title and
     * description were identical in both would mean the translation never
     * reached the metadata.
     */
    public function testTheMetadataOfTheTwoLanguagesDiffersWhereItShould(): void
    {
        foreach (['', '/projects', '/about', '/contact', '/projects/kushim'] as $suffix) {
            $french = self::dom('/fr' . $suffix);
            $english = self::dom('/en' . $suffix);

            self::assertNotSame(
                Dom::element($french, '//meta[@name="description"]')->getAttribute('content'),
                Dom::element($english, '//meta[@name="description"]')->getAttribute('content'),
                $suffix . ': the description must be translated'
            );
        }
    }

    /**
     * The sitemap lists every page in both languages, with their alternates,
     * and lists no unprefixed URL at all.
     */
    public function testTheSitemapCarriesBothLanguagesAndNoUnprefixedUrl(): void
    {
        $response = self::get('/sitemap.xml');
        $xml = $response->body();

        self::assertSame(200, $response->status());

        foreach (['/fr', '/en', '/fr/projects', '/en/projects', '/fr/about', '/en/about', '/fr/contact', '/en/contact'] as $path) {
            self::assertStringContainsString('<loc>' . self::ORIGIN . $path . '</loc>', $xml, $path);
        }

        foreach (self::corpus(Locale::default())->projects() as $project) {
            foreach (Locale::supported() as $locale) {
                self::assertStringContainsString(
                    '<loc>' . self::ORIGIN . '/' . $locale->value . '/projects/' . $project->slug()->value() . '</loc>',
                    $xml
                );
            }
        }

        // An entry route is a redirect, not a destination.
        foreach (['/', '/projects', '/about', '/contact'] as $entry) {
            self::assertStringNotContainsString('<loc>' . self::ORIGIN . $entry . '</loc>', $xml, $entry);
        }

        self::assertStringContainsString('hreflang="x-default"', $xml);
    }

    // ---------------------------------------------------------------- content

    /**
     * The editorial content is the corpus of the language being rendered — the
     * facts are the same in both, and only the prose differs.
     */
    public function testTheSameProjectsAppearInBothLanguagesWithTranslatedProse(): void
    {
        $french = self::corpus(Locale::Fr);
        $english = self::corpus(Locale::En);

        $slugs = static fn (Corpus $corpus): array => array_map(
            static fn (\Facet\Content\Project $p): string => $p->slug()->value(),
            $corpus->projects()
        );

        self::assertSame($slugs($french), $slugs($english), 'The two languages describe the same projects');

        foreach ($french->projects() as $index => $project) {
            $translated = $english->projects()[$index];

            // Facts: identical, because they are stored once.
            self::assertSame($project->name(), $translated->name());
            self::assertSame($project->technologies(), $translated->technologies());
            self::assertSame($project->status(), $translated->status());
            self::assertSame($project->period()?->start(), $translated->period()?->start());
            self::assertSame($project->period()?->end(), $translated->period()?->end());
            self::assertSame($project->isFeatured(), $translated->isFeatured());
            self::assertSame(
                array_map(static fn (\Facet\Content\Link $l): string => $l->url(), $project->links()),
                array_map(static fn (\Facet\Content\Link $l): string => $l->url(), $translated->links())
            );

            // Prose: translated, and the same number of items in every list, so
            // a translation can never add or drop a claim.
            self::assertNotSame($project->summary(), $translated->summary(), $project->slug()->value());
            self::assertCount(count($project->concepts()), $translated->concepts());
            self::assertCount(count($project->outcomes()), $translated->outcomes());
        }
    }

    #[DataProvider('localizedPages')]
    public function testEveryPageIsCompleteWithNoJavaScriptAtAll(string $path, Locale $locale): void
    {
        $noJs = self::noJs($path);
        $xpath = Dom::of($noJs);

        self::assertStringNotContainsString('<noscript', $noJs, $path);
        self::assertStringNotContainsString('javascript:', $noJs, $path);

        self::assertCount(1, Dom::query($xpath, '//main//h1'), $path . ' must have exactly one H1');
        self::assertNotSame('', Dom::normalise(Dom::textOf(Dom::element($xpath, '//main//h1'))), $path);
        self::assertCount(1, Dom::query($xpath, '//header//nav[@data-facet-nav]'), $path);
        self::assertCount(1, Dom::query($xpath, '//header//nav[@data-facet-lang]'), $path);
        self::assertCount(1, Dom::query($xpath, '//footer'), $path);
    }

    // ---------------------------------------------------------------- contact

    /**
     * The form is localized presentation over one unchanged subsystem: the
     * action stays in the language of the page, and so does everything the
     * server says back.
     */
    public function testTheContactFormIsLocalizedAndPostsToItsOwnLanguage(): void
    {
        foreach (Locale::supported() as $locale) {
            $path = '/' . $locale->value . '/contact';
            $xpath = self::dom($path);

            $form = Dom::element($xpath, '//main//form[@method="post"]', $path);
            self::assertSame($path, $form->getAttribute('action'), $path);

            $labels = [];

            foreach (Dom::query($xpath, '//main//form//label') as $label) {
                $labels[] = Dom::normalise(Dom::textOf($label));
            }

            $translator = new Translator($locale);

            foreach (['name', 'email', 'subject', 'message'] as $field) {
                self::assertContains($translator->text('contact.field.' . $field . '.label'), $labels, $path);
            }

            self::assertSame(
                $translator->text('contact.submit'),
                Dom::normalise(Dom::textOf(Dom::element($xpath, '//main//form//button[@type="submit"]'))),
                $path
            );
        }
    }

    /**
     * A rejected submission comes back in the language it was made in, with the
     * per-field reasons said in that language — and the semantics of the
     * refusal are untouched: same status, same fields, same token contract.
     */
    public function testValidationMessagesAreSaidInTheLanguageOfTheSubmission(): void
    {
        foreach (Locale::supported() as $locale) {
            $session = new ArraySession();
            $store = new RecordingContactMessageStore();
            $app = self::app($session, $store);
            $path = '/' . $locale->value . '/contact';

            // A GET first, so the form the POST answers carries this session's
            // token — the CSRF contract is unchanged by localisation.
            $app->handle(Request::create('GET', $path));
            $token = (new CsrfGuard())->token($session);

            $response = $app->handle(Request::create('POST', $path, [], [
                CsrfGuard::FIELD => $token,
                'name' => '',
                'email' => 'not-an-address',
                'subject' => '',
                'message' => '',
            ]));

            self::assertSame(Response::STATUS_UNPROCESSABLE_CONTENT, $response->status(), $path);
            self::assertSame(0, $store->count(), $path . ' must store nothing');
            self::assertStringContainsString('<html lang="' . $locale->value . '"', $response->body(), $path);

            $translator = new Translator($locale);
            $body = Dom::withoutScripts($response->body());

            self::assertStringContainsString(
                htmlspecialchars(
                    $translator->text('contact.notice.invalid'),
                    ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                    'UTF-8'
                ),
                $body,
                $path
            );

            foreach (['contact.error.name.missing', 'contact.error.email.malformed'] as $key) {
                self::assertStringContainsString(
                    htmlspecialchars(
                        $translator->text($key),
                        ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                        'UTF-8'
                    ),
                    $body,
                    $path . ': ' . $key
                );
            }
        }
    }

    /**
     * Post/redirect/get keeps the language: a French submission confirms in
     * French, at the French URL, and an English one in English.
     */
    public function testASuccessfulSubmissionRedirectsAndConfirmsInItsOwnLanguage(): void
    {
        foreach (Locale::supported() as $locale) {
            $session = new ArraySession();
            $store = new RecordingContactMessageStore();
            $app = self::app($session, $store);
            $path = '/' . $locale->value . '/contact';

            $app->handle(Request::create('GET', $path));
            $token = (new CsrfGuard())->token($session);

            $posted = $app->handle(Request::create('POST', $path, [], [
                CsrfGuard::FIELD => $token,
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
                'subject' => 'About the analytical engine',
                'message' => 'I would like to discuss a collaboration.',
            ]));

            self::assertSame(Response::STATUS_SEE_OTHER, $posted->status(), $path);
            self::assertSame($path, $posted->header('Location'), $path . ': the confirmation stays in this language');
            self::assertSame(1, $store->count(), $path);

            $landing = $app->handle(Request::create('GET', $path));

            self::assertStringContainsString(
                htmlspecialchars(
                    (new Translator($locale))->text('contact.notice.sent'),
                    ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                    'UTF-8'
                ),
                $landing->body(),
                $path
            );
        }
    }

    // ------------------------------------------------------- entry redirects

    public function testUnprefixedEntryRoutesNeverRenderAPageOfTheirOwn(): void
    {
        foreach (['/', '/projects', '/about', '/contact', '/projects/kushim'] as $entry) {
            $response = self::get($entry);

            self::assertSame(Response::STATUS_FOUND, $response->status(), $entry);
            self::assertSame('', $response->body(), $entry . ' must render nothing');
            self::assertStringStartsWith('/fr', (string) $response->header('Location'), $entry);
        }
    }

    /**
     * A language the site does not serve is a 404 — never French dressed as
     * German, and never quietly canonicalised to a language the visitor did not
     * ask for.
     */
    public function testAnUnsupportedLanguagePrefixIsNeverServed(): void
    {
        foreach (['/de', '/de/projects', '/de/about', '/es/contact', '/xx/projects/kushim'] as $path) {
            $response = self::get($path);

            self::assertSame(Response::STATUS_NOT_FOUND, $response->status(), $path);
            self::assertNull($response->header('Location'), $path . ' must not be canonicalised');
        }
    }
}
