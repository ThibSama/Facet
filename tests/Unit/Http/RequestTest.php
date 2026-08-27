<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Http;

use Facet\Http\Request;
use Facet\Routing\HttpMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The request must be complete, explicit and superglobal-free.
 *
 * These tests are the reason the rest of the HTTP suite can exist: if a request
 * could only be produced by a real web server, none of the dispatch rules could
 * be asserted at all.
 */
final class RequestTest extends TestCase
{
    public function testCreateTakesEveryInputExplicitly(): void
    {
        $request = Request::create(
            'POST',
            '/contact?ref=nav',
            ['ref' => 'nav'],
            ['message' => 'hello'],
            ['facet_skin' => 'evolving-interface'],
            ['Accept-Language' => 'fr']
        );

        self::assertSame('POST', $request->method());
        self::assertSame(HttpMethod::Post, $request->httpMethod());
        self::assertTrue($request->isMethod(HttpMethod::Post));
        self::assertSame('/contact', $request->path());
        self::assertSame('nav', $request->queryParam('ref'));
        self::assertSame('hello', $request->bodyParam('message'));
        self::assertSame('evolving-interface', $request->cookie('facet_skin'));
        self::assertSame('fr', $request->header('accept-language'));
        self::assertSame('fr', $request->header('Accept-Language'), 'Header lookup is case-insensitive');
    }

    public function testMethodIsUpperCasedAndUnknownMethodsAreModelledAsNull(): void
    {
        self::assertSame(HttpMethod::Get, Request::create('get', '/')->httpMethod());
        self::assertSame('DELETE', Request::create('delete', '/')->method());
        self::assertNull(
            Request::create('DELETE', '/')->httpMethod(),
            'A method outside the route contract is null, not a silent GET'
        );
    }

    public function testAGarbageMethodNeverSurvivesAsItself(): void
    {
        // Whatever a client sends, the value the runtime carries stays a token.
        self::assertSame('INVALID', Request::create("GET\r\nX-Injected: 1", '/')->method());
        self::assertNull(Request::create("GET\r\nX-Injected: 1", '/')->httpMethod());
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function pathCases(): array
    {
        return [
            ['/', '/'],
            ['', '/'],
            ['/projects', '/projects'],
            ['/projects/', '/projects'],
            ['//projects//kushim//', '/projects/kushim'],
            ['/projects?slug=x', '/projects'],
            ['/projects/kushim#anchor', '/projects/kushim#anchor'],
        ];
    }

    #[DataProvider('pathCases')]
    public function testPathIsNormalised(string $target, string $expected): void
    {
        self::assertSame($expected, Request::create('GET', $target)->path());
    }

    public function testPercentEncodedSegmentsAreDecodedOneSegmentAtATime(): void
    {
        $request = Request::create('GET', '/projects/math-l-home');
        self::assertSame(['projects', 'math-l-home'], $request->segments());

        // %2F must stay a literal inside one segment: decoding the whole path
        // at once would let a client invent a path separator.
        $smuggled = Request::create('GET', '/projects/a%2Fb');
        self::assertSame(['projects', 'a/b'], $smuggled->segments());
        self::assertCount(2, $smuggled->segments());
    }

    public function testNullBytesRemainVisibleToParameterValidation(): void
    {
        $request = Request::create('GET', '/projects/kushim%00');

        self::assertSame(['projects', "kushim\0"], $request->segments());
        self::assertFalse($request->needsCanonicalRedirect());
    }

    public function testNonCanonicalPathsAreReportedWithTheirCanonicalTarget(): void
    {
        $request = Request::create('GET', '/projects/', ['page' => '2']);

        self::assertTrue($request->needsCanonicalRedirect());
        self::assertSame('/projects?page=2', $request->canonicalTarget());

        self::assertFalse(Request::create('GET', '/projects')->needsCanonicalRedirect());
        self::assertFalse(Request::create('GET', '/')->needsCanonicalRedirect());
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>, 2: bool, 3: string}>
     */
    public static function canonicalisationCases(): array
    {
        return [
            'encoded separator' => ['/projects/a%2Fb', [], false, '/projects/a%2Fb'],
            'lowercase encoded separator' => ['/projects/a%2fb', [], false, '/projects/a%2Fb'],
            'trailing slash' => ['/projects/', [], true, '/projects'],
            'repeated slash with query' => ['/projects//', ['page' => '2'], true, '/projects?page=2'],
            'encoded valid slug' => ['/projects/ku%73him', [], true, '/projects/kushim'],
            'already canonical' => ['/projects/kushim', [], false, '/projects/kushim'],
            'encoded null' => ['/projects/kushim%00', [], false, '/projects/kushim%00'],
        ];
    }

    /** @param array<string, string> $query */
    #[DataProvider('canonicalisationCases')]
    public function testCanonicalRedirectsNeverTargetTheInboundPath(
        string $target,
        array $query,
        bool $redirects,
        string $canonicalTarget
    ): void {
        $request = Request::create('GET', $target, $query);

        self::assertSame($redirects, $request->needsCanonicalRedirect());
        self::assertSame($canonicalTarget, $request->canonicalTarget());

        if ($redirects) {
            self::assertNotSame($target, $request->canonicalTarget());
        }
    }

    public function testFromGlobalsAdaptsServerArraysWithoutReadingThem(): void
    {
        $request = Request::fromGlobals(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/contact?ref=nav',
                'HTTP_ACCEPT_LANGUAGE' => 'fr',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            ],
            ['ref' => 'nav'],
            ['message' => 'hello'],
            ['facet_skin' => 'x']
        );

        self::assertSame('POST', $request->method());
        self::assertSame('/contact', $request->path());
        self::assertSame('fr', $request->header('accept-language'));
        self::assertSame('application/x-www-form-urlencoded', $request->header('content-type'));
        self::assertSame('hello', $request->bodyParam('message'));
    }

    public function testFromGlobalsDegradesToAPlainGetWhenNothingIsSet(): void
    {
        // The CLI case: no REQUEST_METHOD, no REQUEST_URI, and still a valid
        // request rather than a notice storm.
        $request = Request::fromGlobals([]);

        self::assertSame('GET', $request->method());
        self::assertSame('/', $request->path());
        self::assertSame([], $request->query());
    }

    public function testNonScalarInputIsDroppedRatherThanStringified(): void
    {
        $request = Request::create('GET', '/', ['ok' => 'yes', 'nested' => ['a'], 'n' => 5]);

        self::assertSame(['ok' => 'yes', 'n' => '5'], $request->query());
    }
}
