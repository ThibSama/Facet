<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use Facet\Tests\Fixtures\ExplodingSkin;
use PHPUnit\Framework\TestCase;

/**
 * The end-to-end case the in-process dispatch tests stand in for: a real PHP
 * server, real HTTP requests, real status lines and real headers.
 *
 * In-process assertions can be defeated by an entrypoint that never calls the
 * application the way the server does. Booting `php -S` against public/ is what
 * proves the adapter, the router, the renderer and the error pages agree once
 * the SAPI is involved.
 */
final class HttpServerSmokeTest extends TestCase
{
    /**
     * One live server per configuration, started on demand and shared by every
     * test in the class.
     *
     * @var array<string, array{host: string, process: resource, pipes: array<int, resource>}>
     */
    private static array $servers = [];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servers as $server) {
            foreach ($server['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            proc_terminate($server['process']);
            proc_close($server['process']);
        }

        self::$servers = [];
    }

    /**
     * Boots one server for the whole class, in production mode: the strictest
     * disclosure configuration is the one worth smoke-testing.
     */
    /**
     * The production instance of the real entrypoint — the strictest
     * disclosure configuration, and so the one worth smoke-testing by default.
     */
    private static function host(): string
    {
        return self::server('production', 'public/index.php', 'production', false);
    }

    /**
     * Boots one server per named configuration and returns its host:port.
     */
    private static function server(string $name, string $router, string $environment, bool $debug): string
    {
        if (isset(self::$servers[$name])) {
            return self::$servers[$name]['host'];
        }

        $port = self::freePort();
        $host = '127.0.0.1:' . $port;

        // The router script matters: without it the built-in server answers
        // static 404s for /projects/{slug} and the application is never asked.
        $process = proc_open(
            [
                PHP_BINARY,
                // The server must see the environment array as $_ENV, which is
                // where Config reads non-sensitive values from.
                '-d',
                'variables_order=EGPCS',
                '-S',
                $host,
                '-t',
                self::root() . '/public',
                self::root() . '/' . $router,
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::root(),
            [
                'APP_NAME' => 'Facet',
                'APP_ENV' => $environment,
                'APP_KEY' => 'smoke-key',
                'APP_LOCALE' => 'en',
                'APP_DEBUG' => $debug ? 'true' : 'false',
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
                self::$servers[$name] = ['host' => $host, 'process' => $process, 'pipes' => $pipes];

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

        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        self::assertGreaterThan(0, $port);

        return $port;
    }

    /**
     * @return array{status: int, headers: list<string>, body: string}
     */
    private static function request(string $method, string $path, ?string $host = null): array
    {
        $host ??= self::host();

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => 10,
                // An explicit empty body: the built-in server blocks waiting
                // for one when a POST arrives without a length.
                'header' => "Content-Length: 0\r\nContent-Type: application/x-www-form-urlencoded",
                'content' => '',
            ],
        ]);

        $body = @file_get_contents('http://' . $host . $path, false, $context);
        self::assertIsString($body, sprintf('No response for %s %s', $method, $path));

        // Populated by the stream wrapper in this scope once the request ran.
        /** @var list<string> $headers */
        $headers = $http_response_header;
        self::assertNotEmpty($headers);

        $matched = preg_match('#HTTP/\S+\s+(\d{3})#', $headers[0], $matches);
        $status = $matched === 1 ? (int) ($matches[1] ?? '0') : 0;
        self::assertGreaterThan(0, $status, 'Unparseable status line: ' . $headers[0]);

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
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

    public function testGetHomeIsServedAsHtml(): void
    {
        $response = self::request('GET', '/');

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('<!doctype html>', $response['body']);
        self::assertStringContainsString('</html>', $response['body']);
        self::assertSame(
            'text/html; charset=utf-8',
            self::headerValue('Content-Type', ...$response['headers'])
        );
    }

    public function testAnUnknownPathIsA404(): void
    {
        $response = self::request('GET', '/definitely-not-a-page');

        self::assertSame(404, $response['status']);
        self::assertStringContainsString('<!doctype html>', $response['body']);
        self::assertStringContainsString('Page not found', $response['body']);
    }

    public function testAnUnsupportedMethodIsA405WithAllow(): void
    {
        $response = self::request('POST', '/');

        self::assertSame(405, $response['status']);
        self::assertSame('GET', self::headerValue('Allow', ...$response['headers']));
    }

    public function testAValidProjectSlugIsRouted(): void
    {
        $response = self::request('GET', '/projects/kushim');

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('<!doctype html>', $response['body']);
    }

    public function testAMalformedSlugIsRejected(): void
    {
        foreach (['/projects/Kushim', '/projects/ku--shim', '/projects/a'] as $path) {
            self::assertSame(404, self::request('GET', $path)['status'], $path);
        }
    }

    public function testANonCanonicalPathRedirects(): void
    {
        $response = self::request('GET', '/projects/');

        self::assertSame(301, $response['status']);
        self::assertSame('/projects', self::headerValue('Location', ...$response['headers']));
    }

    /**
     * Served pages must contain no filesystem path, no PHP diagnostic and no
     * configured secret, whatever the status.
     */
    public function testNoServedPageLeaksPathsOrDiagnostics(): void
    {
        foreach (['/', '/projects', '/projects/kushim', '/about', '/contact', '/nope'] as $path) {
            $body = self::request('GET', $path)['body'];

            self::assertStringNotContainsString(self::root(), $body, $path);
            self::assertStringNotContainsString('Fatal error', $body, $path);
            self::assertStringNotContainsString('Warning:', $body, $path);
            self::assertStringNotContainsString('Stack trace', $body, $path);
            self::assertStringNotContainsString('smoke-key', $body, $path);
        }
    }

    /**
     * Criterion 14: the public surface is complete without JavaScript. Scripts
     * may be referenced, but nothing a visitor needs may depend on them.
     */
    public function testEveryPublicPageIsUsableWithoutJavaScript(): void
    {
        foreach (['/', '/projects', '/projects/kushim', '/about', '/contact'] as $path) {
            $body = self::request('GET', $path)['body'];

            // Strip script elements entirely: what remains is what a client
            // without JavaScript sees.
            $withoutScripts = preg_replace('#<script\b[^>]*>.*?</script>#si', '', $body);
            self::assertIsString($withoutScripts);
            $withoutScripts = preg_replace('#<script\b[^>]*>#si', '', $withoutScripts);
            self::assertIsString($withoutScripts);

            self::assertStringContainsString('<h1', $withoutScripts, $path);
            self::assertStringContainsString('<main', $withoutScripts, $path);
            self::assertStringNotContainsString('<noscript', $withoutScripts, $path);
        }

        // The form posts to a real URL with a real method rather than relying
        // on a script handler.
        $contact = self::request('GET', '/contact')['body'];
        self::assertStringContainsString('method="post"', $contact);
        self::assertStringContainsString('action="/contact"', $contact);
    }

    /**
     * Failure injection over real HTTP: the fixture entrypoint boots a skin
     * whose page template throws an exception carrying a fake credential and a
     * fake absolute path. A production server must answer 500 and disclose
     * neither, through the SAPI as well as in process.
     */
    public function testAProductionFiveHundredDisclosesNothing(): void
    {
        $host = self::server('exploding-production', 'tests/Fixtures/server/exploding.php', 'production', false);
        $response = self::request('GET', '/', $host);

        self::assertSame(500, $response['status']);
        self::assertStringContainsString('<!doctype html>', $response['body']);
        self::assertStringContainsString('</html>', $response['body']);

        self::assertStringNotContainsString(ExplodingSkin::FAKE_SECRET, $response['body']);
        self::assertStringNotContainsString(ExplodingSkin::FAKE_PATH, $response['body']);
        self::assertStringNotContainsString('RuntimeException', $response['body']);
        self::assertStringNotContainsString('Stack trace', $response['body']);
        self::assertStringNotContainsString('Fatal error', $response['body']);
        self::assertStringNotContainsString(self::root(), $response['body']);
        self::assertStringNotContainsString('smoke-key', $response['body']);
    }

    /**
     * The same injected failure in local + debug may show bounded context —
     * and must still not print a full filesystem path.
     */
    public function testLocalDebugShowsBoundedContextForTheSameFailure(): void
    {
        $host = self::server('exploding-debug', 'tests/Fixtures/server/exploding.php', 'local', true);
        $response = self::request('GET', '/', $host);

        self::assertSame(500, $response['status']);
        self::assertStringContainsString('RuntimeException', $response['body']);
        self::assertStringContainsString('Database connection failed', $response['body']);
        self::assertStringNotContainsString(self::root(), $response['body']);
    }
}
