<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use Facet\Content\CorpusLoader;
use Facet\Seo\Sitemap;
use Facet\Tests\Support\Dom;
use PHPUnit\Framework\TestCase;

/** PORT-106's focused gate over the real production HTTP entrypoint. */
final class SeoInfrastructureHttpTest extends TestCase
{
    private const ORIGIN = 'https://portfolio.example';

    /** @var resource|null */
    private static $process = null;

    /** @var array<int, resource> */
    private static array $pipes = [];

    private static ?string $host = null;

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }
    }

    private static function host(): string
    {
        if (self::$host !== null) {
            return self::$host;
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, $error ?? 'Could not reserve a port.');
        $name = stream_socket_get_name($socket, false);
        self::assertIsString($name);
        fclose($socket);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        self::$host = '127.0.0.1:' . $port;

        $process = proc_open(
            [PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', self::$host, '-t', self::root() . '/public', self::root() . '/public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            self::$pipes,
            self::root(),
            [
                'APP_NAME' => 'Facet',
                'APP_ENV' => 'production',
                'APP_KEY' => 'seo-infrastructure-key',
                'APP_URL' => self::ORIGIN,
                'APP_LOCALE' => 'fr',
                'APP_DEBUG' => 'false',
                'PATH' => getenv('PATH') ?: '/usr/bin',
            ]
        );
        self::assertIsResource($process);
        self::$process = $process;

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $port, $probeError, $probeMessage, 0.1);
            if (is_resource($probe)) {
                fclose($probe);

                return self::$host;
            }
            usleep(50_000);
        }

        self::fail('SEO infrastructure gate server did not start.');
    }

    /** @return array{status: int, headers: list<string>, body: string} */
    private static function get(string $path): array
    {
        $context = stream_context_create(['http' => [
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
            'header' => "Host: attacker.example\r\nX-Forwarded-Host: proxy-attacker.example",
        ]]);
        $body = @file_get_contents('http://' . self::host() . $path, false, $context);
        self::assertIsString($body, $path);
        /** @var list<string> $http_response_header */
        $matched = preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $matches);
        self::assertSame(1, $matched);

        return ['status' => (int) $matches[1], 'headers' => $http_response_header, 'body' => $body];
    }

    /** @param list<string> $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }

    public function testEveryJsonLdBlockParsesAndProjectClaimsMatchTheCorpus(): void
    {
        $corpus = CorpusLoader::default(self::root())->load();
        $paths = Sitemap::paths($corpus);

        foreach ($paths as $path) {
            $body = self::get($path)['body'];
            $dom = Dom::of($body);
            $blocks = Dom::query($dom, '//script[@type="application/ld+json"]');
            self::assertGreaterThanOrEqual(1, $blocks->count(), $path);

            foreach ($blocks as $block) {
                $decoded = json_decode($block->textContent, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($decoded, $path);
                self::assertSame('https://schema.org', $decoded['@context'] ?? null, $path);
                self::assertSame(self::ORIGIN . $path, $decoded['url'] ?? null, $path);
                self::assertArrayNotHasKey('image', $decoded, $path);
            }
        }

        foreach ($corpus->projects() as $project) {
            $path = '/fr/projects/' . $project->slug()->value();
            $dom = Dom::of(self::get($path)['body']);
            $block = Dom::element($dom, '//script[@type="application/ld+json"]');
            $data = json_decode($block->textContent, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            self::assertSame('CreativeWork', $data['@type'] ?? null, $path);
            self::assertSame($project->name(), $data['name'] ?? null, $path);
            self::assertSame($project->summary(), $data['description'] ?? null, $path);
            self::assertSame($project->technologies(), $data['keywords'] ?? [], $path);
            self::assertSame(
                $project->technologies() === []
                    ? ['@context', '@type', 'name', 'description', 'url']
                    : ['@context', '@type', 'name', 'description', 'url', 'keywords'],
                array_keys($data),
                $path
            );
        }
    }

    public function testSitemapContainsOnlyExpectedPagesWhichReturnAndSelfCanonicalise(): void
    {
        $response = self::get('/sitemap.xml');
        self::assertSame(200, $response['status']);
        self::assertSame('application/xml; charset=utf-8', self::header($response['headers'], 'Content-Type'));

        $xml = simplexml_load_string($response['body']);
        self::assertNotFalse($xml);
        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $locations = array_map(static fn (\SimpleXMLElement $node): string => (string) $node, $xml->xpath('//s:loc') ?: []);
        $expectedPaths = Sitemap::paths(CorpusLoader::default(self::root())->load());
        self::assertSame(array_map(static fn (string $path): string => self::ORIGIN . $path, $expectedPaths), $locations);

        foreach ($locations as $location) {
            $path = parse_url($location, PHP_URL_PATH);
            self::assertIsString($path);
            $page = self::get($path);
            self::assertSame(200, $page['status'], $path);
            $dom = Dom::of($page['body']);
            self::assertSame($location, Dom::element($dom, '//link[@rel="canonical"]')->getAttribute('href'), $path);
        }

        foreach (['/login', '/logout', '/admin', '/admin/messages', '/client', '/sitemap.xml', '/robots.txt'] as $excluded) {
            self::assertNotContains(self::ORIGIN . $excluded, $locations);
        }
    }

    public function testRobotsReferencesSitemapWithoutBlockingAssets(): void
    {
        $response = self::get('/robots.txt');
        self::assertSame(200, $response['status']);
        self::assertSame('text/plain; charset=utf-8', self::header($response['headers'], 'Content-Type'));
        self::assertSame(
            "User-agent: *\nDisallow:\nSitemap: " . self::ORIGIN . "/sitemap.xml\n",
            $response['body']
        );

        foreach (['/build', '/resources', '.css', '.js', '.woff', '/login', '/admin', '/client'] as $blocked) {
            self::assertStringNotContainsString('Disallow: ' . $blocked, $response['body']);
        }
    }

    public function testLoginPrivateRedirectsAndErrorsCarryNoindexContracts(): void
    {
        $login = self::get('/login');
        self::assertSame(200, $login['status']);
        self::assertSame('noindex, nofollow', Dom::element(
            Dom::of($login['body']),
            '//meta[@name="robots"]'
        )->getAttribute('content'));

        foreach (['/admin', '/admin/messages', '/client'] as $path) {
            $response = self::get($path);
            self::assertSame(303, $response['status'], $path);
            self::assertSame('noindex, nofollow', self::header($response['headers'], 'X-Robots-Tag'), $path);
        }

        foreach (['/not-a-page', '/admin/not-a-page', '/logout'] as $path) {
            $response = self::get($path);
            self::assertContains($response['status'], [404, 405], $path);
            self::assertSame('noindex, nofollow', self::header($response['headers'], 'X-Robots-Tag'), $path);
            self::assertSame('noindex, nofollow', Dom::element(
                Dom::of($response['body']),
                '//meta[@name="robots"]'
            )->getAttribute('content'), $path);
        }
    }

    public function testSeoEndpointsAreNotNavigationItemsAndMetadataIsServerRendered(): void
    {
        foreach (Sitemap::paths(CorpusLoader::default(self::root())->load()) as $path) {
            $body = self::get($path)['body'];
            self::assertStringContainsString('<link rel="canonical"', $body, $path);
            self::assertStringContainsString('<script type="application/ld+json">', $body, $path);
            self::assertStringNotContainsString('href="/sitemap.xml"', $body, $path);
            self::assertStringNotContainsString('href="/robots.txt"', $body, $path);
        }
    }
}
