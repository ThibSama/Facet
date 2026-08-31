<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use Facet\Config\Config;
use Facet\Content\Corpus;
use Facet\Content\CorpusLoader;
use Facet\Content\Skill;
use Facet\Content\SkillCategory;
use Facet\Http\Application;
use Facet\Http\Request;
use Facet\Tests\Support\Dom;
use PHPUnit\Framework\TestCase;

/**
 * The skill ribbon's server contract.
 *
 * A ribbon is something a runtime may make out of a list; it is never
 * something the server sends. That is the whole shape of this contract, and
 * every assertion here is a different way of saying it: the document carries
 * one wrapping list per canonical category, every skill in it exactly once, no
 * copies, no live state, and nothing that needs a script to be read.
 *
 * The properties that only exist in motion — that the loop never resets, that
 * a pointer yields it, that it resumes where it stopped — cannot be asserted
 * from PHP and are measured over minutes of real animation by
 * `tools/firefox-audit.py --ribbons`.
 */
final class SkillRibbonTest extends TestCase
{
    private static ?Corpus $corpus = null;

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function corpus(): Corpus
    {
        return self::$corpus ??= CorpusLoader::default(self::root())->load();
    }

    private static function html(): string
    {
        $application = Application::boot(self::root(), Config::fromArray([
            'APP_NAME' => 'Facet',
            'APP_ENV' => 'production',
            'APP_KEY' => 'test-key',
            'APP_LOCALE' => 'en',
        ]));

        $response = $application->handle(Request::create('GET', '/fr'));

        self::assertSame(200, $response->status());

        return $response->body();
    }

    private static function skinCss(): string
    {
        $css = file_get_contents(self::root() . '/resources/skins/evolving-interface/skin.css');
        self::assertIsString($css);

        return $css;
    }

    /**
     * The categories the corpus actually uses, in enum-declaration order.
     *
     * @return list<string>
     */
    private static function categories(): array
    {
        $used = [];

        foreach (self::corpus()->skills() as $skill) {
            $used[$skill->category()->value] = true;
        }

        return array_values(array_filter(
            array_map(static fn (SkillCategory $case): string => $case->value, SkillCategory::cases()),
            static fn (string $value): bool => isset($used[$value])
        ));
    }

    // ------------------------------------------------- what the server sends

    /**
     * One ribbon per canonical category, holding that category's skills and
     * nothing else — the same grouping the page always had, in a box a runtime
     * can pick up.
     */
    public function testOneRibbonPerCanonicalCategoryCarriesItsOwnSkills(): void
    {
        $xpath = Dom::of(Dom::withoutScripts(self::html()));
        $categories = self::categories();

        self::assertNotSame([], $categories, 'The corpus must declare at least one skill category');

        $rendered = [];

        foreach (Dom::query($xpath, '//main//section[starts-with(@aria-labelledby, "skills-")]') as $section) {
            $rendered[] = substr($section->getAttribute('aria-labelledby'), strlen('skills-'));
        }

        self::assertSame($categories, $rendered, 'The grouping and its order are unchanged');

        foreach ($categories as $category) {
            $ribbon = Dom::element(
                $xpath,
                sprintf('//main//section[@aria-labelledby="skills-%s"]//*[@data-facet-ribbon]', $category),
                $category . ' must render exactly one ribbon'
            );

            self::assertFalse(
                $ribbon->hasAttribute('data-facet-ribbon-hold'),
                'The runtime owns the ribbon state; the server must not guess it'
            );
            self::assertSame(
                '',
                $ribbon->getAttribute('data-facet-ribbon'),
                'A server-rendered ribbon is never live'
            );

            $names = [];

            foreach (
                Dom::query(
                    $xpath,
                    sprintf(
                        '//main//section[@aria-labelledby="skills-%s"]//*[@data-facet-ribbon-set]/li',
                        $category
                    )
                ) as $chip
            ) {
                $names[] = Dom::textOf($chip);
            }

            self::assertSame(
                array_map(
                    static fn (Skill $skill): string => $skill->name(),
                    self::corpus()->skillsIn(SkillCategory::from($category))
                ),
                $names,
                $category . ' carries its canonical skills, in corpus order'
            );
        }
    }

    /**
     * The decisive one: every canonical skill is in the document a visitor
     * without JavaScript receives, exactly once. A ribbon that needed a script
     * to finish the list would be a portfolio that omits skills.
     */
    public function testEverySkillIsInTheServedDocumentExactlyOnce(): void
    {
        $xpath = Dom::of(Dom::withoutScripts(self::html()));

        $chips = [];

        foreach (Dom::query($xpath, '//main//*[@data-facet-ribbon]//li') as $chip) {
            $chips[] = Dom::textOf($chip);
        }

        $expected = array_map(
            static fn (Skill $skill): string => $skill->name(),
            self::corpus()->skills()
        );

        sort($expected);
        sort($chips);

        self::assertSame($expected, $chips, 'The ribbons are the skills — all of them, once each');
    }

    /**
     * No copy is ever server-rendered, and nothing in a ribbon is hidden from
     * the reader who has no script to reveal it.
     */
    public function testTheServerRendersNoCopiesAndHidesNothing(): void
    {
        $html = self::html();
        $xpath = Dom::of(Dom::withoutScripts($html));

        self::assertStringNotContainsString('data-facet-ribbon-clone', $html, 'Copies are made by the runtime or not at all');
        self::assertCount(0, Dom::query($xpath, '//main//*[@data-facet-ribbon]//*[@aria-hidden]'));
        self::assertCount(0, Dom::query($xpath, '//main//*[@data-facet-ribbon]//*[@hidden]'));
        self::assertCount(
            0,
            Dom::query($xpath, '//main//*[@data-facet-ribbon]//*[self::a or self::button or self::input or @tabindex]'),
            'A ribbon is text; it puts nothing on the keyboard path'
        );
    }

    /**
     * Requirement 10, asserted rather than promised: the ribbons carry the
     * corpus's words and no invented iconography.
     */
    public function testRibbonsCarryCanonicalTextAndNoIcons(): void
    {
        $xpath = Dom::of(Dom::withoutScripts(self::html()));

        foreach (['img', 'svg', 'picture', 'use', 'i'] as $element) {
            self::assertCount(
                0,
                Dom::query($xpath, sprintf('//main//*[@data-facet-ribbon]//%s', $element)),
                'A ribbon must not invent an icon vocabulary'
            );
        }

        foreach (Dom::query($xpath, '//main//*[@data-facet-ribbon]//li') as $chip) {
            self::assertNotSame('', Dom::textOf($chip), 'Every chip is a canonical name');
            self::assertSame(
                '',
                $chip->getAttribute('style'),
                'A chip states nothing the stylesheet cannot'
            );
        }
    }

    // ------------------------------------------------------------- the CSS

    /**
     * Every rule that makes a list into a ribbon is gated on a flag only the
     * runtime sets. That gate is what makes the no-JavaScript document and the
     * reduced-motion document the same complete, wrapping list.
     */
    public function testEveryRibbonRuleIsGatedOnRuntimeState(): void
    {
        $css = self::skinCss();

        foreach (
            [
                "[data-facet-ribbon='live'] {",
                "[data-facet-ribbon='live'] .facet-ribbon__track {",
                "[data-facet-ribbon='live'][data-facet-ribbon-hold] .facet-ribbon__track {",
            ] as $gated
        ) {
            self::assertStringContainsString($gated, $css, 'The ribbon behaviour must be runtime-gated');
        }

        // The loop travels exactly one set, which is what makes it seamless.
        self::assertMatchesRegularExpression(
            '/@keyframes facet-ribbon-travel \{\s*from \{ transform: translate3d\(0, 0, 0\); \}\s*'
            . 'to \{ transform: translate3d\(calc\(-1 \* var\(--facet-ribbon-shift[^)]*\)\), 0, 0\); \}/',
            $css,
            'The strip must travel exactly one repeat'
        );
        self::assertStringContainsString(
            'animation-play-state: paused;',
            $css,
            'Yielding must pause the animation, not restart or reposition it'
        );
    }

    /**
     * Reduced motion is answered twice: the runtime never mounts a ribbon, and
     * the stylesheet refuses to animate one even if it found itself live.
     */
    public function testReducedMotionLeavesAStaticWrappingList(): void
    {
        $css = self::skinCss();

        $start = strpos($css, '@media (prefers-reduced-motion: reduce) {');
        self::assertIsInt($start);

        $block = substr($css, $start);

        foreach (
            [
                'animation: none;',
                'flex-wrap: wrap;',
                'mask-image: none;',
                'display: none;',
            ] as $rule
        ) {
            self::assertStringContainsString($rule, $block, 'Reduced motion must leave a plain wrapping list');
        }

        $runtime = file_get_contents(self::root() . '/resources/skins/evolving-interface/skin.ts');
        self::assertIsString($runtime);

        self::assertMatchesRegularExpression(
            '/function enhanceRibbons\(root: Document = document\): void \{\s*if \(prefersReducedMotion\(\)\) \{\s*return;/',
            $runtime,
            'The runtime must decline to mount a ribbon under reduced motion'
        );
    }

    // --------------------------------------------------------- the runtime

    /**
     * The runtime's two promises: a copy is never anything but a picture, and
     * everything it added can be taken back.
     */
    public function testTheRuntimeMakesCopiesThatAreOnlyEverVisual(): void
    {
        $ribbons = file_get_contents(self::root() . '/resources/skins/evolving-interface/ribbons.ts');
        self::assertIsString($ribbons);

        self::assertStringContainsString(
            "clone.setAttribute('aria-hidden', 'true');",
            $ribbons,
            'Every copy must be semantically absent'
        );

        foreach (
            [
                'visibility.disconnect();',
                'box.disconnect();',
                'clone.remove();',
                'delete ribbon.dataset.facetRibbon;',
                "ribbon.style.removeProperty('--facet-ribbon-shift');",
            ] as $undone
        ) {
            self::assertStringContainsString($undone, $ribbons, 'Teardown must restore the served document');
        }

        // Motion is CSS. Nothing may drive the strip frame by frame.
        self::assertStringNotContainsString(
            'requestAnimationFrame',
            $ribbons,
            'The ribbon must not run per-frame script'
        );
        self::assertStringNotContainsString(
            'setInterval',
            $ribbons,
            'The ribbon must not run on a timer'
        );
    }

    /**
     * The gate that measures what a served document cannot show: minutes of
     * uninterrupted travel, and a loop with no seam in it.
     */
    public function testTheBrowserHarnessCarriesTheRibbonGate(): void
    {
        $harness = file_get_contents(self::root() . '/tools/firefox-audit.py');
        self::assertIsString($harness);

        self::assertStringContainsString('--ribbons', $harness);
        self::assertStringContainsString('--ribbon-seconds', $harness);
        self::assertStringContainsString('def ribbons(', $harness);

        foreach (['covered', 'unhiddenClones', 'focusableInClones', 'playState'] as $measure) {
            self::assertStringContainsString(
                $measure,
                $harness,
                'The harness must measure the loop, not the source that produced it'
            );
        }
    }
}
