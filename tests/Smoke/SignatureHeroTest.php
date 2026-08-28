<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use Facet\Asset\AssetResolver;
use Facet\Config\Config;
use Facet\Http\Application;
use Facet\Http\Request;
use Facet\Skin\SkinRegistry;
use Facet\Support\ViteManifest;
use Facet\Tests\Support\Dom;
use PHPUnit\Framework\TestCase;

/**
 * The signature hero's progressive-enhancement contract.
 *
 * The effect itself is a WebGL shader and cannot be asserted from PHP. What
 * *can* be asserted — and is what the contract actually promises — is that the
 * document the server sends is complete without it, that nothing about the
 * enhancement is allowed to reach a page that will not use it, and that the
 * fallback the enhancement layers over is still the accepted one.
 *
 * The renderer decision these tests protect is recorded in
 * docs/decisions/PORT-99-signature-hero-renderer.md.
 */
final class SignatureHeroTest extends TestCase
{
    private const HERO_MODULE = 'resources/skins/evolving-interface/hero.ts';
    private const SKIN_ENTRY = 'resources/skins/evolving-interface/skin.ts';

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function manifestPath(): string
    {
        return self::root() . '/public/build/manifest.json';
    }

    private static function manifest(): ViteManifest
    {
        if (!is_readable(self::manifestPath())) {
            self::markTestSkipped('No build present. Run `npm run build` (composer quality does).');
        }

        return ViteManifest::fromFile(self::manifestPath());
    }

    /** @return array<string, mixed> */
    private static function rawManifest(): array
    {
        $raw = file_get_contents(self::manifestPath());
        self::assertIsString($raw);

        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function html(string $path = '/'): string
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

    private static function skinRuntime(): string
    {
        $ts = file_get_contents(self::root() . '/' . self::SKIN_ENTRY);
        self::assertIsString($ts);

        return $ts;
    }

    private static function template(): string
    {
        $php = file_get_contents(
            self::root() . '/resources/skins/evolving-interface/views/page/home.php'
        );
        self::assertIsString($php);

        return $php;
    }

    // ------------------------------------------------- the served document

    /**
     * The base case: the slot is an empty, decorative box that the server
     * sized and the reader never needs.
     */
    public function testTheServerRendersTheSlotAndNothingElse(): void
    {
        $xpath = Dom::of(Dom::withoutScripts(self::html()));

        $slot = Dom::element($xpath, '//main//*[@data-facet-hero-visual]');

        self::assertSame('true', $slot->getAttribute('aria-hidden'), 'The slot is decorative');
        self::assertSame('', Dom::textOf($slot), 'The slot must carry no information');
        self::assertFalse(
            $slot->hasAttribute('data-facet-hero'),
            'The runtime owns the hero state; the server must not guess it'
        );
    }

    /**
     * The enhancement is created by script or not at all. A server-rendered
     * canvas would put a scripted surface in the no-JavaScript document, and
     * would also be the one thing capable of shifting the layout.
     */
    public function testNoCanvasIsEverServerRendered(): void
    {
        $xpath = Dom::of(self::html());

        self::assertCount(0, Dom::query($xpath, '//canvas'));
        self::assertStringNotContainsString('<canvas', self::html());
    }

    /**
     * The slot is sized entirely by the document the server sent, so the
     * canvas that may later appear inside it cannot move anything. Both halves
     * of that promise are asserted: the box is stated in the template, and it
     * is stated in a way that does not depend on the effect arriving.
     */
    public function testTheSlotIsFullySizedBeforeAnyScriptRuns(): void
    {
        $xpath = Dom::of(Dom::withoutScripts(self::html()));
        $slot = Dom::element($xpath, '//main//*[@data-facet-hero-visual]');
        $classes = $slot->getAttribute('class');

        foreach (['aspect-[16/7]', 'min-[56rem]:aspect-[4/5]'] as $utility) {
            self::assertStringContainsString(
                $utility,
                $classes,
                'The slot must declare its own aspect ratio'
            );
        }

        // The material the canvas is layered over positions it, and clips it.
        self::assertMatchesRegularExpression(
            '/\.facet-hero__visual \{[^}]*position: relative;/',
            self::skinCss()
        );
        self::assertMatchesRegularExpression(
            '/\.facet-hero__visual \{[^}]*overflow: hidden;/',
            self::skinCss()
        );
        self::assertMatchesRegularExpression(
            '/\.facet-hero__visual > canvas \{[^}]*position: absolute;/',
            self::skinCss()
        );
    }

    // ------------------------------------------------------ asset isolation

    /**
     * The decisive cost claim: the shader is its own chunk, reached only
     * through a dynamic import, so every page that is not the home page pays
     * nothing for it.
     */
    public function testTheHeroModuleIsADeferredChunkOfTheSkinEntry(): void
    {
        $manifest = self::rawManifest();

        self::assertArrayHasKey(self::HERO_MODULE, $manifest, 'The hero module must be built');

        $hero = $manifest[self::HERO_MODULE];
        self::assertIsArray($hero);
        self::assertTrue($hero['isDynamicEntry'] ?? false, 'The hero module must be dynamically imported');
        self::assertArrayNotHasKey('isEntry', $hero, 'A dynamic chunk is not an entrypoint PHP can emit');

        $skin = $manifest[self::SKIN_ENTRY];
        self::assertIsArray($skin);
        self::assertContains(
            self::HERO_MODULE,
            $skin['dynamicImports'] ?? [],
            'The skin entry must reach the hero only through a dynamic import'
        );
        self::assertNotContains(
            self::HERO_MODULE,
            $skin['imports'] ?? [],
            'A static import would put the shader in every page load'
        );
    }

    /**
     * The resolver emits entrypoints, and the hero module is not one. No
     * rendered document may name it: the browser fetches it, or does not,
     * entirely on the runtime's decision.
     */
    public function testNoRenderedDocumentReferencesTheHeroChunk(): void
    {
        $chunk = self::manifest()->asset(self::HERO_MODULE);
        $bundle = AssetResolver::usingManifest(self::manifest())
            ->resolve(SkinRegistry::default()->defaultSkin());

        self::assertFalse($bundle->references($chunk), 'The hero chunk is not a bundled asset');

        foreach (['/', '/projects', '/about', '/contact'] as $route) {
            self::assertStringNotContainsString(
                $chunk,
                self::html($route),
                $route . ' must not reference the hero chunk'
            );
        }
    }

    /**
     * The hero slot exists on the home page and nowhere else, which is what
     * makes the deferred chunk deferred in practice rather than in principle.
     */
    public function testOnlyTheHomePageCarriesAHeroSlot(): void
    {
        foreach (['/projects', '/about', '/contact'] as $route) {
            $xpath = Dom::of(self::html($route));

            self::assertCount(
                0,
                Dom::query($xpath, '//*[@data-facet-hero-visual]'),
                $route . ' must not carry a hero slot'
            );
        }
    }

    // ------------------------------------------------------- hero lifecycle

    /**
     * A supplement to the runtime proof, not a substitute for it.
     *
     * What actually settles this is `tools/firefox-audit.py --hero-lifecycle`,
     * which mounts the built module in a real Firefox and counts every
     * listener registered and removed. Source text cannot count listeners. It
     * can, however, catch the one shape that made the leak possible in the
     * first place — a handler passed as an anonymous argument, whose reference
     * is gone the moment it is registered and which therefore can never be
     * removed — and that is what is asserted here.
     */
    public function testTheRuntimeRetainsEveryListenerReferenceItMustRemove(): void
    {
        $runtime = self::skinRuntime();

        self::assertMatchesRegularExpression(
            '/const motion = [^;]*window\.matchMedia\(REDUCED_MOTION\)[^;]*;/',
            $runtime,
            'The reduced-motion query must be held, not re-queried at teardown'
        );
        self::assertStringContainsString(
            'const onMotionChange = (event: MediaQueryListEvent): void =>',
            $runtime,
            'The change handler must be a named reference, or it cannot be removed'
        );

        foreach (
            [
                "window.removeEventListener('pagehide', release);",
                "motion?.removeEventListener('change', onMotionChange);",
            ] as $removal
        ) {
            self::assertStringContainsString(
                $removal,
                $runtime,
                'Release must remove every listener it owns'
            );
        }

        self::assertDoesNotMatchRegularExpression(
            "/addEventListener\\(\\s*'change',\\s*\\(/",
            $runtime,
            'An inline change handler is a listener that can never be unregistered'
        );

        // One destroy, whichever signal arrives and however often it repeats.
        self::assertStringContainsString('let released = false;', $runtime);
        self::assertMatchesRegularExpression(
            '/const release = \(\): void => \{\s*if \(released\) \{\s*return;/',
            $runtime,
            'The release path must be idempotent'
        );
        self::assertSame(
            1,
            substr_count($runtime, 'handle.destroy()'),
            'There is exactly one teardown path'
        );
    }

    /**
     * The gate itself has to exist, because the assertion above is deliberately
     * the weaker half of the evidence.
     */
    public function testTheBrowserHarnessCarriesTheLifecycleGate(): void
    {
        $harness = file_get_contents(self::root() . '/tools/firefox-audit.py');
        self::assertIsString($harness);

        self::assertStringContainsString('--hero-lifecycle', $harness);
        self::assertStringContainsString('def hero_lifecycle(', $harness);

        foreach (['pagehideListeners', 'motionListeners', 'destroys'] as $measure) {
            self::assertStringContainsString(
                $measure,
                $harness,
                'The harness must measure listener and destroy counts, not source text'
            );
        }
    }

    // --------------------------------------------- the fallback it layers on

    /**
     * The accepted static visual is the base case, so it must still be a
     * complete picture on its own: its own material, and both shards.
     */
    public function testTheAcceptedStaticFallbackSurvives(): void
    {
        $css = self::skinCss();

        self::assertMatchesRegularExpression(
            '/\.facet-hero__visual \{[^}]*background:\s*\n\s*linear-gradient/',
            $css,
            'The fallback keeps its layered material'
        );
        self::assertStringContainsString('.facet-hero__visual::before', $css);
        self::assertStringContainsString('.facet-hero__visual::after', $css);

        // The effect is painted under the accepted shards, never over them.
        self::assertMatchesRegularExpression(
            '/\.facet-hero__visual > canvas \{[^}]*z-index: 0;/',
            $css
        );
    }

    /**
     * Only a mounted effect is visible. A canvas that was created but never
     * reached `live` — a refused context, a thrown error — stays transparent,
     * so a half-initialised enhancement cannot show a blank rectangle over the
     * fallback.
     */
    public function testTheCanvasIsInvisibleUntilTheEffectIsLive(): void
    {
        $css = self::skinCss();

        self::assertMatchesRegularExpression(
            '/\.facet-hero__visual > canvas \{[^}]*opacity: 0;/',
            $css,
            'A canvas is transparent until the effect says otherwise'
        );
        self::assertMatchesRegularExpression(
            "/\\.facet-hero__visual\\[data-facet-hero='live'\\] > canvas \\{[^}]*opacity: 1;/",
            $css
        );
    }

    // ------------------------------------------------- Tailwind-first layout

    /**
     * The realignment, asserted from both sides: the hero states its layout in
     * the template, and the stylesheet no longer carries a competing copy.
     */
    public function testHeroLayoutLivesInTheTemplateAndNotInTheStylesheet(): void
    {
        $template = self::template();

        foreach (['grid', 'grid-cols-1', 'min-[56rem]:grid-cols-', 'text-display'] as $utility) {
            self::assertStringContainsString($utility, $template, 'The hero states its own layout');
        }

        $css = self::skinCss();

        self::assertDoesNotMatchRegularExpression(
            '/\.facet-hero \{/',
            $css,
            'Hero layout belongs to utilities, not to a component rule'
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.facet-hero h1 \{/',
            $css,
            'Hero typography belongs to utilities'
        );

        /*
         * The generic heading rule out-specifies any utility, so it has to
         * exclude the hero explicitly. Without this the template's
         * `text-display` would be silently overridden and the realignment
         * would be cosmetic.
         */
        self::assertStringContainsString(
            '.facet-main h1:not([data-facet-hero-title])',
            $css
        );
        self::assertStringContainsString('data-facet-hero-title', $template);
    }

    /**
     * `.facet-hero` survives as a marker only. It still has one job — keeping
     * the hero out of the rhythm applied to every other section — and that job
     * is not layout.
     */
    public function testTheHeroClassRemainsOnlyAsAMarker(): void
    {
        $xpath = Dom::of(Dom::withoutScripts(self::html()));
        $hero = Dom::element($xpath, '//main/section[contains(@class, "facet-hero")]');

        self::assertStringContainsString('grid', $hero->getAttribute('class'));
        self::assertStringContainsString(
            'section:not(.facet-hero)',
            self::skinCss(),
            'The marker still exempts the hero from the section rhythm'
        );
    }
}
