<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Http;

use Facet\Config\Config;
use Facet\Content\CorpusLoader;
use Facet\Http\Application;
use Facet\Http\Request;
use Facet\Http\Response;
use Facet\Routing\HttpMethod;
use Facet\Routing\RouteCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The dispatcher, driven end to end without a web server.
 *
 * Every case below is a plain Request in and a Response out: no superglobal is
 * populated, nothing is echoed, and no header is sent. That is the property
 * that makes the routing and disclosure rules assertable at all.
 */
final class ApplicationDispatchTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function app(string $environment = 'local', bool $debug = false): Application
    {
        return Application::boot(self::root(), Config::fromArray([
            'APP_NAME' => 'Facet',
            'APP_ENV' => $environment,
            'APP_KEY' => 'test-key',
            'APP_URL' => 'https://portfolio.example',
            'APP_LOCALE' => 'en',
            'APP_DEBUG' => $debug ? 'true' : 'false',
        ]));
    }

    public function testHomeRendersServerSideHtml(): void
    {
        $response = self::app()->handle(Request::create('GET', '/fr'));

        self::assertSame(200, $response->status());
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        self::assertStringContainsString('<!doctype html>', $response->body());
        self::assertStringContainsString('</html>', $response->body());
    }

    public function testAnUnknownPathIsA404WithAServerRenderedPage(): void
    {
        $response = self::app()->handle(Request::create('GET', '/no-such-page'));

        self::assertSame(404, $response->status());
        self::assertStringContainsString('<!doctype html>', $response->body());
        self::assertStringContainsString('Page introuvable', $response->body());
    }

    /**
     * The unprefixed URLs are entry points, not pages.
     *
     * They resolve a language and redirect to the canonical localized URL —
     * temporarily, because the answer depends on a preference the visitor may
     * change — so there is never a second indexable spelling of a page.
     */
    public function testAnUnprefixedPublicUrlRedirectsToItsLocalizedCounterpart(): void
    {
        foreach ([
            '/' => '/fr',
            '/projects' => '/fr/projects',
            '/about' => '/fr/about',
            '/contact' => '/fr/contact',
            '/projects/kushim' => '/fr/projects/kushim',
        ] as $entry => $expected) {
            $response = self::app()->handle(Request::create('GET', $entry));

            self::assertSame(302, $response->status(), $entry);
            self::assertSame($expected, $response->header('Location'), $entry);
            self::assertSame('', $response->body(), $entry);
        }
    }

    public function testTheEntryRedirectHonoursThePreferenceThenTheBrowserThenFrench(): void
    {
        $remembered = self::app()->handle(
            Request::create('GET', '/projects', [], [], ['facet_locale' => 'en'], ['accept-language' => 'fr'])
        );
        self::assertSame('/en/projects', $remembered->header('Location'));

        $negotiated = self::app()->handle(
            Request::create('GET', '/projects', [], [], [], ['accept-language' => 'en-GB,en;q=0.9,fr;q=0.5'])
        );
        self::assertSame('/en/projects', $negotiated->header('Location'));

        $unsupported = self::app()->handle(
            Request::create('GET', '/projects', [], [], [], ['accept-language' => 'de-DE'])
        );
        self::assertSame('/fr/projects', $unsupported->header('Location'));

        $malformed = self::app()->handle(
            Request::create('GET', '/projects', [], [], ['facet_locale' => 'zz'], ['accept-language' => '@@@'])
        );
        self::assertSame('/fr/projects', $malformed->header('Location'));
    }

    /**
     * An explicit locale URL is the strongest statement there is, so it beats
     * a remembered preference and the browser's header alike — and it replaces
     * the preference, so the next unprefixed entry follows the visitor rather
     * than sending them back.
     */
    public function testAnExplicitLocaleUrlOverridesAndReplacesThePreference(): void
    {
        $response = self::app()->handle(
            Request::create('GET', '/en/about', [], [], ['facet_locale' => 'fr'], ['accept-language' => 'fr'])
        );

        self::assertSame(200, $response->status());
        self::assertStringContainsString('<html lang="en"', $response->body());
        self::assertStringContainsString('facet_locale=en;', (string) $response->header('Set-Cookie'));
        self::assertStringContainsString('HttpOnly', (string) $response->header('Set-Cookie'));
        self::assertStringContainsString('SameSite=Lax', (string) $response->header('Set-Cookie'));
        self::assertStringContainsString('Secure', (string) $response->header('Set-Cookie'));

        // Nothing to write when the preference already says what the URL does.
        $again = self::app()->handle(
            Request::create('GET', '/en/about', [], [], ['facet_locale' => 'en'])
        );
        self::assertNull($again->header('Set-Cookie'));
    }

    /**
     * A language the site does not serve is a routing miss. It is never
     * repaired into French under a German-looking URL, because that would make
     * `/de/about` an indexable page claiming to be German.
     */
    public function testAnUnsupportedLanguagePrefixIsNotFound(): void
    {
        foreach (['/de', '/de/projects', '/es/about', '/FR', '/fr-FR'] as $path) {
            $response = self::app()->handle(Request::create('GET', $path));

            self::assertSame(404, $response->status(), $path);
        }
    }

    public function testAnUnsupportedMethodOnAKnownPathIsA405WithAnAllowHeader(): void
    {
        $response = self::app()->handle(Request::create('POST', '/fr'));

        self::assertSame(405, $response->status());
        self::assertSame('GET', $response->header('Allow'));
        self::assertStringContainsString('<!doctype html>', $response->body());
    }

    public function testAMethodOutsideTheContractOnAKnownPathIsAlso405(): void
    {
        $response = self::app()->handle(Request::create('PUT', '/fr/contact'));

        self::assertSame(405, $response->status());
        self::assertSame('GET, POST', $response->header('Allow'));
    }

    public function testAValidProjectSlugRendersThatProject(): void
    {
        $project = CorpusLoader::default(self::root())->load()->projects()[0];
        $response = self::app()->handle(Request::create('GET', '/fr/projects/' . $project->slug()));

        self::assertSame(200, $response->status());
        self::assertStringContainsString(
            htmlspecialchars($project->name(), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
            $response->body()
        );
    }

    public function testAnUnknownProjectSlugIsA404(): void
    {
        $response = self::app()->handle(Request::create('GET', '/fr/projects/definitely-not-a-project'));

        self::assertSame(404, $response->status());
    }

    public function testAMalformedProjectSlugIsRejectedBeforeAnyLookup(): void
    {
        foreach (['/fr/projects/Kushim', '/fr/projects/ku--shim', '/fr/projects/a', '/fr/projects/kushim-'] as $path) {
            $response = self::app()->handle(Request::create('GET', $path));

            self::assertSame(404, $response->status(), $path . ' must be refused');
        }
    }

    /**
     * The two methods on one path really do take different branches. The POST
     * here carries no CSRF token, so the branch it takes is the refusal — which
     * is the point: an unproven POST must never be answered by quietly
     * rendering the GET page as though nothing had been submitted.
     */
    public function testGetAndPostOnTheSamePathTakeDifferentBranches(): void
    {
        $get = self::app()->handle(Request::create('GET', '/fr/contact'));
        $post = self::app()->handle(Request::create('POST', '/fr/contact', [], ['message' => 'hi']));

        self::assertSame(200, $get->status());
        self::assertStringContainsString('method="post"', $get->body());
        self::assertNotSame($get->status(), $post->status(), 'POST must not silently render the GET page');
        self::assertSame(Response::STATUS_FORBIDDEN, $post->status());
    }

    public function testANonCanonicalPathRedirectsInsteadOfRenderingTwice(): void
    {
        $response = self::app()->handle(Request::create('GET', '/fr/projects/'));

        self::assertSame(301, $response->status());
        self::assertSame('/fr/projects', $response->header('Location'));
        self::assertSame('', $response->body());
    }

    public function testARedirectPreservesTheQueryString(): void
    {
        $response = self::app()->handle(Request::create('GET', '/fr/projects/', ['page' => '2']));

        self::assertSame(301, $response->status());
        self::assertSame('/fr/projects?page=2', $response->header('Location'));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>, 2: int, 3: ?string}>
     */
    public static function canonicalDispatchCases(): array
    {
        return [
            'encoded separator' => ['/fr/projects/a%2Fb', [], 404, null],
            'trailing slash' => ['/fr/projects/', [], 301, '/fr/projects'],
            'repeated slash with query' => ['/fr/projects//', ['page' => '2'], 301, '/fr/projects?page=2'],
            'encoded valid slug' => ['/fr/projects/ku%73him', [], 301, '/fr/projects/kushim'],
            'already canonical' => ['/fr/projects/kushim', [], 200, null],
            'encoded null' => ['/fr/projects/kushim%00', [], 404, null],
        ];
    }

    /** @param array<string, string> $query */
    #[DataProvider('canonicalDispatchCases')]
    public function testCanonicalisationCooperatesWithRoutingAndParameterValidation(
        string $target,
        array $query,
        int $status,
        ?string $location
    ): void {
        $response = self::app()->handle(Request::create('GET', $target, $query));

        self::assertSame($status, $response->status());
        self::assertSame($location, $response->header('Location'));

        if ($location !== null) {
            self::assertNotSame($target, $location, 'A redirect must not point back to its inbound target');
        }
    }

    public function testEveryPublicContentRouteRendersOrDeclaresItselfUnbuilt(): void
    {
        foreach (RouteCatalog::publiclyReachable() as $route) {
            if (!$route->accepts(HttpMethod::Get)) {
                continue;
            }

            $values = [];

            foreach ($route->parameters() as $parameter) {
                $values[$parameter->name()] = $parameter->name() === 'locale' ? 'en' : 'kushim';
            }

            $path = $route->toPath($values);
            $response = self::app()->handle(Request::create('GET', $path));

            // An entry route answers 302 by design: it is where a language is
            // chosen, not where a page is served.
            if (RouteCatalog::isEntry($route->name())) {
                self::assertSame(302, $response->status(), $route->name());

                continue;
            }

            self::assertContains(
                $response->status(),
                [200, Response::STATUS_NOT_IMPLEMENTED],
                sprintf('%s (%s) answered %d', $route->name(), $path, $response->status())
            );
            if (str_starts_with($route->name(), 'technical.')) {
                self::assertNotSame('', $response->body());
                self::assertContains($response->header('Content-Type'), [
                    'application/xml; charset=utf-8',
                    'text/plain; charset=utf-8',
                ]);
            } else {
                self::assertStringContainsString('<!doctype html>', $response->body());
            }
        }
    }

    public function testTheSkinOverrideStillWorksThroughTheRequestObject(): void
    {
        $response = self::app('local')->handle(
            Request::create('GET', '/fr', ['skin' => 'evolving-interface'])
        );

        self::assertSame(200, $response->status());
        self::assertStringContainsString('data-skin="evolving-interface"', $response->body());
    }

    public function testHandlingIsFreeOfOutputSideEffects(): void
    {
        $before = ob_get_level();
        $response = self::app()->handle(Request::create('GET', '/fr'));

        self::assertSame($before, ob_get_level(), 'Dispatch must leave the output buffer stack untouched');
        self::assertNotSame('', $response->body());
    }
}
