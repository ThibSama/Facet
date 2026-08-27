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

    private static function html(string $path = '/'): string
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
        ]))->handle(Request::create('GET', '/'));

        self::assertNull($response->header('Set-Cookie'));

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
     * `localStorage` whose getter throws must leave the document untouched
     * rather than aborting the script.
     */
    public function testThePrePaintBootstrapSurvivesAStorageFailure(): void
    {
        $node = self::nodeBinary();

        if ($node === null) {
            self::markTestSkipped('Node is not available; the bootstrap harness needs a JS runtime.');
        }

        $script = self::inlineBootstrapSource();

        $cases = [
            'throwing' => 'get localStorage() { throw new Error("SecurityError"); }',
            'empty' => 'get localStorage() { return { getItem() { return null; } }; }',
            'hostile' => 'get localStorage() { return { getItem() { return "<script>"; } }; }',
            'stored-dark' => 'get localStorage() { return { getItem() { return "dark"; } }; }',
        ];

        $expected = [
            'throwing' => 'unset',
            'empty' => 'unset',
            'hostile' => 'unset',
            'stored-dark' => 'dark',
        ];

        foreach ($cases as $name => $storage) {
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

            self::assertSame(0, $status, $name . ': the bootstrap must not throw — ' . $result);
            self::assertSame($expected[$name], $result, $name);
        }
    }

    /**
     * Criterion 11: the shell declares structural tokens only, and the skin
     * carries no colour of its own yet.
     */
    public function testOnlyStructuralTokensAreDeclared(): void
    {
        preg_match_all('/(--[a-z0-9-]+)\s*:/i', self::css(), $matches);

        self::assertNotEmpty($matches[1]);

        foreach (array_unique($matches[1]) as $token) {
            self::assertStringStartsWith('--facet-', $token, 'Shell tokens must be namespaced');
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

        // The skin has no identity yet — it aliases the shared tokens, which
        // is what keeps it theme-correct before Phase 4 gives it real values.
        $skinCss = file_get_contents(
            self::root() . '/resources/skins/evolving-interface/skin.css'
        );
        self::assertIsString($skinCss);
        self::assertSame(0, preg_match_all('/#[0-9a-f]{3,8}\b/i', $skinCss));
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
