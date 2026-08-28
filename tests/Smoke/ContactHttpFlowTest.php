<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use Facet\Contact\ContactValidator;
use Facet\Database\Database;
use Facet\Database\Migration\Migrator;
use Facet\Security\RateLimiter;
use Facet\Session\PhpSession;
use Facet\Tests\Support\Dom;
use Facet\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The contact form over real HTTP, with a real session cookie and a real
 * database behind it.
 *
 * Everything else in this checkpoint drives the application in process, which
 * cannot see the half of the system that only exists once a SAPI is involved:
 * whether the session cookie is actually sent, whether the browser's next
 * request is recognised as the same visitor, whether a 303 really produces a
 * GET, and whether the flags on the cookie are the ones the adapter asked for.
 * A CSRF token is worth nothing if the session it is tied to does not survive
 * the round trip, and only this test can prove that it does.
 *
 * The server is the real entrypoint in production configuration, pointed at the
 * disposable MariaDB instance. Every claim below is checked against a row count
 * read straight out of that database — not against what the page said.
 */
final class ContactHttpFlowTest extends TestCase
{
    private const VALID = [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'subject' => 'About the analytical engine',
        'message' => 'I would like to discuss a collaboration.',
    ];

    private const APP_KEY = 'contact-http-flow-key';

    /** @var array{host: string, process: resource, pipes: array<int, resource>}|null */
    private static ?array $server = null;

    private static ?Database $database = null;

    /** @var array<string, string> the browser's cookie jar */
    private array $cookies = [];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function setUp(): void
    {
        if (!TestDatabase::isConfigured()) {
            self::markTestSkipped(TestDatabase::unavailableReason());
        }

        // A fresh schema per test, so every row count below starts from zero
        // and means what it says.
        TestDatabase::reset();
        self::$database = TestDatabase::connection();
        Migrator::default(self::$database, self::root())->migrate();

        $this->cookies = [];
    }

    protected function tearDown(): void
    {
        if (TestDatabase::isConfigured()) {
            TestDatabase::reset();
        }

        self::$database = null;
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

    // ------------------------------------------------------------ server

    /**
     * One production server for the class, wired to the disposable database
     * through the ordinary `DB_*` keys — the same configuration a deployment
     * would use, so nothing about this journey is test-only.
     */
    private static function host(): string
    {
        if (self::$server !== null) {
            return self::$server['host'];
        }

        $credentials = TestDatabase::credentials();
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
                'DB_DSN' => $credentials->dsn(),
                'DB_USERNAME' => $credentials->username(),
                'DB_PASSWORD' => $credentials->password(),
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

    // ------------------------------------------------------------ client

    /**
     * A browser, reduced to the two behaviours this journey needs: it keeps
     * cookies between requests and it does not follow redirects on its own, so
     * the 303 itself can be asserted.
     *
     * @param array<string, string>|null $form
     *
     * @return array{status: int, headers: list<string>, body: string}
     */
    private function request(string $method, string $path, ?array $form = null): array
    {
        $headers = [];

        if ($this->cookies !== []) {
            $pairs = [];

            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }

            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }

        $content = '';

        if ($form !== null) {
            $content = http_build_query($form);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        // The built-in server blocks on a POST with no declared length.
        $headers[] = 'Content-Length: ' . strlen($content);

        $body = @file_get_contents('http://' . self::host() . $path, false, stream_context_create([
            'http' => [
                'method' => $method,
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => 10,
                'header' => implode("\r\n", $headers),
                'content' => $content,
            ],
        ]));

        self::assertIsString($body, sprintf('No response for %s %s', $method, $path));

        /** @var list<string> $responseHeaders */
        $responseHeaders = $http_response_header;
        self::assertNotEmpty($responseHeaders);

        $this->absorbCookies($responseHeaders);

        self::assertSame(1, preg_match('#HTTP/\S+\s+(\d{3})#', $responseHeaders[0], $matches), $responseHeaders[0]);

        $status = $matches[1] ?? '';
        self::assertNotSame('', $status);

        return ['status' => (int) $status, 'headers' => $responseHeaders, 'body' => $body];
    }

    /**
     * @param list<string> $headers
     */
    private function absorbCookies(array $headers): void
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Set-Cookie:') !== 0) {
                continue;
            }

            $pair = trim(explode(';', substr($header, 11), 2)[0]);
            $parts = explode('=', $pair, 2);

            if (count($parts) === 2 && $parts[1] !== '') {
                $this->cookies[$parts[0]] = $parts[1];
            }
        }
    }

    /**
     * @param list<string> $headers
     *
     * @return list<string>
     */
    private static function headerValues(string $name, array $headers): array
    {
        $values = [];

        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                $values[] = trim(substr($header, strlen($name) + 1));
            }
        }

        return $values;
    }

    /**
     * @param list<string> $headers
     */
    private static function headerValue(string $name, array $headers): ?string
    {
        return self::headerValues($name, $headers)[0] ?? null;
    }

    // ------------------------------------------------------------ table

    private function rowCount(): int
    {
        self::assertInstanceOf(Database::class, self::$database);

        return (int) self::$database->selectValue('SELECT COUNT(*) FROM contact_messages');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        self::assertInstanceOf(Database::class, self::$database);

        return self::$database->select(
            'SELECT id, name, email, subject, message, status, created_at FROM contact_messages ORDER BY id'
        );
    }

    /**
     * Load the form and read the token out of it, exactly as a browser would.
     *
     * @return array{token: string, response: array{status: int, headers: list<string>, body: string}}
     */
    private function openForm(): array
    {
        $response = $this->request('GET', '/contact');

        self::assertSame(200, $response['status']);

        $token = Dom::element(
            Dom::of(Dom::withoutScripts($response['body'])),
            '//main//form//input[@name="_token"]'
        )->getAttribute('value');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

        return ['token' => $token, 'response' => $response];
    }

    // ------------------------------------------------------ the journey

    /**
     * GET, POST, 303, GET — with one cookie carrying the session across all
     * four, and exactly one row at the end of it.
     */
    public function testTheFullJourneyStoresOneRowAndConfirmsOnTheRedirectedPage(): void
    {
        self::assertSame(0, $this->rowCount());

        $form = $this->openForm();

        self::assertArrayHasKey(
            PhpSession::COOKIE_NAME,
            $this->cookies,
            'The GET must establish the session the token is tied to'
        );
        self::assertSame(0, $this->rowCount(), 'Rendering the form writes nothing');

        $posted = $this->request('POST', '/contact', ['_token' => $form['token']] + self::VALID);

        self::assertSame(303, $posted['status'], 'A success is a See Other, so the next request is a GET');
        self::assertSame('/contact', self::headerValue('Location', $posted['headers']));
        self::assertSame('', trim($posted['body']));
        self::assertSame(1, $this->rowCount());

        // The browser follows the redirect with the same cookie.
        $landing = $this->request('GET', '/contact');

        self::assertSame(200, $landing['status']);
        self::assertSame(1, $this->rowCount(), 'Following the redirect stores nothing more');

        $notice = Dom::element(Dom::of(Dom::withoutScripts($landing['body'])), '//main//*[@id="contact-notice"]');

        self::assertStringContainsString('has been received', Dom::textOf($notice));

        // The stored row is the message that was typed.
        $row = $this->rows()[0];

        self::assertSame(self::VALID['name'], $row['name']);
        self::assertSame(self::VALID['email'], $row['email']);
        self::assertSame(self::VALID['subject'], $row['subject']);
        self::assertSame(self::VALID['message'], $row['message']);
        self::assertSame('new', $row['status']);
    }

    /**
     * The property PRG exists for, over the wire: reloading the page the
     * browser landed on cannot repost, and the confirmation is shown once.
     */
    public function testRefreshingTheLandingPageCannotInsertAgain(): void
    {
        $form = $this->openForm();

        $this->request('POST', '/contact', ['_token' => $form['token']] + self::VALID);
        $this->request('GET', '/contact');

        for ($i = 0; $i < 5; $i++) {
            $refresh = $this->request('GET', '/contact');

            self::assertSame(200, $refresh['status']);
            self::assertStringNotContainsString('has been received', $refresh['body'], 'Refresh ' . $i);
        }

        self::assertSame(1, $this->rowCount(), 'No reload may add a row');
    }

    /**
     * The session cookie's own flags, read off the wire. `HttpOnly` and
     * `SameSite=Lax` are the two that matter for this form; `Secure` is
     * correctly absent because this server really is plain HTTP, and a cookie
     * marked Secure here would simply be discarded.
     */
    public function testTheSessionCookieCarriesTheFlagsTheAdapterAsked(): void
    {
        $response = $this->request('GET', '/contact');

        $setCookies = self::headerValues('Set-Cookie', $response['headers']);

        self::assertNotEmpty($setCookies, 'The session must be established with a cookie');

        $session = null;

        foreach ($setCookies as $cookie) {
            if (stripos($cookie, PhpSession::COOKIE_NAME . '=') === 0) {
                $session = $cookie;
            }
        }

        self::assertIsString($session, 'The session cookie must use the configured name');

        self::assertStringContainsStringIgnoringCase('HttpOnly', $session);
        self::assertStringContainsStringIgnoringCase('SameSite=Lax', $session);
        self::assertStringContainsStringIgnoringCase('Path=/', $session);
        self::assertStringNotContainsStringIgnoringCase('Secure', $session, 'Over plain HTTP a Secure cookie is dropped');
    }

    /**
     * A submission from a client with no session at all — a bare `curl`, or a
     * form posted from somewhere else — is refused, over the wire, with
     * nothing stored.
     */
    public function testASubmissionWithNoSessionOrTokenIsRefusedOverTheWire(): void
    {
        $response = $this->request('POST', '/contact', self::VALID);

        self::assertSame(403, $response['status']);
        self::assertSame(0, $this->rowCount());
        self::assertStringNotContainsString('has been received', $response['body']);
    }

    /**
     * A token is worth nothing outside the session it was issued to. This is
     * the case that only a cookie-aware client can produce: one browser opens
     * the form, another posts its token.
     */
    public function testATokenFromAnotherSessionIsRefused(): void
    {
        $form = $this->openForm();

        // A second "browser": same server, no cookie jar.
        $this->cookies = [];

        $response = $this->request('POST', '/contact', ['_token' => $form['token']] + self::VALID);

        self::assertSame(403, $response['status']);
        self::assertSame(0, $this->rowCount());
    }

    /**
     * And the same token replayed inside its own session, after it has already
     * been spent.
     */
    public function testAnAcceptedTokenIsRotatedAndCannotBeReplayedOverTheWire(): void
    {
        $form = $this->openForm();

        self::assertSame(303, $this->request('POST', '/contact', ['_token' => $form['token']] + self::VALID)['status']);
        self::assertSame(1, $this->rowCount());

        $replay = $this->request('POST', '/contact', ['_token' => $form['token']] + self::VALID);

        self::assertSame(403, $replay['status']);
        self::assertSame(1, $this->rowCount());

        // The form now serves a different token, and that one works.
        $next = $this->openForm();

        self::assertNotSame($form['token'], $next['token']);
        self::assertSame(303, $this->request('POST', '/contact', ['_token' => $next['token']] + self::VALID)['status']);
        self::assertSame(2, $this->rowCount());
    }

    /**
     * Invalid input over the wire: 422, the form comes back filled in and
     * still submittable, and the table is untouched.
     */
    public function testInvalidInputIsRedisplayedOverTheWireAndStoresNothing(): void
    {
        $form = $this->openForm();

        $response = $this->request('POST', '/contact', [
            '_token' => $form['token'],
            'name' => 'Ada Lovelace',
            'email' => 'not-an-address',
            'subject' => str_repeat('s', ContactValidator::SUBJECT_MAX + 1),
            'message' => '',
        ]);

        self::assertSame(422, $response['status']);
        self::assertSame(0, $this->rowCount());

        $xpath = Dom::of(Dom::withoutScripts($response['body']));

        foreach (['email', 'subject', 'message'] as $field) {
            self::assertNotSame(
                '',
                Dom::textOf(Dom::element($xpath, sprintf('//main//*[@id="contact-%s-error"]', $field))),
                $field
            );
        }

        self::assertSame(
            'not-an-address',
            Dom::element($xpath, '//main//form//input[@name="email"]')->getAttribute('value')
        );

        // The correction goes through with the token the rejected page carried.
        $token = Dom::element($xpath, '//main//form//input[@name="_token"]')->getAttribute('value');

        self::assertSame(303, $this->request('POST', '/contact', ['_token' => $token] + self::VALID)['status']);
        self::assertSame(1, $this->rowCount());
    }

    /**
     * A filled honeypot, over the wire: answered exactly as a success is, and
     * stored not at all.
     */
    public function testAFilledHoneypotIsIndistinguishableFromSuccessAndStoresNothing(): void
    {
        $form = $this->openForm();

        $trapped = $this->request('POST', '/contact', [
            '_token' => $form['token'],
            'website' => 'http://spam.example',
        ] + self::VALID);

        self::assertSame(303, $trapped['status']);
        self::assertSame('/contact', self::headerValue('Location', $trapped['headers']));
        self::assertSame(0, $this->rowCount(), 'A trapped submission must never reach the table');

        // Even the page it lands on says the same thing.
        self::assertStringContainsString('has been received', $this->request('GET', '/contact')['body']);
        self::assertSame(0, $this->rowCount());
    }

    /**
     * The throttle, over the wire and counted in rows.
     */
    public function testTheThrottleBoundsRowsOverTheWire(): void
    {
        $statuses = [];

        for ($i = 0; $i < RateLimiter::DEFAULT_LIMIT + 3; $i++) {
            $form = $this->openForm();

            $statuses[] = $this->request('POST', '/contact', ['_token' => $form['token']] + self::VALID)['status'];
        }

        self::assertSame(
            RateLimiter::DEFAULT_LIMIT,
            $this->rowCount(),
            'The allowance bounds rows, not just responses'
        );

        self::assertSame(429, $statuses[RateLimiter::DEFAULT_LIMIT], 'The first refusal says why');
        self::assertSame([429, 429, 429], array_slice($statuses, RateLimiter::DEFAULT_LIMIT), 'Deterministically');
    }

    /**
     * A throttled visitor is not a broken site: the rest of the page still
     * works, and a different visitor is unaffected.
     */
    public function testThrottlingIsScopedToTheVisitorItThrottled(): void
    {
        for ($i = 0; $i < RateLimiter::DEFAULT_LIMIT + 1; $i++) {
            $form = $this->openForm();
            $this->request('POST', '/contact', ['_token' => $form['token']] + self::VALID);
        }

        self::assertSame(RateLimiter::DEFAULT_LIMIT, $this->rowCount());

        // A second browser.
        $this->cookies = [];
        $form = $this->openForm();

        self::assertSame(303, $this->request('POST', '/contact', ['_token' => $form['token']] + self::VALID)['status']);
        self::assertSame(RateLimiter::DEFAULT_LIMIT + 1, $this->rowCount());
    }

    // ------------------------------------------------------- disclosure

    /**
     * Every status this journey can produce, checked for disclosure. A form
     * that defends itself and then prints a stack trace has defended nothing.
     */
    public function testNoStatusOnThisJourneyDisclosesAnything(): void
    {
        $form = $this->openForm();

        $responses = [
            'form' => $form['response'],
            'refused' => $this->request('POST', '/contact', self::VALID),
            'invalid' => $this->request('POST', '/contact', ['_token' => $form['token'], 'email' => 'x'] + self::VALID),
            'accepted' => $this->request('POST', '/contact', ['_token' => $this->openForm()['token']] + self::VALID),
            'landing' => $this->request('GET', '/contact'),
        ];

        foreach ($responses as $why => $response) {
            foreach ([
                self::root(),
                self::APP_KEY,
                'facet_test',
                'DB_PASSWORD',
                'SQLSTATE',
                'PDO',
                'Fatal error',
                'Warning:',
                'Stack trace',
                'Exception',
                '<?php',
            ] as $leak) {
                self::assertStringNotContainsString($leak, $response['body'], $why . ' leaked ' . $leak);
            }
        }
    }

    /**
     * With the scripts stripped, the form the server sends is still complete
     * and still submittable — the token included, since it is markup rather
     * than something a script attaches.
     */
    public function testTheScriptStrippedFormIsStillFullyUsable(): void
    {
        $noJs = Dom::withoutScripts($this->request('GET', '/contact')['body']);

        self::assertStringNotContainsString('<noscript', $noJs);
        self::assertStringNotContainsString('javascript:', $noJs);

        $xpath = Dom::of($noJs);
        $formElement = Dom::element($xpath, '//main//form');

        self::assertSame('post', strtolower($formElement->getAttribute('method')));
        self::assertSame('/contact', $formElement->getAttribute('action'));

        foreach (ContactValidator::FIELDS as $field) {
            Dom::element($xpath, sprintf('//main//form//*[@name="%s"]', $field), $field);
        }

        $token = Dom::element($xpath, '//main//form//input[@name="_token"]')->getAttribute('value');
        self::assertNotSame('', $token, 'The token must survive script stripping');

        Dom::element($xpath, '//main//form//button[@type="submit"]');

        // And a submission built only from what that document contains works.
        self::assertSame(303, $this->request('POST', '/contact', ['_token' => $token] + self::VALID)['status']);
        self::assertSame(1, $this->rowCount());
    }
}
