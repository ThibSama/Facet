<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use DOMElement;
use DOMNodeList;
use DOMXPath;
use Facet\Content\Corpus;
use Facet\Content\CorpusLoader;
use Facet\Tests\Support\Dom;
use PHPUnit\Framework\TestCase;

/**
 * The Phase 2 exit gate, proved end to end against a production server.
 *
 * Phase 1 proved the shell: a server-rendered document with fingerprinted
 * assets that degrades to plain HTML. Phase 2 claims something narrower and
 * more visible — that the *public site* is complete and coherent. Every page a
 * visitor can reach answers, every link between them resolves, every page
 * still works with the scripts removed, and nothing anywhere reads as
 * unfinished.
 *
 * The individual pages are asserted in their own tests. What is new here is
 * that they are crawled *together*, over real HTTP, in production
 * configuration, as one site rather than as a set of templates.
 */
final class Phase2GateTest extends TestCase
{
    /** @var array{host: string, process: resource, pipes: array<int, resource>}|null */
    private static ?array $server = null;

    private static ?Corpus $corpus = null;

    /** @var array<string, array{status: int, headers: list<string>, body: string}> */
    private static array $responses = [];

    private const APP_KEY = 'phase2-gate-key';

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function corpus(): Corpus
    {
        return self::$corpus ??= CorpusLoader::default(self::root())->load();
    }

    public static function tearDownAfterClass(): void
    {
        self::$responses = [];

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
     * Every public GET page the site declares: the fixed pages plus one detail
     * page per canonical project, read from the corpus so a sixth project
     * joins the crawl by existing.
     *
     * @return list<string>
     */
    private static function publicPages(): array
    {
        $paths = ['/', '/projects', '/about', '/contact'];

        foreach (self::corpus()->projects() as $project) {
            $paths[] = '/projects/' . $project->slug()->value();
        }

        return $paths;
    }

    // -------------------------------------------------------- the server

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
                'APP_KEY' => self::APP_KEY,
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
    private static function request(string $method, string $path): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => 10,
            ],
        ]);

        $body = @file_get_contents('http://' . self::host() . $path, false, $context);
        self::assertIsString($body, 'No response for ' . $method . ' ' . $path);

        /** @var list<string> $headers */
        $headers = $http_response_header;

        self::assertNotEmpty($headers, 'No status line was returned');
        self::assertSame(1, preg_match('#HTTP/\S+\s+(\d{3})#', $headers[0], $matches), $headers[0]);
        $status = $matches[1] ?? null;
        self::assertIsString($status);

        return ['status' => (int) $status, 'headers' => $headers, 'body' => $body];
    }

    /**
     * A GET, fetched once per class run — the crawl visits the same URLs
     * several times over and the server is the slow part.
     *
     * @return array{status: int, headers: list<string>, body: string}
     */
    private static function get(string $path): array
    {
        return self::$responses[$path] ??= self::request('GET', $path);
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

    /**
     * A served page as a visitor without JavaScript receives it.
     */
    private static function noJsPage(string $path): DOMXPath
    {
        return Dom::of(Dom::withoutScripts(self::get($path)['body']));
    }

    /**
     * @return DOMNodeList<DOMElement>
     */
    private static function query(DOMXPath $xpath, string $expression): DOMNodeList
    {
        return Dom::query($xpath, $expression);
    }

    // ------------------------------------------------------ criterion 14

    /**
     * Criterion 14: every public GET page the site declares answers 200 with a
     * complete HTML document.
     */
    public function testEveryPublicPageAnswers200(): void
    {
        $pages = self::publicPages();

        self::assertCount(
            4 + count(self::corpus()->projects()),
            $pages,
            'The crawl must cover the fixed pages and every canonical project'
        );

        foreach ($pages as $path) {
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
    }

    // ------------------------------------------------------ criterion 15

    /**
     * Criterion 15: an unknown project is a 404, canonical redirects are
     * deterministic, and no link the site prints is broken.
     */
    public function testUnknownProjectsAre404AndRedirectsAreDeterministic(): void
    {
        foreach (['/projects/no-such-project', '/projects/kushim-2', '/projects/Kushim'] as $path) {
            self::assertSame(404, self::request('GET', $path)['status'], $path);
        }

        foreach (['/projects/' => '/projects', '/about/' => '/about', '/contact/' => '/contact'] as $from => $to) {
            $first = self::request('GET', $from);
            $second = self::request('GET', $from);

            self::assertSame(301, $first['status'], $from);
            self::assertSame($to, self::headerValue('Location', ...$first['headers']), $from);

            // Deterministic: the same request twice gets the same answer.
            self::assertSame($first['status'], $second['status'], $from);
            self::assertSame(
                self::headerValue('Location', ...$first['headers']),
                self::headerValue('Location', ...$second['headers']),
                $from
            );

            // And the target is a page, not another redirect.
            self::assertSame(200, self::get($to)['status'], $to);
        }
    }

    /**
     * Criterion 15: every internal link printed anywhere on the public site
     * resolves to a page this server serves.
     */
    public function testNoInternalLinkIsBroken(): void
    {
        $checked = [];

        foreach (self::publicPages() as $path) {
            $xpath = self::noJsPage($path);

            foreach (self::query($xpath, '//a[@href]') as $anchor) {
                $href = $anchor->getAttribute('href');

                if ($href === '' || str_starts_with($href, '#') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) === 1) {
                    // In-page anchors and absolute outbound URLs are not this
                    // server's to answer; the outbound ones are canonical
                    // corpus links, asserted where they are rendered.
                    continue;
                }

                self::assertStringStartsWith('/', $href, $path . ' printed a relative link: ' . $href);

                if (isset($checked[$href])) {
                    continue;
                }

                $checked[$href] = true;

                self::assertSame(200, self::get($href)['status'], $href . ' is linked from ' . $path);
            }
        }

        self::assertNotEmpty($checked);

        // The crawl really did reach the whole public surface through links.
        foreach (self::publicPages() as $path) {
            self::assertArrayHasKey($path, $checked, $path . ' must be reachable by a link');
        }
    }

    // ------------------------------------------------------ criterion 16

    /**
     * Criterion 16: with every script stripped, the site is still a complete,
     * navigable document set — shell, project navigation and contact fields
     * all survive.
     */
    public function testTheWholePublicSiteSurvivesWithoutJavaScript(): void
    {
        foreach (self::publicPages() as $path) {
            $body = Dom::withoutScripts(self::get($path)['body']);

            self::assertStringNotContainsString('<noscript', $body, $path);
            self::assertStringNotContainsString('javascript:', $body, $path);

            $xpath = Dom::of($body);

            self::assertSame(1, self::query($xpath, '//body//a[@href="#main"]')->length, $path);
            self::assertSame(1, self::query($xpath, '//header//nav[@aria-label="Primary"]')->length, $path);
            self::assertSame(1, self::query($xpath, '//main[@id="main"]')->length, $path);
            self::assertSame(1, self::query($xpath, '//footer')->length, $path);
            self::assertSame(1, self::query($xpath, '//main//h1')->length, $path . ' must have exactly one H1');
            self::assertNotSame('', Dom::textOf(Dom::element($xpath, '//main//h1')), $path);
        }

        // Project navigation: the catalogue links to every canonical project,
        // and each case study links back.
        $catalogue = self::noJsPage('/projects');

        foreach (self::corpus()->projects() as $project) {
            $href = '/projects/' . $project->slug()->value();

            self::assertGreaterThan(
                0,
                self::query($catalogue, sprintf('//main//a[@href="%s"]', $href))->length,
                $href . ' must be reachable from the catalogue without JavaScript'
            );

            self::assertGreaterThan(
                0,
                self::query(self::noJsPage($href), '//main//a[@href="/projects"]')->length,
                $href . ' must link back to the catalogue without JavaScript'
            );
        }

        // Contact fields: all four, labelled, inside a form that really posts.
        $contact = self::noJsPage('/contact');
        $form = Dom::element($contact, '//main//form');

        self::assertSame('post', strtolower($form->getAttribute('method')));
        self::assertSame('/contact', $form->getAttribute('action'));

        foreach (['name', 'email', 'subject', 'message'] as $field) {
            $control = Dom::element($contact, sprintf('//main//form//*[@name="%s"]', $field), $field);
            $id = $control->getAttribute('id');

            self::assertNotSame('', $id, $field);
            Dom::element($contact, sprintf('//main//form//label[@for="%s"]', $id), $field . ' label');
        }

        self::assertSame(1, self::query($contact, '//main//form//button[@type="submit"]')->length);
    }

    // ------------------------------------------------------ criterion 17

    /**
     * Criterion 17: nothing a visitor can read on a 200 page says the site is
     * unfinished.
     */
    public function testNoPublicPageShowsPlaceholderOrDevelopmentCopy(): void
    {
        $forbidden = [
            'lorem ipsum',
            'todo',
            'fixme',
            'xxx',
            'wip',
            'coming soon',
            'under construction',
            'placeholder',
            'dummy text',
            'sample text',
            'to be defined',
            'to be completed',
            'console.log',
            'var_dump',
            'debug',
            'test test',
        ];

        foreach (self::publicPages() as $path) {
            $xpath = self::noJsPage($path);
            $visible = mb_strtolower(Dom::textOf(Dom::element($xpath, '//body')));

            foreach ($forbidden as $token) {
                self::assertStringNotContainsString($token, $visible, $path . ' shows "' . $token . '"');
            }

            self::assertNotSame('', trim($visible), $path . ' must show something');
        }
    }

    // ------------------------------------------------------ criterion 18

    /**
     * Criterion 18: production discloses nothing, on any status the public
     * surface can produce — including the one route that is declared but not
     * implemented.
     */
    public function testProductionDisclosesNothingOnAnyStatus(): void
    {
        $probes = array_merge(
            self::publicPages(),
            ['/projects/no-such-project', '/definitely-not-a-page', '/projects/', '/login']
        );

        foreach ($probes as $path) {
            $body = self::request('GET', $path)['body'];

            foreach ([
                self::root(),
                self::APP_KEY,
                'Fatal error',
                'Warning:',
                'Stack trace',
                'Exception',
                'vendor/',
                '<?php',
            ] as $leak) {
                self::assertStringNotContainsString($leak, $body, $path . ' leaked ' . $leak);
            }
        }

        // POST /contact is implemented, and this request carries no CSRF token
        // and no session — the shape a cross-site submission arrives in. It
        // must be refused with a status, not with a diagnostic.
        $posted = self::request('POST', '/contact');

        self::assertSame(403, $posted['status']);

        foreach ([self::root(), self::APP_KEY, 'Stack trace', 'Exception'] as $leak) {
            self::assertStringNotContainsString($leak, $posted['body'], 'POST /contact leaked ' . $leak);
        }
    }
}
