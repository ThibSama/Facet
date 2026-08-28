<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use Facet\Config\Config;
use Facet\Http\Application;
use Facet\Http\Request;
use Facet\Tests\Support\Dom;
use PHPUnit\Framework\TestCase;

/**
 * Section entry, and the two things it is not allowed to become.
 *
 * It is not allowed to become the page's scrolling. The browser's vertical
 * scroll is the one interaction a visitor already knows how to perform on
 * every site they have ever used, and a skin that intercepts it trades that
 * for something they have to learn. So the assertions below are mostly
 * negative: no scroll listener, no `scroll-behavior`, no scripted scrolling.
 *
 * And it is not allowed to become a condition of reading. Every rule is gated
 * on an attribute only the runtime writes, so the served document, the
 * reduced-motion document and the printed page all carry no entry state at
 * all.
 *
 * The decision record for both this and the line-measurement question is
 * docs/decisions/PORT-103-section-transitions-and-pretext.md.
 */
final class SectionTransitionTest extends TestCase
{
    private const ROUTES = ['/', '/projects', '/about', '/contact', '/login'];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function html(string $path): string
    {
        $application = Application::boot(self::root(), Config::fromArray([
            'APP_NAME' => 'Facet',
            'APP_ENV' => 'production',
            'APP_KEY' => 'test-key',
            'APP_LOCALE' => 'en',
        ]));

        $response = $application->handle(Request::create('GET', $path));

        self::assertSame(200, $response->status(), $path . ' must render');

        return $response->body();
    }

    private static function read(string $relative): string
    {
        $contents = file_get_contents(self::root() . '/' . $relative);
        self::assertIsString($contents, $relative . ' must be readable');

        return $contents;
    }

    /**
     * Every stylesheet and script the skin and the shell actually ship.
     *
     * @return array<string, string>
     */
    private static function frontendSources(): array
    {
        $files = [];

        foreach (
            [
                'resources/css/app.css',
                'resources/js/app.ts',
                'resources/js/nav.ts',
                'resources/js/theme.ts',
                'resources/skins/evolving-interface/skin.css',
                'resources/skins/evolving-interface/skin.ts',
                'resources/skins/evolving-interface/cards.ts',
                'resources/skins/evolving-interface/ribbons.ts',
                'resources/skins/evolving-interface/reveal.ts',
                'resources/skins/evolving-interface/hero.ts',
            ] as $relative
        ) {
            $files[$relative] = self::read($relative);
        }

        return $files;
    }

    // ------------------------------------------------- scrolling stays native

    /**
     * Nothing anywhere in the frontend listens for a scroll, and nothing
     * declares a scroll behaviour. Both halves matter: a scroll handler is the
     * layout-polling this checkpoint forbids, and `scroll-behavior: smooth` is
     * the hijack.
     */
    public function testNothingListensForOrOverridesScrolling(): void
    {
        foreach (self::frontendSources() as $relative => $source) {
            self::assertStringNotContainsString(
                "addEventListener('scroll'",
                $source,
                $relative . ' must not poll the scroll position'
            );
            self::assertStringNotContainsString(
                'onscroll',
                $source,
                $relative . ' must not poll the scroll position'
            );
            /*
             * `scroll-behavior: smooth` is the hijack. `scroll-behavior: auto`
             * is the opposite — the shell's reduced-motion block uses it to
             * *undo* smoothness — so the assertion names the value, not the
             * property.
             */
            self::assertStringNotContainsString(
                'scroll-behavior: smooth',
                $source,
                $relative . ' must leave scrolling to the browser'
            );
            self::assertStringNotContainsString(
                'scrollTo(',
                $source,
                $relative . ' must not move the viewport'
            );
            self::assertStringNotContainsString(
                'scrollIntoView(',
                $source,
                $relative . ' must not move the viewport'
            );
        }
    }

    /**
     * The observer is the mechanism, and it is the only one. A rendered
     * document that could be intercepted by a wheel handler would fail this
     * before any of the visual assertions mattered.
     */
    public function testTheMechanismIsAnIntersectionObserver(): void
    {
        $reveal = self::read('resources/skins/evolving-interface/reveal.ts');

        self::assertStringContainsString('new IntersectionObserver(', $reveal);
        self::assertStringContainsString('observer.disconnect();', $reveal, 'The observer must be releasable');
        self::assertStringContainsString(
            'observer.unobserve(section);',
            $reveal,
            'A section that has arrived stops costing anything'
        );
        self::assertStringNotContainsString(
            'requestAnimationFrame',
            $reveal,
            'Entry is a CSS transition; nothing may drive it per frame'
        );

        // Input handlers, not the word — the file explains at length what it
        // deliberately does not listen to, and saying so is not doing so.
        foreach (['wheel', 'touchmove', 'scroll', 'touchstart'] as $hijack) {
            self::assertStringNotContainsString(
                sprintf("addEventListener('%s'", $hijack),
                $reveal,
                'Input belongs to the browser'
            );
        }

        self::assertStringNotContainsString(
            'preventDefault',
            $reveal,
            'Input belongs to the browser'
        );
    }

    // ---------------------------------------------- what the server renders

    /**
     * No route ships an entry state. The document a visitor receives — with
     * or without JavaScript — is complete and fully visible.
     */
    public function testNoRouteRendersAnEntryState(): void
    {
        foreach (self::ROUTES as $route) {
            $html = self::html($route);

            self::assertStringNotContainsString('data-facet-reveal', $html, $route . ' must not be staged by the server');

            $xpath = Dom::of(Dom::withoutScripts($html));

            /*
             * One inline style is legitimate and predates this checkpoint: a
             * media slot reserves its aspect ratio inline so the ratio holds
             * before the skin's stylesheet arrives. Everything else that
             * carried an inline style would be a runtime writing into the
             * document the server sent.
             */
            self::assertCount(
                0,
                Dom::query($xpath, '//main//*[@style][not(@data-facet-media)]'),
                $route . ': nothing is positioned or hidden inline'
            );

            foreach (Dom::attributes($xpath, '//main//*[@data-facet-media]', 'style') as $style) {
                self::assertMatchesRegularExpression(
                    '/^aspect-ratio: [\d\s\/]+;$/',
                    $style,
                    $route . ': a media slot reserves geometry and states nothing else'
                );
            }
        }
    }

    /**
     * Every entry rule is gated, and every gate is an attribute the runtime
     * owns. This is what makes "no JavaScript" and "reduced motion" the same
     * complete page rather than two different partial ones.
     */
    public function testEveryEntryRuleIsGatedOnRuntimeState(): void
    {
        $css = self::read('resources/skins/evolving-interface/skin.css');

        self::assertMatchesRegularExpression(
            '/\.facet-main > section\[data-facet-reveal-section\] \{\s*opacity: 0;\s*transform: translate3d\(0, 10px, 0\);/',
            $css,
            'Entry is opacity and transform, and nothing that touches layout'
        );
        self::assertMatchesRegularExpression(
            '/\.facet-main > section\[data-facet-reveal=.in.\] \{\s*opacity: 1;\s*transform: none;\s*\}/',
            $css,
            'An arrived section is simply itself again'
        );

        foreach (
            [
                '@media print {',
                '@media (prefers-reduced-motion: reduce) {',
            ] as $block
        ) {
            self::assertStringContainsString($block, $css);
        }

        // Both blocks must neutralise the entry state, not merely exist.
        foreach (['@media print {', '@media (prefers-reduced-motion: reduce) {'] as $block) {
            $start = strpos($css, $block);
            self::assertIsInt($start);

            self::assertMatchesRegularExpression(
                '/section\[data-facet-reveal-section\] \{\s*opacity: 1;\s*transform: none;/',
                substr($css, $start),
                $block . ' must neutralise the entry state'
            );
        }
    }

    /**
     * The hero is excluded by name. It is the signature moment and it is
     * already on screen; animating it in would play an entrance for the one
     * reader guaranteed not to be waiting for it.
     */
    public function testTheHeroIsNeverStaged(): void
    {
        $runtime = self::read('resources/skins/evolving-interface/skin.ts');

        self::assertStringContainsString(
            "'.facet-main > section:not(.facet-hero)'",
            $runtime,
            'The hero must be excluded from section entry'
        );
        self::assertMatchesRegularExpression(
            '/function enhanceReveal\(root: Document = document\): void \{\s*if \(prefersReducedMotion\(\)\) \{\s*return;/',
            $runtime,
            'Reduced motion must be checked before the observer is created'
        );
    }

    // ------------------------------------------------------------- Pretext

    /**
     * Requirement 15. The decision is recorded, and the thing it decided
     * against is absent from every manifest — which is the only form of
     * "not installed" a test can check.
     */
    public function testThePretextDecisionIsRecordedAndNothingWasInstalled(): void
    {
        $decision = self::read('docs/decisions/PORT-103-section-transitions-and-pretext.md');

        self::assertStringContainsString('**Status:** decided', $decision);
        self::assertStringContainsString('**Not used. Not downloaded, not installed, not vendored.**', $decision);
        self::assertMatchesRegularExpression(
            '/\| Multi-line blocks surveyed \| 81 \|/',
            $decision,
            'The decision must rest on a measurement, not on a preference'
        );

        foreach (['package.json', 'package-lock.json', 'composer.json', 'composer.lock'] as $manifest) {
            self::assertStringNotContainsStringIgnoringCase(
                'pretext',
                self::read($manifest),
                $manifest . ' must not have gained a dependency'
            );
        }

        // The native mechanism the decision rests on is actually in effect.
        self::assertStringContainsString(
            'text-wrap: balance;',
            self::read('resources/skins/evolving-interface/skin.css'),
            'Headings are balanced by the browser, which is why no library is needed'
        );
    }

    /**
     * The gate that measures what source text cannot: that scrolling a whole
     * route reveals every staged section, moves no layout, and stays inside a
     * frame budget.
     */
    public function testTheBrowserHarnessCarriesTheTransitionGate(): void
    {
        $harness = self::read('tools/firefox-audit.py');

        self::assertStringContainsString('--transitions', $harness);
        self::assertStringContainsString('def transitions(', $harness);

        foreach (['scrollBehaviour', 'honoured', 'hiddenOnScreen', 'offsets'] as $measure) {
            self::assertStringContainsString($measure, $harness);
        }
    }
}
