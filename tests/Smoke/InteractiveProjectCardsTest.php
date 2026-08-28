<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use DOMElement;
use Facet\Config\Config;
use Facet\Content\CorpusLoader;
use Facet\Content\Corpus;
use Facet\Content\Project;
use Facet\Http\Application;
use Facet\Http\Request;
use Facet\Tests\Support\Dom;
use PHPUnit\Framework\TestCase;

/**
 * The project card's interaction contract.
 *
 * A card is premium *and* honest: the whole box answers a pointer or a tap,
 * but the thing it answers with is the same ordinary anchor the heading always
 * carried. That distinction is the entire point of these tests. A card built
 * from a click handler would look identical in a screenshot and would be a
 * different product — unreachable without JavaScript, invisible to "open in a
 * new tab", and absent from the accessibility tree.
 *
 * What can be asserted from PHP is the served document and the stylesheet that
 * dresses it. What cannot — that a tap at the card's far corner really lands
 * on the link, and that the lift really costs no layout — is measured in a
 * real browser by `tools/firefox-audit.py --card-interaction`.
 */
final class InteractiveProjectCardsTest extends TestCase
{
    /** Every route whose cards this contract governs. */
    private const ROUTES = ['/', '/projects'];

    private static ?Corpus $corpus = null;

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function corpus(): Corpus
    {
        return self::$corpus ??= CorpusLoader::default(self::root())->load();
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

    private static function skinCss(): string
    {
        $css = file_get_contents(self::root() . '/resources/skins/evolving-interface/skin.css');
        self::assertIsString($css);

        return $css;
    }

    /**
     * Every card on a route, read from the document a visitor without
     * JavaScript receives.
     *
     * @return list<DOMElement>
     */
    private static function cards(string $route): array
    {
        $xpath = Dom::of(Dom::withoutScripts(self::html($route)));
        $cards = [];

        foreach (Dom::query($xpath, '//main//li[contains(@class, "facet-card")]') as $card) {
            $cards[] = $card;
        }

        self::assertNotSame([], $cards, $route . ' must render cards');

        return $cards;
    }

    // ------------------------------------------------------ the link itself

    /**
     * The card is one link, and the link is a link.
     *
     * Exactly one anchor per card is what makes the stretched overlay safe:
     * a second interactive element underneath a full-card hit area would be
     * unreachable by pointer while still being a tab stop, which is the
     * nested trap this contract exists to forbid.
     */
    public function testEachCardHoldsExactlyOneSemanticProjectLink(): void
    {
        foreach (self::ROUTES as $route) {
            $xpath = Dom::of(Dom::withoutScripts(self::html($route)));

            foreach (self::cards($route) as $card) {
                $anchors = [];

                foreach ($card->getElementsByTagName('a') as $anchor) {
                    $anchors[] = $anchor;
                }

                self::assertCount(
                    1,
                    $anchors,
                    $route . ': a card is one link, or the stretched hit area covers a trap'
                );

                $anchor = $anchors[0];

                self::assertStringContainsString(
                    'facet-card__link',
                    $anchor->getAttribute('class'),
                    $route . ': the card-wide hit area belongs to the canonical link'
                );
                self::assertMatchesRegularExpression(
                    '#^/projects/[a-z0-9-]+$#',
                    $anchor->getAttribute('href'),
                    $route . ': the link must address a real case study'
                );
                self::assertNotSame(
                    '',
                    Dom::textOf($anchor),
                    $route . ': the link must be announced by the project name, not by the card'
                );
            }

            // No interactive element other than those links lives in a card.
            self::assertCount(
                0,
                Dom::query(
                    $xpath,
                    '//main//li[contains(@class, "facet-card")]'
                    . '//*[self::button or self::input or self::select or self::textarea or @tabindex]'
                ),
                $route . ': a card holds no second focusable element'
            );
        }
    }

    /**
     * Every card reaches its canonical URL with scripts stripped. The
     * interaction is decoration; the navigation is the document.
     *
     * The catalogue is the whole corpus in the corpus's own order; the home
     * page shows a leading selection of it. Both are asserted against the
     * canonical order rather than against a copy of it, so a corpus edit moves
     * the expectation with the content.
     */
    public function testEveryCardReachesItsCanonicalUrlWithoutJavaScript(): void
    {
        $canonical = array_map(
            static fn (Project $project): string => '/projects/' . $project->slug()->value(),
            self::corpus()->projects()
        );

        foreach (self::ROUTES as $route) {
            $xpath = Dom::of(Dom::withoutScripts(self::html($route)));

            $hrefs = Dom::attributes(
                $xpath,
                '//main//li[contains(@class, "facet-card")]//a[contains(@class, "facet-card__link")]',
                'href'
            );

            self::assertNotSame([], $hrefs, $route . ' must link to projects');
            self::assertSame(
                array_slice($canonical, 0, count($hrefs)),
                $hrefs,
                $route . ': the cards are canonical projects, in corpus order'
            );
        }

        self::assertSame(
            $canonical,
            Dom::attributes(
                Dom::of(Dom::withoutScripts(self::html('/projects'))),
                '//main//li[contains(@class, "facet-card")]//a[contains(@class, "facet-card__link")]',
                'href'
            ),
            'The catalogue is every project, exactly once'
        );
    }

    /**
     * Nothing about the card is scripted into existence, and nothing on it
     * navigates by script.
     */
    public function testNoCardNavigatesOrBehavesByScript(): void
    {
        foreach (self::ROUTES as $route) {
            $html = self::html($route);
            $xpath = Dom::of($html);

            foreach (['onclick', 'onmousedown', 'onkeydown', 'onpointerdown', 'data-href', 'role'] as $attribute) {
                self::assertCount(
                    0,
                    Dom::query(
                        $xpath,
                        sprintf('//main//li[contains(@class, "facet-card")]/@%s', $attribute)
                    ),
                    $route . ': a card must not carry ' . $attribute
                );
            }

            self::assertCount(
                0,
                Dom::query($xpath, '//main//li[contains(@class, "facet-card")]//*[@aria-hidden="true"][a]'),
                $route . ': no link is hidden from assistive technology'
            );
        }
    }

    // ------------------------------------------------------------- the box

    /**
     * Ordinary layout is stated where the card is written.
     *
     * The point is not that utilities are prettier. It is that the card's box
     * and the card's material stopped being the same decision: the template
     * owns radius, padding, border and the positioning context, and the
     * stylesheet owns only what a utility cannot express.
     */
    public function testTheCardsBoxIsStatedInUtilitiesAndItsMaterialInCss(): void
    {
        foreach (self::ROUTES as $route) {
            foreach (self::cards($route) as $card) {
                $classes = $card->getAttribute('class');

                foreach (['relative', 'rounded-card', 'border', 'border-hairline', 'p-card'] as $utility) {
                    self::assertStringContainsString(
                        $utility,
                        $classes,
                        $route . ': the card must state ' . $utility . ' in the template'
                    );
                }
            }
        }

        $css = self::skinCss();

        foreach (['border-radius: var(--facet-radius-lg)', 'padding: var(--facet-space-4)'] as $moved) {
            self::assertStringNotContainsString(
                $moved,
                self::cardRule($css),
                'Card geometry moved to utilities and must not be restated in CSS'
            );
        }
    }

    /** The `.facet-card` declaration block, and nothing around it. */
    private static function cardRule(string $css): string
    {
        $found = preg_match(
            "/html\[data-skin='evolving-interface'\] \.facet-card \{([^}]*)\}/",
            $css,
            $matches
        );

        self::assertSame(1, $found, 'The skin must declare a .facet-card rule');
        self::assertArrayHasKey(1, $matches);

        return $matches[1];
    }

    // -------------------------------------------------------- the material

    /**
     * The full-card hit area is a pseudo-element on the link, positioned
     * against a card that declares itself the containing block. Both halves
     * have to be true or the overlay covers the wrong box.
     */
    public function testTheHitAreaIsThelinksOwnStretchedOverlay(): void
    {
        $css = self::skinCss();

        self::assertMatchesRegularExpression(
            "/\.facet-card \{[^}]*position: relative;/",
            $css,
            'The card is the containing block the overlay stretches to'
        );
        self::assertMatchesRegularExpression(
            "/\.facet-card__link::after \{[^}]*position: absolute;[^}]*inset: 0;/s",
            $css,
            'The link must own an overlay covering the card'
        );
    }

    /**
     * Keyboard parity, and a hover that a touch screen never inherits.
     *
     * `:focus-within` has to appear outside the pointer media query: a
     * keyboard has no `hover` capability to report, and a focus treatment
     * nested inside `(hover: hover)` would simply never apply to the readers
     * who need it most.
     */
    public function testFocusIsTreatedLikeHoverAndHoverIsGatedOnRealPointers(): void
    {
        $css = self::skinCss();

        self::assertStringContainsString(
            '@media (hover: hover) {',
            $css,
            'A sticky hover on a touch screen must not light a card nobody is pointing at'
        );

        $hoverBlock = self::hoverMediaBlock($css);

        self::assertStringContainsString('.facet-card:hover', $hoverBlock);
        self::assertStringNotContainsString(
            ':focus-within',
            $hoverBlock,
            'Keyboard focus must not be gated behind a pointer capability'
        );

        foreach (
            [
                "/\.facet-card:focus-within \{[^}]*transform: translateY\(-3px\);/",
                "/\.facet-card:focus-within::before \{[^}]*opacity: 1;/",
            ] as $parity
        ) {
            self::assertMatchesRegularExpression($parity, $css, 'Focus gets the hover treatment');
        }

        // The lit border and raised shadow are declared for both at once.
        self::assertMatchesRegularExpression(
            "/\.facet-card:hover,\s*\n[^\n]*\.facet-card:focus-within \{[^}]*box-shadow: var\(--facet-shadow-md\)/",
            $css
        );
    }

    /** The `@media (hover: hover)` block, and nothing around it. */
    private static function hoverMediaBlock(string $css): string
    {
        $start = strpos($css, '@media (hover: hover) {');
        self::assertIsInt($start);

        $end = strpos($css, "\n}\n", $start);
        self::assertIsInt($end);

        return substr($css, $start, $end - $start);
    }

    /**
     * Reduced motion removes the travel and keeps the affordance. A card that
     * simply stopped responding would be a worse answer than one that moves.
     */
    public function testReducedMotionRemovesMovementAndNotTheAffordance(): void
    {
        $css = self::skinCss();

        $start = strpos($css, '@media (prefers-reduced-motion: reduce) {');
        self::assertIsInt($start);

        $block = substr($css, $start);

        self::assertMatchesRegularExpression(
            "/\.facet-card:hover,\s*\n[^\n]*\.facet-card:focus-within \{\s*transform: none;\s*\}/",
            $block,
            'Reduced motion must neutralise the lift'
        );
        self::assertStringNotContainsString(
            'opacity: 0',
            $block,
            'Reduced motion must not withdraw the light that says a card is active'
        );
    }

    // ---------------------------------------------------------- the runtime

    /**
     * The pointer tracker is an enhancement of an enhancement: it moves the
     * origin of a light the stylesheet already paints, and it refuses to run
     * for readers a pointer path means nothing to.
     */
    public function testThePointerTrackerIsGuardedAndReleasable(): void
    {
        $runtime = file_get_contents(self::root() . '/resources/skins/evolving-interface/skin.ts');
        self::assertIsString($runtime);

        self::assertStringContainsString(
            "const FINE_POINTER = '(hover: hover) and (pointer: fine)';",
            $runtime,
            'A coarse pointer has no path to track'
        );
        self::assertMatchesRegularExpression(
            '/if \(grids\.length === 0 \|\| prefersReducedMotion\(\)\) \{/',
            $runtime,
            'Reduced motion must be checked before the tracker mounts'
        );
        self::assertStringContainsString(
            'onRelease((): void => cards.destroy());',
            $runtime,
            'Every mounted grid must hand back a teardown'
        );

        $cards = file_get_contents(self::root() . '/resources/skins/evolving-interface/cards.ts');
        self::assertIsString($cards);

        foreach (
            [
                "grid.removeEventListener('pointermove', onPointerMove);",
                "grid.removeEventListener('pointerleave', onPointerLeave);",
            ] as $removal
        ) {
            self::assertStringContainsString($removal, $cards, 'Destroy must remove every listener it added');
        }

        // Geometry is read on the frame the pointer arrives, never per move,
        // and never in the handler where the read would force a layout.
        self::assertSame(
            1,
            substr_count($cards, 'getBoundingClientRect()'),
            'A per-move measurement is a forced reflow; the rectangle is read once'
        );
        self::assertStringContainsString(
            'bounds ??= card.getBoundingClientRect();',
            $cards,
            'The rectangle is read inside the animation frame, before anything is written'
        );
        self::assertStringNotContainsString(
            '.href',
            $cards,
            'The tracker must never navigate'
        );
    }

    /**
     * The gate that measures what source text cannot: a real tap at a real
     * corner, and a real absence of layout work while a card lifts.
     */
    public function testTheBrowserHarnessCarriesTheCardGate(): void
    {
        $harness = file_get_contents(self::root() . '/tools/firefox-audit.py');
        self::assertIsString($harness);

        self::assertStringContainsString('--card-interaction', $harness);
        self::assertStringContainsString('def card_interaction(', $harness);
    }
}
