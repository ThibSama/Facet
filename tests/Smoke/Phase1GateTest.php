<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use Facet\Support\ViteManifest;
use PHPUnit\Framework\TestCase;

/**
 * The Phase 1 exit gate, proved end to end against a production server.
 *
 * Everything below runs over real HTTP, against `public/index.php`, in
 * production configuration, with no Vite dev server running and no Node in the
 * request path. That combination is the claim Phase 1 makes: the site is
 * server-rendered PHP that serves fingerprinted assets from a build artefact
 * and degrades to plain HTML.
 *
 * The individual behaviours are unit- and smoke-tested elsewhere. What is new
 * here is that they are asserted *together*, on one running server, including
 * the thing an in-process test cannot see — that the asset URLs the documents
 * reference are actually served.
 */
final class Phase1GateTest extends TestCase
{
    /** @var array{host: string, process: resource, pipes: array<int, resource>}|null */
    private static ?array $server = null;

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server === null) {
            return;
        }

        foreach (self::$server['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_terminate(self::$server['process']);
        proc_close(self::$server['process']);

        self::$server = null;
    }

    /**
     * One production server for the whole class. The environment handed to it
     * carries no Vite dev-server origin, so a document that referenced one
     * would have had to invent it.
     */
    private static function host(): string
    {
        if (self::$server !== null) {
            return self::$server['host'];
        }

        $port = self::freePort();
        $host = '127.0.0.1:' . $port;

        $process = proc_open(
            [
                PHP_BINARY,
                '-d',
                'variables_order=EGPCS',
                '-S',
                $host,
                '-t',
                self::root() . '/public',
                self::root() . '/public/index.php',
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::root(),
            [
                'APP_NAME' => 'Facet',
                'APP_ENV' => 'production',
                'APP_KEY' => 'phase1-gate-key',
                'APP_LOCALE' => 'en',
                'APP_DEBUG' => 'false',
                'PATH' => getenv('PATH') ?: '/usr/bin',
            ]
        );

        if (!is_resource($process)) {
            self::markTestSkipped('Could not start the PHP built-in server.');
        }

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

            if (is_resource($probe)) {
                fclose($probe);
                self::$server = ['host' => $host, 'process' => $process, 'pipes' => $pipes];

                return $host;
            }

            usleep(50_000);
        }

        proc_terminate($process);
        proc_close($process);

        self::markTestSkipped('The PHP built-in server did not come up on ' . $host);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, 'Could not reserve a port: ' . $error);

        $name = stream_socket_get_name($socket, false);
        self::assertIsString($name);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * @return array{status: int, headers: list<string>, body: string}
     */
    private static function get(string $path): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => 10,
            ],
        ]);

        $body = @file_get_contents('http://' . self::host() . $path, false, $context);
        self::assertIsString($body, 'No response for GET ' . $path);

        /** @var list<string> $headers */
        $headers = $http_response_header;

        self::assertNotEmpty($headers, 'No status line was returned');
        self::assertSame(1, preg_match('#HTTP/\S+\s+(\d{3})#', $headers[0], $matches), $headers[0]);
        $status = $matches[1] ?? null;
        self::assertIsString($status);

        return ['status' => (int) $status, 'headers' => $headers, 'body' => $body];
    }

    private static function headerValue(string $name, string ...$headers): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
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
     * @return DOMNodeList<DOMElement>
     */
    private static function query(DOMXPath $xpath, string $expression): DOMNodeList
    {
        $result = $xpath->query($expression);
        self::assertNotFalse($result);

        /** @var DOMNodeList<DOMElement> $result */
        return $result;
    }

    private static function manifest(): ViteManifest
    {
        $path = self::root() . '/public/build/manifest.json';

        if (!is_readable($path)) {
            self::markTestSkipped('No build present. Run `npm run build` (composer quality does).');
        }

        return ViteManifest::fromFile($path);
    }

    /**
     * Criterion 13: the representative public surface, answered by a real
     * production server with the statuses it declares.
     */
    public function testThePublicSurfaceAnswersItsDeclaredStatuses(): void
    {
        foreach (['/', '/projects', '/projects/kushim', '/about', '/contact'] as $path) {
            $response = self::get($path);

            self::assertSame(200, $response['status'], $path);
            self::assertSame(
                'text/html; charset=utf-8',
                self::headerValue('Content-Type', ...$response['headers']),
                $path
            );
            self::assertStringContainsString('<!doctype html>', $response['body'], $path);
            self::assertStringContainsString('</html>', $response['body'], $path);
        }

        $notFound = self::get('/definitely-not-a-page');
        self::assertSame(404, $notFound['status']);
        self::assertStringContainsString('Page not found', $notFound['body']);

        $unknownProject = self::get('/projects/no-such-project');
        self::assertSame(404, $unknownProject['status']);

        $redirect = self::get('/projects/');
        self::assertSame(301, $redirect['status']);
        self::assertSame('/projects', self::headerValue('Location', ...$redirect['headers']));
    }

    /**
     * Criterion 13: nothing served in production discloses a path, a
     * diagnostic or a configured secret — on success or on failure.
     */
    public function testProductionDisclosesNothingOnAnyStatus(): void
    {
        foreach (['/', '/projects', '/projects/kushim', '/about', '/contact', '/nope', '/projects/no-such-project'] as $path) {
            $body = self::get($path)['body'];

            foreach ([self::root(), 'phase1-gate-key', 'Fatal error', 'Warning:', 'Stack trace', 'Exception'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $body, $path . ' leaked ' . $forbidden);
            }
        }
    }

    /**
     * Criterion 14: production rendering comes from the built manifest. Every
     * asset a document references is fingerprinted, belongs to the shared
     * layer or the selected skin, and is actually served.
     */
    public function testProductionServesOnlyFingerprintedSharedAndSelectedSkinAssets(): void
    {
        $manifest = self::manifest();

        $shared = $manifest->script('resources/js/app.ts');
        $selected = $manifest->script('resources/skins/evolving-interface/skin.ts');
        $unselected = $manifest->script('resources/skins/fixture-unselected/skin.ts');

        $expected = array_unique(array_merge(
            [$shared, $selected],
            $manifest->styles('resources/js/app.ts'),
            $manifest->styles('resources/skins/evolving-interface/skin.ts')
        ));

        foreach (['/', '/projects', '/projects/kushim', '/about', '/contact', '/nope'] as $path) {
            $body = self::get($path)['body'];

            // No dev server, no HMR client, no source module.
            self::assertStringNotContainsString('@vite/client', $body, $path);
            self::assertStringNotContainsString('resources/js/app.ts', $body, $path);
            self::assertStringNotContainsString('fixture-unselected', $body, $path);
            self::assertStringNotContainsString($unselected, $body, $path);

            preg_match_all('~/build/assets/[A-Za-z0-9._-]+~', $body, $matches);
            $referenced = array_values(array_unique($matches[0]));

            self::assertNotEmpty($referenced, $path . ' must reference built assets');
            self::assertSame([], array_values(array_diff($referenced, $expected)), $path);

            foreach ($referenced as $url) {
                // Fingerprinted, so it can be cached immutably.
                self::assertMatchesRegularExpression(
                    '~/build/assets/[A-Za-z0-9_-]+-[A-Za-z0-9_-]{8}\.(?:js|css)$~',
                    $url,
                    $url . ' must carry a content hash'
                );
            }
        }

        // The URLs are not just well-formed: the server really serves them.
        foreach ($expected as $url) {
            $asset = self::get($url);

            self::assertSame(200, $asset['status'], $url . ' must be served');
            self::assertNotSame('', $asset['body'], $url . ' must not be empty');
        }
    }

    /**
     * Criterion 15: with every script removed, the shell is still a complete,
     * navigable document.
     */
    public function testTheShellRemainsUsableWithoutJavaScript(): void
    {
        foreach (['/', '/projects', '/projects/kushim', '/about', '/contact', '/nope'] as $path) {
            $body = self::get($path)['body'];

            $noJs = preg_replace('#<script\b[^>]*>.*?</script>#si', '', $body);
            self::assertIsString($noJs);
            $noJs = preg_replace('#<script\b[^>]*>#si', '', $noJs);
            self::assertIsString($noJs);

            self::assertStringNotContainsString('<noscript', $noJs, $path);
            self::assertStringNotContainsString('javascript:', $noJs, $path);

            $xpath = self::dom($noJs);

            // Skip link, header, navigation, main and footer all survive.
            $skip = self::query($xpath, '//body//a[@href="#main"]');
            self::assertSame(1, $skip->length, $path . ' must keep its skip link');

            self::assertSame(1, self::query($xpath, '//header')->length, $path);
            self::assertSame(1, self::query($xpath, '//main[@id="main"]')->length, $path);
            self::assertSame(1, self::query($xpath, '//footer')->length, $path);
            self::assertSame(1, self::query($xpath, '//header//nav[@aria-label="Primary"]')->length, $path);
            self::assertSame(4, self::query($xpath, '//header//nav//a[@href]')->length, $path);
            self::assertGreaterThan(0, self::query($xpath, '//main//h1')->length, $path);

            // Every navigation target is a real URL this server answers, not a
            // placeholder waiting for a script handler.
            foreach (self::query($xpath, '//header//nav//a') as $link) {
                $href = $link->getAttribute('href');

                self::assertMatchesRegularExpression('~^/~', $href, $path);
                self::assertSame(200, self::get($href)['status'], $href);
            }

            // No control that needs JavaScript is presented to a client
            // without it.
            foreach (self::query($xpath, '//header//button') as $button) {
                self::assertTrue($button->hasAttribute('hidden'), $path . ': an inert control was left visible');
            }
        }
    }

    /**
     * Criterion 15: the theme still resolves without JavaScript, from the
     * stylesheet the production server serves.
     */
    public function testTheServedStylesheetCarriesTheSystemThemeWithoutJavaScript(): void
    {
        $manifest = self::manifest();
        $styles = $manifest->styles('resources/js/app.ts');

        self::assertNotEmpty($styles, 'The shared layer must ship a stylesheet');

        $css = '';

        foreach ($styles as $style) {
            $css .= self::get($style)['body'];
        }

        self::assertStringContainsString('prefers-color-scheme:dark', str_replace(' ', '', $css));
        self::assertStringContainsString('data-theme', $css);
        self::assertStringContainsString(':focus-visible', $css);
    }
}
