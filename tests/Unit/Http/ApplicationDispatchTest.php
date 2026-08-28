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
        $response = self::app()->handle(Request::create('GET', '/'));

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
        self::assertStringContainsString('Page not found', $response->body());
    }

    public function testAnUnsupportedMethodOnAKnownPathIsA405WithAnAllowHeader(): void
    {
        $response = self::app()->handle(Request::create('POST', '/'));

        self::assertSame(405, $response->status());
        self::assertSame('GET', $response->header('Allow'));
        self::assertStringContainsString('<!doctype html>', $response->body());
    }

    public function testAMethodOutsideTheContractOnAKnownPathIsAlso405(): void
    {
        $response = self::app()->handle(Request::create('PUT', '/contact'));

        self::assertSame(405, $response->status());
        self::assertSame('GET, POST', $response->header('Allow'));
    }

    public function testAValidProjectSlugRendersThatProject(): void
    {
        $project = CorpusLoader::default(self::root())->load()->projects()[0];
        $response = self::app()->handle(Request::create('GET', '/projects/' . $project->slug()));

        self::assertSame(200, $response->status());
        self::assertStringContainsString(
            htmlspecialchars($project->name(), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
            $response->body()
        );
    }

    public function testAnUnknownProjectSlugIsA404(): void
    {
        $response = self::app()->handle(Request::create('GET', '/projects/definitely-not-a-project'));

        self::assertSame(404, $response->status());
    }

    public function testAMalformedProjectSlugIsRejectedBeforeAnyLookup(): void
    {
        foreach (['/projects/Kushim', '/projects/ku--shim', '/projects/a', '/projects/kushim-'] as $path) {
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
        $get = self::app()->handle(Request::create('GET', '/contact'));
        $post = self::app()->handle(Request::create('POST', '/contact', [], ['message' => 'hi']));

        self::assertSame(200, $get->status());
        self::assertStringContainsString('method="post"', $get->body());
        self::assertNotSame($get->status(), $post->status(), 'POST must not silently render the GET page');
        self::assertSame(Response::STATUS_FORBIDDEN, $post->status());
    }

    public function testANonCanonicalPathRedirectsInsteadOfRenderingTwice(): void
    {
        $response = self::app()->handle(Request::create('GET', '/projects/'));

        self::assertSame(301, $response->status());
        self::assertSame('/projects', $response->header('Location'));
        self::assertSame('', $response->body());
    }

    public function testARedirectPreservesTheQueryString(): void
    {
        $response = self::app()->handle(Request::create('GET', '/projects/', ['page' => '2']));

        self::assertSame(301, $response->status());
        self::assertSame('/projects?page=2', $response->header('Location'));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>, 2: int, 3: ?string}>
     */
    public static function canonicalDispatchCases(): array
    {
        return [
            'encoded separator' => ['/projects/a%2Fb', [], 404, null],
            'trailing slash' => ['/projects/', [], 301, '/projects'],
            'repeated slash with query' => ['/projects//', ['page' => '2'], 301, '/projects?page=2'],
            'encoded valid slug' => ['/projects/ku%73him', [], 301, '/projects/kushim'],
            'already canonical' => ['/projects/kushim', [], 200, null],
            'encoded null' => ['/projects/kushim%00', [], 404, null],
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

            $path = $route->toPath($route->isDynamic() ? ['slug' => 'kushim'] : []);
            $response = self::app()->handle(Request::create('GET', $path));

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
            Request::create('GET', '/', ['skin' => 'evolving-interface'])
        );

        self::assertSame(200, $response->status());
        self::assertStringContainsString('data-skin="evolving-interface"', $response->body());
    }

    public function testHandlingIsFreeOfOutputSideEffects(): void
    {
        $before = ob_get_level();
        $response = self::app()->handle(Request::create('GET', '/'));

        self::assertSame($before, ob_get_level(), 'Dispatch must leave the output buffer stack untouched');
        self::assertNotSame('', $response->body());
    }
}
