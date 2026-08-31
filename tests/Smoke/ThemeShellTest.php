<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Facet\Config\Config;
use Facet\Http\Application;
use Facet\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The shell's light/dark behaviour.
 *
 * Three properties are worth guarding and all three are checkable without a
 * browser. Theming has to work with JavaScript switched off, which makes it a
 * property of the stylesheet; an explicit choice has to beat the system one in
 * both directions, which makes it a property of the cascade; and both themes
 * have to stay readable, which makes it arithmetic over the declared tokens.
 *
 * The one thing that genuinely needs a runtime — the pre-paint bootstrap
 * surviving a storage failure — is executed rather than described, in a Node
 * process with a `localStorage` that throws.
 */
final class ThemeShellTest extends TestCase
{
    private const STORAGE_KEY = 'facet.theme';

    /** WCAG AA for body text. */
    private const MIN_TEXT_CONTRAST = 4.5;

    /** WCAG AA for focus indicators and other non-text UI. */
    private const MIN_UI_CONTRAST = 3.0;

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function css(): string
    {
        $css = file_get_contents(self::root() . '/resources/css/app.css');
        self::assertIsString($css);

        return $css;
    }

    /**
     * The body of the `@theme` at-rule, brace-matched rather than pattern-
     * matched so a nested block could never truncate it.
     */
    private static function tailwindThemeBlock(string $css): string
    {
        $start = strpos($css, '@theme');
        self::assertIsInt($start, 'app.css must declare a Tailwind @theme block');

        $open = strpos($css, '{', $start);
        self::assertIsInt($open);

        $depth = 0;
        $length = strlen($css);

        for ($index = $open; $index < $length; $index++) {
            if ($css[$index] === '{') {
                $depth++;
            } elseif ($css[$index] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($css, $start, $index - $start + 1);
                }
            }
        }

        self::fail('The @theme block is not closed');
    }

    private static function bootstrapPartial(): string
    {
        $raw = file_get_contents(
            self::root() . '/resources/skins/evolving-interface/views/partials/theme-bootstrap.php'
        );
        self::assertIsString($raw);

        return $raw;
    }

    private static function themeModule(): string
    {
        $raw = file_get_contents(self::root() . '/resources/js/theme.ts');
        self::assertIsString($raw);

        return $raw;
    }

    private static function html(string $path = '/fr'): string
    {
        $response = Application::boot(self::root(), Config::fromArray([
            'APP_NAME' => 'Facet',
            'APP_ENV' => 'production',
            'APP_KEY' => 'test-key',
            'APP_LOCALE' => 'en',
        ]))->handle(Request::create('GET', $path));

        self::assertSame(200, $response->status());

        return $response->body();
    }

    private static function dom(string $html): DOMXPath
    {
        $document = new DOMDocument();

        $previous = libxml_use_internal_errors(true);
        self::assertTrue($document->loadHTML('<?xml encoding="utf-8" ?>' . $html));
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    /**
     * The declarations inside one exact selector block of the source CSS.
     *
     * @return array<string, string> custom property name => value
     */
    private static function tokensOf(string $selector): array
    {
        $pattern = '/' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/';

        self::assertSame(1, preg_match($pattern, self::css(), $matches), 'No block declares ' . $selector);

        preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $matches[1], $declarations, PREG_SET_ORDER);

        $tokens = [];

        foreach ($declarations as $declaration) {
            $tokens[$declaration[1]] = trim($declaration[2]);
        }

        return $tokens;
    }

    private static function relativeLuminance(string $hex): float
    {
        $hex = ltrim(trim($hex), '#');
        self::assertMatchesRegularExpression('/^[0-9a-f]{6}$/i', $hex, 'Not a 6-digit hex colour: ' . $hex);

        $channels = [];

        foreach ([0, 2, 4] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private static function contrast(string $foreground, string $background): float
    {
        $a = self::relativeLuminance($foreground);
        $b = self::relativeLuminance($background);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /**
     * Criterion 8: with no stored choice and no JavaScript, the stylesheet
     * alone follows the operating system.
     */
    public function testTheStylesheetFollowsTheSystemPreferenceWithoutJavaScript(): void
    {
        $css = self::css();

        self::assertStringContainsString('@media (prefers-color-scheme: dark)', $css);

        // The guard is what makes "force light on a dark system" work: the
        // system rule must stand down when the visitor has said otherwise.
        self::assertStringContainsString(":root:not([data-theme='light'])", $css);

        $system = self::tokensOf(":root:not([data-theme='light'])");
        $light = self::tokensOf(':root');

        self::assertNotSame(
            $light['--facet-surface'],
            $system['--facet-surface'],
            'The dark media query must actually change the surface'
        );
        self::assertNotSame($light['--facet-ink'], $system['--facet-ink']);

        // Nothing in the served document pre-commits to a theme, so the media
        // query is in charge until a visitor chooses.
        $xpath = self::dom(self::html());

        $root = self::element($xpath, '//html');
        self::assertFalse($root->hasAttribute('data-theme'), 'The server must not pick a theme for the visitor');

        // The browser is told both themes are supported, so it paints its own
        // widgets — scrollbars, form controls — for whichever one is active.
        self::assertSame('light dark', self::element($xpath, '//meta[@name="color-scheme"]')->getAttribute('content'));
    }

    /**
     * An explicit choice overrides the system in both directions — the half
     * that is easy to get wrong is forcing light on a dark machine.
     */
    public function testAnExplicitChoiceOverridesTheSystemInBothDirections(): void
    {
        $dark = self::tokensOf(":root[data-theme='dark']");
        $light = self::tokensOf(":root[data-theme='light']");
        $base = self::tokensOf(':root');
        $system = self::tokensOf(":root:not([data-theme='light'])");

        self::assertSame($system['--facet-surface'], $dark['--facet-surface'], 'Forced dark must match system dark');
        self::assertSame($base['--facet-surface'], $light['--facet-surface'], 'Forced light must match the light base');

        // `color-scheme` has to move with the tokens, or the browser keeps
        // painting form controls and scrollbars for the wrong theme.
        foreach ([":root[data-theme='dark']" => 'dark', ":root[data-theme='light']" => 'light'] as $selector => $scheme) {
            self::assertMatchesRegularExpression(
                '/' . preg_quote($selector, '/') . '\s*\{[^}]*color-scheme:\s*' . $scheme . ';/',
                self::css(),
                $selector . ' must declare color-scheme: ' . $scheme
            );
        }

        // Every token the light base declares must be restated by each theme,
        // or switching would leave a value from the previous one behind.
        foreach (array_keys($base) as $token) {
            if (!str_starts_with($token, '--facet-') || str_starts_with($token, '--facet-shell')
                || $token === '--facet-nav-breakpoint') {
                continue;
            }

            self::assertArrayHasKey($token, $dark, $token . ' must be defined for the dark theme');
            self::assertArrayHasKey($token, $light, $token . ' must be defined for the forced light theme');
            self::assertArrayHasKey($token, $system, $token . ' must be defined for the system dark theme');
        }
    }

    /**
     * Criterion 9: the control is a real button — operable by pointer, touch
     * and keyboard for free — and persists nothing but a browser preference.
     */
    public function testTheControlIsANativeButtonAndPersistsOnlyABrowserPreference(): void
    {
        $xpath = self::dom(self::html());

        $nodes = $xpath->query('//button[@data-facet-theme-toggle]');
        self::assertNotFalse($nodes);
        self::assertSame(1, $nodes->length);

        $control = $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $control);
        self::assertSame('button', $control->getAttribute('type'));
        self::assertContains($control->getAttribute('aria-pressed'), ['true', 'false']);

        // No cookie, no request parameter, no server round trip: the whole
        // preference is one key in the visitor's own browser.
        foreach ([self::themeModule(), self::bootstrapPartial()] as $source) {
            self::assertStringContainsString('localStorage', $source);
            self::assertStringNotContainsString('document.cookie', $source);
            self::assertStringNotContainsString('fetch(', $source);
            self::assertStringNotContainsString('XMLHttpRequest', $source);
        }

        self::assertStringContainsString(self::STORAGE_KEY, self::themeModule());
        self::assertStringContainsString(self::STORAGE_KEY, self::bootstrapPartial());

        // And the server never sets one either.
        $response = Application::boot(self::root(), Config::fromArray([
            'APP_NAME' => 'Facet',
            'APP_ENV' => 'production',
            'APP_KEY' => 'test-key',
        ]))->handle(Request::create('GET', '/fr'));

        // The one cookie a public page may set is the language preference, and
        // it says nothing about a theme: theme and locale are independent
        // preferences kept in independent places, which is why switching one
        // can never move the other.
        $cookie = (string) $response->header('Set-Cookie');

        self::assertStringStartsWith('facet_locale=', $cookie);
        self::assertStringNotContainsString(self::STORAGE_KEY, $cookie);
        self::assertStringNotContainsString('theme', $cookie);

        // The control is a touch target, not a hover affordance.
        self::assertStringContainsString('min-height: 2.75rem', self::css());
    }

    /**
     * Criterion 10: the bootstrap runs in the head, before any body content
     * paints, and reads exactly one key.
     */
    public function testThePrePaintBootstrapRunsInTheHeadAndIsMinimal(): void
    {
        $html = self::html();

        $headEnd = strpos($html, '</head>');
        $bodyStart = strpos($html, '<body');
        $script = strpos($html, self::STORAGE_KEY);

        self::assertIsInt($headEnd);
        self::assertIsInt($bodyStart);
        self::assertIsInt($script);
        self::assertLessThan($headEnd, $script, 'The theme bootstrap must run before the head closes');
        self::assertLessThan($bodyStart, $script, 'The theme bootstrap must run before the body paints');

        $partial = self::bootstrapPartial();

        self::assertStringContainsString('try {', $partial, 'Storage access must be guarded');
        self::assertStringContainsString('catch', $partial);
        self::assertSame(1, substr_count($partial, 'getItem'), 'The bootstrap must read exactly one key');
        self::assertStringNotContainsString('setItem', $partial, 'The bootstrap must not write');

        // It is the only inline script in the document: everything else is a
        // module loaded from the build.
        self::assertSame(1, preg_match_all('/<script(?![^>]*\ssrc=)[^>]*>/i', $html));
    }

    /**
     * The failure injection the criterion asks for, actually executed: a
     * `localStorage` whose getter throws must not abort the script — the
     * visitor still gets a theme, decided by the clock alone.
     */
    public function testThePrePaintBootstrapSurvivesAStorageFailure(): void
    {
        // Every case runs at 09:00, so the only variable is the storage.
        $cases = [
            'throwing' => ['get localStorage() { throw new Error("SecurityError"); }', 'light'],
            'empty' => ['get localStorage() { return { getItem() { return null; } }; }', 'light'],
            'hostile' => ['get localStorage() { return { getItem() { return "<script>"; } }; }', 'light'],
            'stored-dark' => ['get localStorage() { return { getItem() { return "dark"; } }; }', 'dark'],
        ];

        foreach ($cases as $name => [$storage, $expected]) {
            self::assertSame($expected, self::bootstrapResult($storage, 9), $name);
        }
    }

    /**
     * PORT-138: with nothing stored, the hour on the visitor's own clock picks
     * the theme — 07:00 inclusive to 20:00 exclusive is day, and the rest of
     * the twenty-four is night.
     *
     * The boundaries are the whole rule, so the boundaries are what is
     * asserted, and none of it depends on when the suite happens to run: the
     * bootstrap is executed against a stubbed clock, one hour at a time.
     */
    public function testTheClockPicksTheThemeWhenNothingIsStored(): void
    {
        $empty = 'get localStorage() { return { getItem() { return null; } }; }';

        $hours = [
            0 => 'dark',
            5 => 'dark',
            // 06:59 — still night, because the day starts on the hour.
            6 => 'dark',
            7 => 'light',
            12 => 'light',
            // 19:59 — the last minute of the day half.
            19 => 'light',
            20 => 'dark',
            23 => 'dark',
        ];

        foreach ($hours as $hour => $expected) {
            self::assertSame(
                $expected,
                self::bootstrapResult($empty, $hour),
                sprintf('%02d:00 must open in %s', $hour, $expected)
            );
        }

        // A clock that cannot be read is not a reason to guess: light stands.
        self::assertSame('light', self::bootstrapResult($empty, 'Number.NaN'));
        self::assertSame('light', self::bootstrapResult($empty, 99));
    }

    /**
     * A choice, once made, is not re-decided every morning: the stored value
     * wins at any hour, in both directions, and an unusable stored value is
     * no value at all rather than a third state.
     */
    public function testAStoredChoiceBeatsTheClockInBothDirections(): void
    {
        $stored = static fn (string $value): string => sprintf(
            'get localStorage() { return { getItem() { return %s; } }; }',
            json_encode($value, JSON_THROW_ON_ERROR)
        );

        // Light kept at eleven at night, dark kept at noon.
        self::assertSame('light', self::bootstrapResult($stored('light'), 23));
        self::assertSame('dark', self::bootstrapResult($stored('dark'), 12));

        // Anything else is not a preference, so the clock decides.
        foreach (['', 'system', 'DARK', 'null'] as $invalid) {
            self::assertSame('dark', self::bootstrapResult($stored($invalid), 22), $invalid);
            self::assertSame('light', self::bootstrapResult($stored($invalid), 10), $invalid);
        }
    }

    /**
     * The bootstrap and the module must not be able to disagree.
     *
     * They cannot share code — one is an inline script in the head, the other
     * a module from the build — so what holds them together is this: both
     * state the same two boundaries, both read the same key, and neither
     * consults the operating system's colour scheme, which under PORT-138 no
     * longer outranks the product rule.
     */
    public function testTheBootstrapAndTheModuleResolveTheThemeIdentically(): void
    {
        // Both files *say* they consult no such thing; what is asserted is
        // that neither one does, so the prose is stripped before looking.
        $script = self::withoutComments(self::inlineBootstrapSource());
        $module = self::withoutComments(self::themeModule());

        foreach ([$script, $module] as $source) {
            self::assertStringContainsString('getHours()', $source, 'Both paths must read the local hour');
            self::assertStringNotContainsString('prefers-color-scheme', $source);
            self::assertStringNotContainsString('geolocation', $source);
            self::assertStringNotContainsString('sunrise', $source);
        }

        // The two boundaries, stated in both files.
        self::assertMatchesRegularExpression('/hour\s*>=\s*7\s*&&\s*hour\s*<\s*20/', $script);
        self::assertStringContainsString('const DAY_STARTS_AT = 7;', $module);
        self::assertStringContainsString('const NIGHT_STARTS_AT = 20;', $module);
        self::assertMatchesRegularExpression(
            '/hour\s*>=\s*DAY_STARTS_AT\s*&&\s*hour\s*<\s*NIGHT_STARTS_AT/',
            $module
        );

        // And the module's own precedence is the bootstrap's: choice, then clock.
        self::assertMatchesRegularExpression('/storedTheme\(\)\s*\?\?\s*timeTheme\(\)/', $module);
    }

    /**
     * The manual theme change is a transition with an end.
     *
     * The mechanism differs by engine and neither half of it can be seen from
     * PHP; what can be checked here is the contract both halves are built on —
     * one attribute, set only by the module, removed by both a completion
     * handler and a timer, never printed into the served document, and inert
     * under reduced motion.
     */
    public function testTheGlobalThemeTransitionIsBoundedAndNeverShipsInTheDocument(): void
    {
        $module = self::withoutComments(self::themeModule());
        $css = self::css();

        self::assertStringContainsString("TRANSITION_ATTRIBUTE = 'data-facet-theme-shift'", $module);
        self::assertStringContainsString('prefersReducedMotion()', $module);
        self::assertStringContainsString('removeAttribute(TRANSITION_ATTRIBUTE)', $module);
        self::assertStringContainsString('window.setTimeout(settle', $module);

        // No dependency, no frame loop, and no browser API the engines
        // disagree about: the transition is a transition.
        self::assertStringNotContainsString('requestAnimationFrame', $module);
        self::assertStringNotContainsString('startViewTransition', $module);
        self::assertDoesNotMatchRegularExpression('/^\s*import\s/m', $module);

        // One mechanism, declared once, and it stands down for reduced motion.
        self::assertStringContainsString('html[data-facet-theme-shift]', $css);
        self::assertStringContainsString('@media (prefers-reduced-motion: no-preference)', $css);

        // The capsule keeps its own animation while the document crosses.
        self::assertStringContainsString(
            '*:not(.facet-theme-toggle, .facet-theme-toggle *)',
            $css
        );

        // Nothing about a transition is in the served markup: the page a
        // visitor opens has a theme, not a theme change.
        self::assertStringNotContainsString('data-facet-theme-shift', self::html());
    }

    /**
     * Runs the real inline bootstrap in Node against a stubbed storage and a
     * stubbed clock, and reports the theme it stamped — `unset` if it stamped
     * none.
     *
     * @param string     $storage a property descriptor for `window.localStorage`
     * @param int|string $hour    what `getHours()` returns, or a JS expression
     */
    private static function bootstrapResult(string $storage, int|string $hour): string
    {
        $node = self::nodeBinary();

        if ($node === null) {
            self::markTestSkipped('Node is not available; the bootstrap harness needs a JS runtime.');
        }

        $script = self::inlineBootstrapSource();

        $harness = <<<JS
        let applied = 'unset';
        const document = {
            documentElement: {
                setAttribute(name, value) {
                    if (name === 'data-theme') {
                        applied = value;
                    }
                },
            },
        };
        const window = { {$storage} };

        /* A clock that says exactly one thing, so the assertion is about the
           rule rather than about when the suite ran. */
        const Date = class {
            getHours() {
                return {$hour};
            }
        };

        {$script}

        console.log(applied);
        JS;

        $file = tempnam(sys_get_temp_dir(), 'facet-theme-');
        self::assertIsString($file);
        file_put_contents($file, $harness);

        $output = [];
        $status = 0;
        exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $status);
        unlink($file);

        $result = trim(implode("\n", $output));

        self::assertSame(0, $status, 'The bootstrap must not throw — ' . $result);

        return $result;
    }

    /** Criterion 11: shared structure stays neutral; the selected skin owns identity. */
    public function testVisualTokensAreNamespacedAndOwnedByTheSkin(): void
    {
        $css = self::css();

        /*
         * The shell's own declarations stay namespaced. The Tailwind `@theme`
         * block is excluded from that rule and held to a stricter one below:
         * its key names are Tailwind's vocabulary — `--text-display` is what
         * makes a `text-display` utility exist, and prefixing it would simply
         * produce a differently-named utility — so the neutrality it has to
         * prove is about *values*, not names.
         */
        $themeBlock = self::tailwindThemeBlock($css);
        $shellCss = str_replace($themeBlock, '', $css);

        preg_match_all('/(--[a-z0-9-]+)\s*:/i', $shellCss, $matches);

        self::assertNotEmpty($matches[1]);

        foreach (array_unique($matches[1]) as $token) {
            self::assertStringStartsWith('--facet-', $token, 'Shell tokens must be namespaced');
        }

        /*
         * Criterion 11 restated for the theme block: naming the design system
         * to Tailwind must not copy any of it into the shared layer. Every
         * value is a reference to a `--facet-` variable, so the skin remains
         * the only place a visual decision is written down — and a literal
         * here would be exactly the identity leak this test exists to catch.
         */
        preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $themeBlock, $themeMatches, PREG_SET_ORDER);

        self::assertNotEmpty($themeMatches, 'The Tailwind theme block must declare tokens');

        foreach ($themeMatches as [, $token, $value]) {
            self::assertMatchesRegularExpression(
                '/^var\(\s*--facet-[a-z0-9-]+/i',
                trim($value),
                sprintf('%s must resolve through a --facet- variable, not a literal', $token)
            );
        }

        // Colours belong to tokens, not to components: no component rule may
        // name a literal colour.
        $components = self::css();
        $componentsStart = strpos($components, '@layer components');
        self::assertIsInt($componentsStart);

        $componentBlock = substr($components, $componentsStart);

        self::assertSame(
            0,
            preg_match_all('/#[0-9a-f]{3,8}\b/i', $componentBlock),
            'Shell components must reference tokens, never literal colours'
        );

        // Phase 4 gives the skin its own light/dark primitives. They stay
        // namespaced and components consume semantic variables rather than
        // repeating colour literals throughout individual declarations.
        $skinCss = file_get_contents(
            self::root() . '/resources/skins/evolving-interface/skin.css'
        );
        self::assertIsString($skinCss);
        preg_match_all('/(--[a-z0-9-]+)\s*:/i', $skinCss, $skinMatches);
        self::assertNotEmpty($skinMatches[1]);
        foreach (array_unique($skinMatches[1]) as $token) {
            self::assertStringStartsWith('--facet-', $token, 'Skin tokens must be namespaced');
        }
        self::assertGreaterThan(0, preg_match_all('/#[0-9a-f]{6}\b/i', $skinCss));
        self::assertStringContainsString('--facet-light-canvas:', $skinCss);
        self::assertStringContainsString('--facet-dark-canvas:', $skinCss);
        self::assertStringContainsString('var(--facet-', $skinCss);
    }

    /**
     * Criterion 12: both themes stay readable, and the focus ring stays
     * visible against the surface it is drawn on.
     */
    public function testBothThemesMeetContrastFloors(): void
    {
        $themes = [
            'light' => self::tokensOf(':root'),
            'system dark' => self::tokensOf(":root:not([data-theme='light'])"),
            'forced dark' => self::tokensOf(":root[data-theme='dark']"),
            'forced light' => self::tokensOf(":root[data-theme='light']"),
        ];

        foreach ($themes as $name => $tokens) {
            foreach (['--facet-surface', '--facet-surface-raised'] as $background) {
                foreach (['--facet-ink', '--facet-ink-muted', '--facet-ink-subtle', '--facet-accent'] as $foreground) {
                    $ratio = self::contrast($tokens[$foreground], $tokens[$background]);

                    self::assertGreaterThanOrEqual(
                        self::MIN_TEXT_CONTRAST,
                        $ratio,
                        sprintf('%s: %s on %s is %.2f:1', $name, $foreground, $background, $ratio)
                    );
                }

                // Focus rings and control outlines are non-text UI.
                foreach (['--facet-focus', '--facet-border-strong'] as $ui) {
                    $ratio = self::contrast($tokens[$ui], $tokens[$background]);

                    self::assertGreaterThanOrEqual(
                        self::MIN_UI_CONTRAST,
                        $ratio,
                        sprintf('%s: %s on %s is %.2f:1', $name, $ui, $background, $ratio)
                    );
                }
            }

            // Text placed on the accent, e.g. the submit button.
            $ratio = self::contrast($tokens['--facet-accent-ink'], $tokens['--facet-accent']);
            self::assertGreaterThanOrEqual(
                self::MIN_TEXT_CONTRAST,
                $ratio,
                sprintf('%s: accent ink on accent is %.2f:1', $name, $ratio)
            );
        }

        // And the ring is actually drawn: a token nothing uses proves nothing.
        self::assertStringContainsString('outline: 2px solid var(--facet-focus)', self::css());
        self::assertStringContainsString(':focus-visible', self::css());
    }

    /**
     * The inline script the bootstrap partial emits, with the PHP stripped.
     */
    private static function inlineBootstrapSource(): string
    {
        self::assertSame(
            1,
            preg_match('#<script>(.*?)</script>#s', self::bootstrapPartial(), $matches),
            'The bootstrap partial must emit exactly one inline script'
        );
        $script = $matches[1] ?? null;
        self::assertIsString($script);

        return $script;
    }

    /**
     * The same source with its comments taken out, so an assertion about what
     * a file *does* is never satisfied — or defeated — by what it says.
     */
    private static function withoutComments(string $source): string
    {
        $stripped = preg_replace('#/\*.*?\*/#s', '', $source);
        self::assertIsString($stripped);

        $stripped = preg_replace('#^\s*//.*$#m', '', $stripped);
        self::assertIsString($stripped);

        return $stripped;
    }

    /**
     * The single element an expression must select.
     */
    private static function element(DOMXPath $xpath, string $expression): DOMElement
    {
        $nodes = $xpath->query($expression);
        self::assertNotFalse($nodes, 'Invalid XPath: ' . $expression);
        self::assertSame(1, $nodes->length, $expression . ' must select exactly one element');

        $node = $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $node);

        return $node;
    }

    private static function nodeBinary(): ?string
    {
        $path = trim((string) shell_exec('command -v node 2>/dev/null'));

        return $path === '' ? null : $path;
    }
}
