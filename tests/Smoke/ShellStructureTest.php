<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use Facet\Config\Config;
use Facet\Http\Application;
use Facet\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The shared shell, asserted against the DOM the server actually produced.
 *
 * String matching would pass on markup no browser could use, so every claim
 * here is made through XPath over the parsed document: one header, one main,
 * one footer, a labelled navigation landmark, a skip link that reaches a real
 * target, and enhanced controls whose accessible state is coherent before any
 * JavaScript has run.
 *
 * The routes are swept rather than sampled. A shell is only shared if every
 * rendered route — including the error page — has the same one.
 */
final class ShellStructureTest extends TestCase
{
    /** Public routes that render a page today. */
    private const RENDERED_PATHS = ['/', '/projects', '/projects/kushim', '/about', '/contact'];

    private const NAV_ID = 'facet-primary-nav';

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function application(): Application
    {
        return Application::boot(self::root(), Config::fromArray([
            'APP_NAME' => 'Facet',
            'APP_ENV' => 'production',
            'APP_KEY' => 'test-key',
            'APP_LOCALE' => 'en',
        ]));
    }

    private static function html(string $path, int $expectedStatus = 200): string
    {
        $response = self::application()->handle(Request::create('GET', $path));

        self::assertSame($expectedStatus, $response->status(), $path);

        return $response->body();
    }

    private static function dom(string $html): DOMXPath
    {
        $document = new DOMDocument();

        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_use_internal_errors($previous);

        self::assertTrue($loaded, 'The rendered document must parse as HTML');

        return new DOMXPath($document);
    }

    /**
     * @return DOMNodeList<DOMElement>
     */
    private static function query(DOMXPath $xpath, string $expression): DOMNodeList
    {
        $result = $xpath->query($expression);
        self::assertNotFalse($result, 'Invalid XPath: ' . $expression);

        /** @var DOMNodeList<DOMElement> $result */
        return $result;
    }

    private static function one(DOMXPath $xpath, string $expression, string $message): DOMElement
    {
        $nodes = self::query($xpath, $expression);

        self::assertSame(1, $nodes->length, $message);

        $node = $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $node);

        return $node;
    }

    /**
     * The document a client without JavaScript sees: the same bytes, with every
     * script element removed.
     */
    private static function withoutScripts(string $html): string
    {
        $stripped = preg_replace('#<script\b[^>]*>.*?</script>#si', '', $html);
        self::assertIsString($stripped);

        return $stripped;
    }

    /**
     * Criterion 2: the landmark skeleton, on every rendered route.
     */
    public function testEveryRenderedRouteHasTheSameLandmarkSkeleton(): void
    {
        foreach (self::RENDERED_PATHS as $path) {
            $xpath = self::dom(self::html($path));

            self::one($xpath, '//header', $path . ' must have exactly one header');
            self::one($xpath, '//main', $path . ' must have exactly one main');
            self::one($xpath, '//footer', $path . ' must have exactly one footer');

            $nav = self::one($xpath, '//header//nav', $path . ' must have one navigation landmark in the header');
            self::assertSame('Primary', $nav->getAttribute('aria-label'), $path);
            self::assertSame(self::NAV_ID, $nav->getAttribute('id'), $path);

            $main = self::one($xpath, '//main', $path);
            self::assertSame('main', $main->getAttribute('id'), $path . ' must anchor the skip link');

            // The page's own heading lives inside main, not in the shell.
            self::assertGreaterThan(0, self::query($xpath, '//main//h1')->length, $path);
            self::assertSame(0, self::query($xpath, '//header//h1')->length, $path);
        }
    }

    /**
     * Criterion 2: the skip link is the first focusable element and points at a
     * target that exists.
     */
    public function testTheSkipLinkIsFirstAndReachesMain(): void
    {
        foreach (self::RENDERED_PATHS as $path) {
            $xpath = self::dom(self::html($path));

            $focusable = self::query(
                $xpath,
                '//body//*[self::a[@href] or self::button or self::input or self::textarea or self::select]'
                . '[not(@hidden)]'
            );

            self::assertGreaterThan(0, $focusable->length, $path);

            $first = $focusable->item(0);
            self::assertInstanceOf(DOMElement::class, $first);
            self::assertSame('a', $first->tagName, $path . ': the first focusable element must be the skip link');
            self::assertSame('#main', $first->getAttribute('href'), $path);
            self::assertSame('Skip to content', trim($first->textContent), $path);

            // It is moved off-screen, never removed from the tab order.
            self::assertSame('', $first->getAttribute('hidden'), $path);
            self::assertSame('', $first->getAttribute('aria-hidden'), $path);
            self::assertSame('', $first->getAttribute('tabindex'), $path);
        }
    }

    /**
     * Criterion 3: active navigation is correct for exact routes and for URLs
     * nested underneath one.
     */
    public function testActiveNavigationIsCorrectForExactAndNestedUrls(): void
    {
        $expectations = [
            '/' => 'Home',
            '/projects' => 'Projects',
            '/projects/kushim' => 'Projects',
            '/about' => 'About',
            '/contact' => 'Contact',
        ];

        foreach ($expectations as $path => $label) {
            $xpath = self::dom(self::html($path));

            $current = self::query($xpath, '//header//nav//a[@aria-current="page"]');

            self::assertSame(1, $current->length, $path . ' must mark exactly one link current');

            $link = $current->item(0);
            self::assertInstanceOf(DOMElement::class, $link);
            self::assertSame($label, trim($link->textContent), $path);
        }
    }

    /**
     * An unrouted URL still renders the shell, and marks nothing current.
     */
    public function testTheErrorPageRendersTheSameShellWithNoCurrentSection(): void
    {
        $xpath = self::dom(self::html('/definitely-not-a-page', 404));

        self::one($xpath, '//header', 'The 404 page must render the shared header');
        self::one($xpath, '//main', 'The 404 page must render main');
        self::one($xpath, '//footer', 'The 404 page must render the shared footer');
        self::assertSame(4, self::query($xpath, '//header//nav//a')->length);
        self::assertSame(0, self::query($xpath, '//header//nav//a[@aria-current]')->length);
    }

    /**
     * Criterion 4: without JavaScript the navigation is present, visible and
     * complete — and no control that needs JavaScript is offered.
     */
    public function testNavigationIsFullyUsableWithoutJavaScript(): void
    {
        foreach (self::RENDERED_PATHS as $path) {
            $noJs = self::withoutScripts(self::html($path));
            $xpath = self::dom($noJs);

            self::assertStringNotContainsString('<noscript', $noJs, $path);

            $links = self::query($xpath, '//header//nav//a[@href]');
            self::assertSame(4, $links->length, $path . ': every section must be reachable without JavaScript');

            $nav = self::one($xpath, '//header//nav', $path);
            self::assertSame('', $nav->getAttribute('hidden'), $path . ': the navigation must not start hidden');

            // Both enhanced controls are inert until the runtime reveals them.
            foreach (['data-facet-nav-toggle', 'data-facet-theme-toggle'] as $hook) {
                $control = self::one($xpath, '//button[@' . $hook . ']', $path . ': ' . $hook);
                self::assertTrue($control->hasAttribute('hidden'), $path . ': ' . $hook . ' must ship hidden');
            }

            // The footer repeats the sections, so a collapsed header is never
            // the only way out of a page.
            self::assertSame(4, self::query($xpath, '//footer//a[@href]')->length, $path);
        }
    }

    /**
     * Criterion 4/5: the collapse is driven by a real button with a real
     * target, not by hover, and every enhanced control is a typed button.
     */
    public function testEnhancedControlsDeclareTheirStateTargetAndName(): void
    {
        $xpath = self::dom(self::html('/'));

        $navToggle = self::one($xpath, '//button[@data-facet-nav-toggle]', 'one navigation toggle');
        self::assertSame('button', $navToggle->getAttribute('type'), 'A toggle inside no form must still not submit');
        self::assertSame(self::NAV_ID, $navToggle->getAttribute('aria-controls'));
        self::assertContains($navToggle->getAttribute('aria-expanded'), ['true', 'false']);
        self::assertNotSame('', trim($navToggle->textContent), 'The toggle needs an accessible name');

        // aria-controls must resolve: a relationship pointing at nothing is
        // worse than none at all.
        self::assertSame(
            1,
            self::query($xpath, '//*[@id="' . self::NAV_ID . '"]')->length,
            'aria-controls must reference exactly one existing element'
        );

        $themeToggle = self::one($xpath, '//button[@data-facet-theme-toggle]', 'one theme toggle');
        self::assertSame('button', $themeToggle->getAttribute('type'));
        self::assertContains($themeToggle->getAttribute('aria-pressed'), ['true', 'false']);
        self::assertNotSame('', trim($themeToggle->textContent), 'The theme control needs an accessible name');

        // Decorative glyphs are hidden from assistive technology so the name
        // is the text and nothing else.
        foreach (self::query($xpath, '//header//span[contains(@class, "__glyph")]') as $glyph) {
            self::assertSame('true', $glyph->getAttribute('aria-hidden'));
        }
    }

    /**
     * Criterion 4: nothing in the shell is reachable by hover only. The
     * stylesheet may decorate on hover, but no rule may be the sole way to
     * reveal navigation.
     */
    public function testTheStylesheetNeverRevealsNavigationOnHoverAlone(): void
    {
        $css = file_get_contents(self::root() . '/resources/css/app.css');
        self::assertIsString($css);

        foreach (self::hoverRules($css) as $rule) {
            self::assertStringNotContainsString(
                'display:',
                str_replace(' ', '', $rule),
                'A :hover rule must not control visibility: ' . $rule
            );
            self::assertStringNotContainsString(
                'visibility:',
                str_replace(' ', '', $rule),
                'A :hover rule must not control visibility: ' . $rule
            );
        }
    }

    /**
     * Criterion 1: the shell is one structure the layout composes, not markup
     * each page repeats.
     */
    public function testTheShellIsComposedFromPartialsRatherThanRepeatedPerPage(): void
    {
        $views = self::root() . '/resources/skins/evolving-interface/views';

        foreach (['partials/header.php', 'partials/nav.php', 'partials/footer.php', 'layout.php'] as $file) {
            self::assertFileExists($views . '/' . $file);
        }

        $layout = file_get_contents($views . '/layout.php');
        self::assertIsString($layout);

        foreach (['partials/header.php', 'partials/footer.php'] as $partial) {
            self::assertStringContainsString($partial, $layout, 'The layout must compose ' . $partial);
        }

        // No page template may open its own document or shell landmark: they
        // all end by handing their content to the one layout.
        foreach (glob($views . '/page/*.php') ?: [] as $page) {
            $raw = file_get_contents($page);
            self::assertIsString($raw);

            foreach (['<html', '<body', '<header', '<footer', '<nav'] as $landmark) {
                self::assertStringNotContainsString(
                    $landmark,
                    $raw,
                    basename($page) . ' must not render its own shell'
                );
            }

            self::assertStringContainsString('layout.php', $raw, basename($page) . ' must render through the layout');
        }
    }

    /**
     * Criterion 6: nothing in the shell can force the document wider than a
     * 320px viewport. The stylesheet is the contract — fluid gutters, media
     * capped to the container, and wide content scrolling inside itself.
     */
    public function testTheShellDeclaresNoFixedWidthThatCanOverflowASmallViewport(): void
    {
        $css = file_get_contents(self::root() . '/resources/css/app.css');
        self::assertIsString($css);

        $normalised = preg_replace('/\s+/', ' ', $css);
        self::assertIsString($normalised);

        self::assertStringContainsString('max-width: 100%', $normalised, 'Media must be capped to its container');
        self::assertStringContainsString('overflow-wrap: break-word', $normalised, 'Long words must break');
        self::assertStringContainsString('overflow-x: auto', $normalised, 'Wide blocks must scroll inside themselves');
        self::assertStringContainsString(
            'padding-inline: var(--facet-shell-gutter)',
            $normalised,
            'The shell gutter must be fluid'
        );

        // A `width:` in px on a shell element is the classic 320px overflow.
        self::assertSame(
            [],
            self::pixelWidths($normalised),
            'The shell must not declare a fixed pixel width'
        );

        // Nor may it paper over an overflow instead of preventing one.
        self::assertStringNotContainsString('overflow-x: hidden', $normalised);
    }

    /**
     * @return list<string>
     */
    private static function hoverRules(string $css): array
    {
        preg_match_all('/[^{}]*:hover[^{}]*\{[^}]*\}/', $css, $matches);

        return $matches[0];
    }

    /**
     * @return list<string>
     */
    private static function pixelWidths(string $normalisedCss): array
    {
        preg_match_all('/(?<![-a-z])(?:min-|max-)?width: \d+px/', $normalisedCss, $matches);

        return $matches[0];
    }
}
