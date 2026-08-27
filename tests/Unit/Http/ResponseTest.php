<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Http;

use Facet\Http\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * A response is a value. Nothing here may reach output, and a header must not
 * be constructible in a shape that could inject another one.
 */
final class ResponseTest extends TestCase
{
    public function testHtmlCarriesStatusHeadersAndBody(): void
    {
        $response = Response::html('<p>hi</p>');

        self::assertSame(200, $response->status());
        self::assertSame('OK', $response->reasonPhrase());
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        self::assertSame('no-cache', $response->header('Cache-Control'));
        self::assertSame('<p>hi</p>', $response->body());
        self::assertTrue($response->isSuccessful());
        self::assertFalse($response->isError());
        self::assertFalse($response->isRedirect());
    }

    public function testCallerSuppliedHeadersWinOverDefaults(): void
    {
        $response = Response::html('x', 200, ['Content-Type' => 'application/xhtml+xml']);

        self::assertSame('application/xhtml+xml', $response->header('Content-Type'));
    }

    public function testARedirectIsRepresentableWithoutAnySideEffect(): void
    {
        $response = Response::redirect('/projects', Response::STATUS_MOVED_PERMANENTLY);

        self::assertSame(301, $response->status());
        self::assertSame('/projects', $response->header('Location'));
        self::assertTrue($response->isRedirect());
        self::assertSame('', $response->body());
    }

    public function testErrorStatusesAreClassified(): void
    {
        self::assertTrue(Response::html('', 404)->isError());
        self::assertTrue(Response::html('', 500)->isError());
        self::assertSame('Method Not Allowed', Response::html('', 405)->reasonPhrase());
    }

    public function testHeadersAreImmutableAndReplacedCaseInsensitively(): void
    {
        $original = Response::html('x');
        $updated = $original->withHeader('content-type', 'text/plain; charset=utf-8');

        self::assertSame('text/html; charset=utf-8', $original->header('Content-Type'));
        self::assertSame('text/plain; charset=utf-8', $updated->header('Content-Type'));
        self::assertCount(2, $updated->headers());
    }

    public function testARedirectLocationCannotInjectAHeader(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Response::redirect("/projects\r\nSet-Cookie: admin=1");
    }

    public function testAHeaderValueCannotSpanLines(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Response::html('x')->withHeader('X-Test', "a\r\nX-Injected: 1");
    }

    public function testARedirectMustUseARedirectStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Response::redirect('/projects', 200);
    }

    public function testAnImpossibleStatusIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Response::html('x', 99);
    }
}
