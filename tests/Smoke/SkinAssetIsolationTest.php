<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use Facet\Asset\AssetResolver;
use Facet\Asset\SharedAssets;
use Facet\Config\Config;
use Facet\Http\Application;
use Facet\Http\Request;
use Facet\Skin\SkinDefinition;
use Facet\Skin\SkinRegistry;
use Facet\Support\ViteManifest;
use PHPUnit\Framework\TestCase;

/**
 * Proves the build boundary end to end.
 *
 * The manifest deliberately contains an entrypoint — `fixture-unselected` —
 * that no registered skin owns. A rendered document that never references it,
 * while the file demonstrably exists in the build, is the evidence that skin
 * assets are isolated rather than merely organised.
 */
final class SkinAssetIsolationTest extends TestCase
{
    private const SHARED_ENTRY = 'resources/js/app.ts';
    private const SELECTED_ENTRY = 'resources/skins/evolving-interface/skin.ts';
    private const UNSELECTED_ENTRY = 'resources/skins/fixture-unselected/skin.ts';

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

    /**
     * A skin that exists only here. It is never registered, so nothing can
     * select it — but its entrypoint is built, so "not injected" is a real
     * claim rather than a vacuous one.
     */
    private static function unselectedSkin(): SkinDefinition
    {
        return SkinDefinition::define(
            'fixture-unselected',
            'fixture-unselected',
            'resources/skins/fixture-unselected/views',
            [self::UNSELECTED_ENTRY]
        );
    }

    public function testTheUnselectableFixtureIsNotARegisteredSkin(): void
    {
        self::assertFalse(
            SkinRegistry::default()->has('fixture-unselected'),
            'The fixture must never become selectable'
        );
    }

    public function testManifestDistinguishesSharedFromSkinAssets(): void
    {
        $manifest = self::manifest();

        foreach ([self::SHARED_ENTRY, self::SELECTED_ENTRY, self::UNSELECTED_ENTRY] as $entry) {
            self::assertTrue($manifest->has($entry), $entry . ' must be a build entrypoint');
        }

        $shared = $manifest->script(self::SHARED_ENTRY);
        $selected = $manifest->script(self::SELECTED_ENTRY);
        $unselected = $manifest->script(self::UNSELECTED_ENTRY);

        self::assertNotSame($shared, $selected, 'Shared and skin JS must be separate artefacts');
        self::assertNotSame($selected, $unselected, 'Each skin must own a distinct artefact');
        self::assertStringContainsString('/app-', $shared);
        self::assertStringContainsString('/skin-evolving-interface-', $selected);
        self::assertStringContainsString('/skin-fixture-unselected-', $unselected);

        self::assertNotSame(
            $manifest->styles(self::SHARED_ENTRY),
            $manifest->styles(self::SELECTED_ENTRY),
            'A skin stylesheet must not be the shared stylesheet'
        );
        self::assertTrue(SharedAssets::isShared(self::SHARED_ENTRY));
        self::assertFalse(SharedAssets::isShared(self::SELECTED_ENTRY));
    }

    public function testResolverEmitsSharedPlusSelectedSkinOnly(): void
    {
        $manifest = self::manifest();
        $resolver = AssetResolver::usingManifest($manifest);
        $skin = SkinRegistry::default()->defaultSkin();

        $bundle = $resolver->resolve($skin);

        self::assertSame([self::SHARED_ENTRY, self::SELECTED_ENTRY], $bundle->entrypoints());
        self::assertSame([], $bundle->missing());
        self::assertTrue($bundle->references($manifest->script(self::SHARED_ENTRY)));
        self::assertTrue($bundle->references($manifest->script(self::SELECTED_ENTRY)));
        self::assertFalse(
            $bundle->references($manifest->script(self::UNSELECTED_ENTRY)),
            'An unselected entrypoint must never enter the bundle'
        );

        foreach ($manifest->styles(self::UNSELECTED_ENTRY) as $style) {
            self::assertFalse($bundle->references($style));
        }
    }

    public function testSelectingTheFixtureWouldEmitItsAssetsAndNotTheOtherSkins(): void
    {
        // The mirror image: the isolation is a property of selection, not of
        // the fixture being somehow special-cased out of the pipeline.
        $bundle = AssetResolver::usingManifest(self::manifest())->resolve(self::unselectedSkin());

        self::assertSame([self::SHARED_ENTRY, self::UNSELECTED_ENTRY], $bundle->entrypoints());
        self::assertTrue($bundle->references(self::manifest()->script(self::UNSELECTED_ENTRY)));
        self::assertFalse($bundle->references(self::manifest()->script(self::SELECTED_ENTRY)));
    }

    public function testDevelopmentResolverToleratesAMissingBuild(): void
    {
        $resolver = AssetResolver::fromOptionalManifestFile(self::root() . '/public/build/does-not-exist.json');
        $bundle = $resolver->resolve(SkinRegistry::default()->defaultSkin());

        self::assertFalse($resolver->hasManifest());
        self::assertTrue($bundle->isEmpty());
        self::assertSame([self::SHARED_ENTRY, self::SELECTED_ENTRY], $bundle->missing());
    }

    public function testRenderedDocumentReferencesOnlySharedAndSelectedSkinAssets(): void
    {
        $manifest = self::manifest();
        $html = self::renderThroughEntrypoint();

        foreach ([self::SHARED_ENTRY, self::SELECTED_ENTRY] as $entry) {
            self::assertStringContainsString($manifest->script($entry), $html);

            foreach ($manifest->styles($entry) as $style) {
                self::assertStringContainsString($style, $html);
            }
        }

        self::assertStringNotContainsString($manifest->script(self::UNSELECTED_ENTRY), $html);
        self::assertStringNotContainsString('fixture-unselected', $html);

        foreach ($manifest->styles(self::UNSELECTED_ENTRY) as $style) {
            self::assertStringNotContainsString($style, $html);
        }

        // Nothing outside the bundle sneaks in through the template either.
        $bundle = AssetResolver::usingManifest($manifest)->resolve(SkinRegistry::default()->defaultSkin());
        preg_match_all('~/build/assets/[A-Za-z0-9._-]+~', $html, $matches);

        self::assertNotEmpty($matches[0]);
        self::assertSame([], array_values(array_diff(array_unique($matches[0]), $bundle->urls())));
    }

    public function testDevelopmentSkinOverrideStillRendersOnlyThatSkinsAssets(): void
    {
        $manifest = self::manifest();
        $html = self::render(self::application('local'), ['skin' => SkinRegistry::EVOLVING_INTERFACE]);

        self::assertStringContainsString('data-skin="evolving-interface"', $html);
        self::assertStringContainsString($manifest->script(self::SELECTED_ENTRY), $html);
        self::assertStringNotContainsString($manifest->script(self::UNSELECTED_ENTRY), $html);
    }

    public function testDevelopmentHtmlUsesHmrAndSourceEntrypointsWithoutAManifest(): void
    {
        $origin = 'http://127.0.0.1:65534';
        $html = self::render(self::application('local', null, $origin));

        self::assertStringContainsString($origin . '/@vite/client', $html);
        self::assertStringContainsString($origin . '/' . self::SHARED_ENTRY, $html);
        self::assertStringContainsString($origin . '/' . self::SELECTED_ENTRY, $html);
        self::assertStringNotContainsString('/build/assets/', $html);
        self::assertStringNotContainsString('fixture-unselected', $html);
    }

    public function testProductionHtmlNeverContainsHmrOrADevServerOrigin(): void
    {
        $manifest = self::manifest();
        $origin = 'http://127.0.0.1:65534';
        $html = self::render(self::application('production', null, $origin));

        self::assertStringContainsString($manifest->script(self::SHARED_ENTRY), $html);
        self::assertStringContainsString($manifest->script(self::SELECTED_ENTRY), $html);
        self::assertStringNotContainsString('/@vite/client', $html);
        self::assertStringNotContainsString($origin, $html);
    }

    public function testAProductionRequestForTheFixtureRendersTheDefaultSkin(): void
    {
        $manifest = self::manifest();
        $html = self::render(self::application('production'), ['skin' => 'fixture-unselected']);

        self::assertStringContainsString('data-skin="evolving-interface"', $html);
        self::assertStringContainsString($manifest->script(self::SELECTED_ENTRY), $html);
        self::assertStringNotContainsString($manifest->script(self::UNSELECTED_ENTRY), $html);
        self::assertStringNotContainsString('fixture-unselected', $html);
    }

    /**
     * The decisive pair: with the fixture registered (tests only) a dev
     * override really does swap every asset, and production still refuses the
     * exact same request. Isolation is a consequence of selection, not of the
     * fixture being unreachable by accident.
     */
    public function testOverrideSwapsAssetsInDevelopmentAndIsRefusedInProduction(): void
    {
        $manifest = self::manifest();
        $registry = SkinRegistry::create(
            [SkinRegistry::default()->defaultSkin(), self::unselectedSkin()],
            SkinRegistry::EVOLVING_INTERFACE
        );

        $development = self::render(self::application('local', $registry), ['skin' => 'fixture-unselected']);

        self::assertStringContainsString('data-skin="fixture-unselected"', $development);
        self::assertStringContainsString($manifest->script(self::UNSELECTED_ENTRY), $development);
        self::assertStringNotContainsString($manifest->script(self::SELECTED_ENTRY), $development);
        // The shared layer is present either way — that is what makes it shared.
        self::assertStringContainsString($manifest->script(self::SHARED_ENTRY), $development);

        $production = self::render(self::application('production', $registry), ['skin' => 'fixture-unselected']);

        self::assertStringContainsString('data-skin="evolving-interface"', $production);
        self::assertStringContainsString($manifest->script(self::SELECTED_ENTRY), $production);
        self::assertStringNotContainsString($manifest->script(self::UNSELECTED_ENTRY), $production);
    }

    /**
     * The end-to-end case the in-process tests stand in for: a real HTTP
     * request, with a real query string, served by the real entrypoint.
     */
    public function testOverServedHttpTheQueryStringNeverPullsInAnUnselectedSkin(): void
    {
        $manifest = self::manifest();

        foreach (self::overHttp() as $query => $html) {
            self::assertStringContainsString('<!doctype html>', $html, 'query: ' . $query);
            self::assertStringContainsString('data-skin="evolving-interface"', $html, 'query: ' . $query);
            self::assertStringContainsString($manifest->script(self::SELECTED_ENTRY), $html, 'query: ' . $query);
            self::assertStringNotContainsString($manifest->script(self::UNSELECTED_ENTRY), $html, 'query: ' . $query);
            self::assertStringNotContainsString('fixture-unselected', $html, 'query: ' . $query);
        }
    }

    /**
     * @return array<string, string> raw query string => rendered HTML
     */
    private static function overHttp(): array
    {
        $host = '127.0.0.1:8931';
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = proc_open(
            [PHP_BINARY, '-S', $host, '-t', self::root() . '/public', self::root() . '/public/index.php'],
            $descriptors,
            $pipes,
            self::root(),
            ['APP_NAME' => 'Facet', 'APP_ENV' => 'local', 'APP_KEY' => 'test-key', 'PATH' => getenv('PATH') ?: '/usr/bin']
        );

        if (!is_resource($process)) {
            self::markTestSkipped('Could not start the PHP built-in server.');
        }

        $results = [];

        try {
            $ready = false;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $socket = @fsockopen('127.0.0.1', 8931, $errno, $errstr, 0.1);

                if (is_resource($socket)) {
                    fclose($socket);
                    $ready = true;

                    break;
                }

                usleep(50_000);
            }

            if (!$ready) {
                self::markTestSkipped('The PHP built-in server did not come up.');
            }

            $queries = ['', 'skin=evolving-interface', 'skin=fixture-unselected', 'skin=../../etc/passwd'];

            foreach ($queries as $query) {
                $body = @file_get_contents('http://' . $host . '/?' . $query);
                self::assertIsString($body, 'No response for query: ' . $query);
                $results[$query] = $body;
            }
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            proc_terminate($process);
            proc_close($process);
        }

        return $results;
    }

    private static function application(
        string $environment,
        ?SkinRegistry $registry = null,
        ?string $devServerOrigin = null
    ): Application
    {
        $values = [
            'APP_NAME' => 'Facet',
            'APP_ENV' => $environment,
            'APP_KEY' => 'test-key',
            'APP_LOCALE' => 'en',
        ];

        if ($devServerOrigin !== null) {
            $values['VITE_DEV_SERVER_ORIGIN'] = $devServerOrigin;
        }

        return Application::boot(
            self::root(),
            Config::fromArray($values),
            $registry
        );
    }

    /**
     * Renders the home route in-process. The application is driven with an
     * explicit Request, so the query string a test wants to prove something
     * about is the one the runtime actually sees.
     *
     * @param array<string, string> $query
     */
    private static function render(Application $application, array $query = []): string
    {
        $response = $application->handle(Request::create('GET', '/', $query));

        self::assertSame(200, $response->status(), 'Home must render successfully');

        return $response->body();
    }

    /**
     * @param array<string, string> $query
     */
    private static function renderThroughEntrypoint(string $environment = 'local', array $query = []): string
    {
        $command = sprintf(
            'APP_NAME=Facet APP_ENV=%s APP_KEY=test-key QUERY_STRING=%s %s -d variables_order=EGPCS %s 2>&1',
            escapeshellarg($environment),
            escapeshellarg(http_build_query($query)),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::root() . '/public/index.php')
        );

        $output = [];
        $status = 0;
        exec($command, $output, $status);
        $html = implode("\n", $output);

        self::assertSame(0, $status, 'Entrypoint exited non-zero: ' . $html);

        return $html;
    }
}
