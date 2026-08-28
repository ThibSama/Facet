<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use Facet\Content\CorpusLoader;
use Facet\Tests\Support\Dom;
use PHPUnit\Framework\TestCase;

/** PORT-105's focused gate: real HTTP and raw server-rendered source. */
final class SeoMetadataHttpTest extends TestCase
{
    private const PUBLIC_ORIGIN = 'https://portfolio.example';

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
                'APP_KEY' => 'seo-gate-key',
                'APP_URL' => self::PUBLIC_ORIGIN,
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

        self::fail('SEO gate server did not start.');
    }

    /** @return array{status: int, body: string} */
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

        return ['status' => (int) $matches[1], 'body' => $body];
    }

    /** @return array<string, array{title: string, description: string}> */
    private static function matrix(): array
    {
        $corpus = CorpusLoader::default(self::root())->load();
        $profile = $corpus->profile();
        $matrix = [
            '/' => [
                'title' => $profile->name() . ' — ' . $profile->headline(),
                'description' => $profile->summary(),
            ],
            '/projects' => [
                'title' => 'Projets — ' . $profile->name(),
                'description' => 'Les projets de ' . $profile->name() . ', présentés à partir de leurs informations vérifiées.',
            ],
            '/about' => [
                'title' => 'À propos de ' . $profile->name(),
                'description' => $profile->headline() . ' en ' . $profile->location() . '. ' . $profile->summary(),
            ],
            '/contact' => [
                'title' => 'Contacter ' . $profile->name(),
                'description' => 'Formulaire de contact de ' . $profile->name() . ' et liens publics issus de son profil.',
            ],
        ];

        foreach ($corpus->projects() as $project) {
            $matrix['/projects/' . $project->slug()->value()] = [
                'title' => $project->name() . ' — Projet de ' . $profile->name(),
                'description' => $project->summary(),
            ];
        }

        return $matrix;
    }

    public function testEveryCanonicalHasOneCompleteUniqueMetadataSetInRawHtml(): void
    {
        $titles = [];
        $descriptions = [];

        foreach (self::matrix() as $path => $expected) {
            $response = self::get($path);
            self::assertSame(200, $response['status'], $path);
            self::assertStringContainsString('<head>', $response['body'], $path);
            self::assertStringContainsString('</head>', $response['body'], $path);

            $dom = Dom::of($response['body']);
            $title = Dom::textOf(Dom::element($dom, '//head/title', $path));
            $description = Dom::element($dom, '//head/meta[@name="description"]', $path)->getAttribute('content');
            $canonical = Dom::element($dom, '//head/link[@rel="canonical"]', $path)->getAttribute('href');

            self::assertSame($expected['title'], $title, $path);
            self::assertSame($expected['description'], $description, $path);
            self::assertSame(self::PUBLIC_ORIGIN . $path, $canonical, $path);
            self::assertSame(1, preg_match('~^https://portfolio\.example(?:/[^?#]*)?$~', $canonical), $path);

            self::assertSame($title, Dom::element($dom, '//meta[@property="og:title"]')->getAttribute('content'), $path);
            self::assertSame($description, Dom::element($dom, '//meta[@property="og:description"]')->getAttribute('content'), $path);
            self::assertSame($canonical, Dom::element($dom, '//meta[@property="og:url"]')->getAttribute('content'), $path);
            self::assertContains(Dom::element($dom, '//meta[@property="og:type"]')->getAttribute('content'), ['website', 'article', 'profile'], $path);
            self::assertSame('summary', Dom::element($dom, '//meta[@name="twitter:card"]')->getAttribute('content'), $path);
            self::assertSame($title, Dom::element($dom, '//meta[@name="twitter:title"]')->getAttribute('content'), $path);
            self::assertSame($description, Dom::element($dom, '//meta[@name="twitter:description"]')->getAttribute('content'), $path);
            self::assertCount(0, Dom::query($dom, '//meta[@property="og:image" or @name="twitter:image"]'), $path);
            self::assertCount(0, Dom::query($dom, '//meta[@name="robots"]'), $path);

            $titles[] = $title;
            $descriptions[] = $description;
        }

        self::assertCount(count($titles), array_unique($titles), 'Every canonical title must be unique.');
        self::assertCount(count($descriptions), array_unique($descriptions), 'Every canonical description must be unique.');
    }

    public function testProjectDescriptionsAreExactlyCanonicalProjectClaims(): void
    {
        $corpus = CorpusLoader::default(self::root())->load();

        foreach ($corpus->projects() as $project) {
            $path = '/projects/' . $project->slug()->value();
            $dom = Dom::of(self::get($path)['body']);
            $meta = Dom::element($dom, '//meta[@name="description"]');

            self::assertSame($project->summary(), $meta->getAttribute('content'), $path);
        }
    }
}
