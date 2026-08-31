<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use Facet\Config\Config;
use Facet\Http\Application;
use Facet\Http\Request;
use Facet\Http\Response;
use Facet\Skin\SkinRegistry;
use Facet\Tests\Fixtures\ExplodingSkin;
use PHPUnit\Framework\TestCase;

/**
 * What an error page is allowed to say.
 *
 * The method is failure injection rather than inspection: a template throws an
 * exception whose message carries a fake credential and a fake absolute path,
 * and the production response is then searched for both. An assertion that the
 * page "looks safe" would prove nothing; an assertion that a value known to be
 * inside the exception is absent from the body proves the disclosure boundary
 * held.
 */
final class ErrorDisclosureTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function app(
        string $environment,
        bool $debug,
        ?SkinRegistry $registry = null
    ): Application {
        return Application::boot(
            self::root(),
            Config::fromArray([
                'APP_NAME' => 'Facet',
                'APP_ENV' => $environment,
                'APP_KEY' => 'test-key',
                'APP_LOCALE' => 'en',
                'APP_DEBUG' => $debug ? 'true' : 'false',
            ]),
            $registry
        );
    }

    private static function explode(string $environment, bool $debug, string $skinId): Response
    {
        return self::app($environment, $debug, ExplodingSkin::registry($skinId))
            ->handle(Request::create('GET', '/fr'));
    }

    public function testAnInjectedFailureIsA500(): void
    {
        $response = self::explode('production', false, ExplodingSkin::EXPLODING);

        self::assertSame(500, $response->status());
        self::assertStringContainsString('<!doctype html>', $response->body());
        self::assertStringContainsString('</html>', $response->body());
    }

    /**
     * The decisive assertion of this checkpoint.
     */
    public function testProductionNeverDisclosesTheSecretThePathOrTheTrace(): void
    {
        $body = self::explode('production', false, ExplodingSkin::EXPLODING)->body();

        self::assertStringNotContainsString(ExplodingSkin::FAKE_SECRET, $body);
        self::assertStringNotContainsString(ExplodingSkin::FAKE_PATH, $body);
        self::assertStringNotContainsString('Database connection failed', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
        self::assertStringNotContainsString('#0 ', $body, 'No stack frame may reach the page');
        self::assertStringNotContainsString(self::root(), $body, 'No filesystem path may reach the page');
        self::assertStringNotContainsString('.php', $body);
        self::assertStringNotContainsString('Diagnostics', $body);
        self::assertStringContainsString("Quelque chose s&apos;est mal passé", $body);
    }

    /**
     * A production instance with APP_DEBUG deliberately set on is still
     * production: {@see Config::isDebug()} refuses to open, so the response
     * must be identical to the one above.
     */
    public function testDebugCannotBeTurnedOnInProduction(): void
    {
        $body = self::explode('production', true, ExplodingSkin::EXPLODING)->body();

        self::assertStringNotContainsString(ExplodingSkin::FAKE_SECRET, $body);
        self::assertStringNotContainsString(ExplodingSkin::FAKE_PATH, $body);
        self::assertStringNotContainsString('Diagnostics', $body);
    }

    public function testLocalDebugExposesBoundedDiagnosticContext(): void
    {
        $body = self::explode('local', true, ExplodingSkin::EXPLODING)->body();

        self::assertStringContainsString('RuntimeException', $body);
        self::assertStringContainsString('Database connection failed', $body);
        self::assertStringContainsString('id="diagnostics"', $body);

        // Bounded: the diagnostics name files, never full filesystem paths.
        self::assertStringNotContainsString(self::root(), $body);
        self::assertLessThanOrEqual(
            20,
            substr_count($body, '<li>'),
            'Debug diagnostics must stay bounded rather than dumping a whole trace'
        );
    }

    public function testLocalWithoutDebugStillDisclosesNothing(): void
    {
        $body = self::explode('local', false, ExplodingSkin::EXPLODING)->body();

        self::assertStringNotContainsString(ExplodingSkin::FAKE_SECRET, $body);
        self::assertStringNotContainsString('Diagnostics', $body);
        self::assertStringNotContainsString('id="diagnostics"', $body);
    }

    /**
     * Criterion 13: reporting an error must not be able to fail.
     */
    public function testABrokenErrorTemplateFallsBackInsteadOfRecursing(): void
    {
        $response = self::explode('production', false, ExplodingSkin::EXPLODING_ERROR);

        self::assertSame(500, $response->status());
        self::assertStringContainsString('<!doctype html>', $response->body());
        self::assertStringContainsString("Quelque chose s&apos;est mal passé", $response->body());
        self::assertStringNotContainsString('The error template is broken too', $response->body());
        self::assertStringNotContainsString(ExplodingSkin::FAKE_SECRET, $response->body());
    }

    public function testTheFallbackDocumentIsUsableWithoutJavaScriptOrAssets(): void
    {
        $body = self::explode('production', false, ExplodingSkin::EXPLODING_ERROR)->body();

        self::assertStringNotContainsString('<script', $body);
        // The fallback's way out is the localized home of the language the
        // failure was reported in, not the unprefixed entry route: a page that
        // has already resolved a language should not send the reader through a
        // redirect to resolve it again.
        self::assertStringContainsString('<a href="/fr"', $body);
    }

    public function testNotFoundAndMethodNotAllowedAlsoRenderValidHtml(): void
    {
        $app = self::app('production', false);

        $notFound = $app->handle(Request::create('GET', '/nope'));
        $notAllowed = $app->handle(Request::create('POST', '/fr'));

        foreach ([$notFound, $notAllowed] as $response) {
            self::assertStringContainsString('<!doctype html>', $response->body());
            self::assertStringContainsString('</html>', $response->body());
            self::assertStringNotContainsString(self::root(), $response->body());
        }

        self::assertSame(404, $notFound->status());
        self::assertSame(405, $notAllowed->status());
        self::assertSame('GET', $notAllowed->header('Allow'));
    }
}
