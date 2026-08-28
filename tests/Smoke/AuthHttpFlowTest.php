<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use Facet\Account\AccountStatus;
use Facet\Account\AdminBootstrapper;
use Facet\Account\Role;
use Facet\Database\Database;
use Facet\Database\Migration\Migrator;
use Facet\Session\PhpSession;
use Facet\Tests\Support\Dom;
use Facet\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Authentication over real HTTP, with a real session cookie and real accounts
 * in MariaDB behind it.
 *
 * Everything else in this checkpoint drives the application in process, which
 * cannot see the half of the system that only exists once a SAPI is involved —
 * and for authentication that half is the important one. Whether the session
 * identifier actually changes at login is a claim about a cookie. Whether the
 * old identifier still opens the door is a claim about what the server did with
 * a file. Whether logout is terminal is a claim about both. None of them can be
 * settled by an in-memory session, however carefully it is written.
 *
 * The server is the real entrypoint in production configuration, pointed at the
 * disposable MariaDB instance, and the accounts are created with the same
 * shell command an operator would use. Nothing here is test-only.
 */
final class AuthHttpFlowTest extends TestCase
{
    private const ADMIN = 'ada@example.com';
    private const CLIENT = 'grace@example.com';
    private const DISABLED = 'alan@example.com';
    private const PASSWORD = 'correct horse battery staple';

    private const APP_KEY = 'auth-http-flow-key';

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

        TestDatabase::reset();
        self::$database = TestDatabase::connection();
        Migrator::default(self::$database, self::root())->migrate();

        // The administrator is minted by the shell command; the other two rows
        // are written directly, because that command deliberately mints only
        // active administrators.
        (new AdminBootstrapper(self::$database))->create(self::ADMIN, self::PASSWORD);
        $this->insert(self::CLIENT, Role::Client, AccountStatus::Active);
        $this->insert(self::DISABLED, Role::Client, AccountStatus::Disabled);

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

    private function insert(string $email, Role $role, AccountStatus $status): void
    {
        self::assertInstanceOf(Database::class, self::$database);

        self::$database->execute(
            'INSERT INTO users (email, password_hash, role, status) VALUES (:email, :hash, :role, :status)',
            [
                'email' => $email,
                'hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
                'role' => $role->value,
                'status' => $status->value,
            ]
        );
    }

    // ------------------------------------------------------------ server

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
     * every 303 can be asserted where it happens.
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

            if (count($parts) !== 2) {
                continue;
            }

            // A deletion, which is what an expiring cookie is: PHP writes the
            // literal value `deleted` with `Max-Age=0`, and a browser drops the
            // cookie rather than sending either back.
            if ($parts[1] === '' || $parts[1] === 'deleted' || preg_match('/max-age=0\b/i', $header) === 1) {
                unset($this->cookies[$parts[0]]);

                continue;
            }

            $this->cookies[$parts[0]] = $parts[1];
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

    /**
     * @param list<string> $headers
     */
    private static function sessionCookieHeader(array $headers): ?string
    {
        foreach (self::headerValues('Set-Cookie', $headers) as $cookie) {
            if (stripos($cookie, PhpSession::COOKIE_NAME . '=') === 0) {
                return $cookie;
            }
        }

        return null;
    }

    private function sessionId(): ?string
    {
        return $this->cookies[PhpSession::COOKIE_NAME] ?? null;
    }

    /**
     * Load the sign-in form and read the token out of it, as a browser would.
     *
     * @return array{token: string, response: array{status: int, headers: list<string>, body: string}}
     */
    private function openLoginForm(): array
    {
        $response = $this->request('GET', '/login');

        self::assertSame(200, $response['status']);

        $token = Dom::element(
            Dom::of(Dom::withoutScripts($response['body'])),
            '//main//form//input[@name="_token"]'
        )->getAttribute('value');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

        return ['token' => $token, 'response' => $response];
    }

    /**
     * @return array{status: int, headers: list<string>, body: string}
     */
    private function signIn(string $email, string $password = self::PASSWORD): array
    {
        $form = $this->openLoginForm();

        return $this->request('POST', '/login', [
            '_token' => $form['token'],
            'email' => $email,
            'password' => $password,
        ]);
    }

    // ------------------------------------------------------- the journey

    /**
     * The fixation test, and the reason this file exists.
     *
     * A known anonymous identifier is established, a login happens over it, and
     * the identifier the browser is left holding is a different one. Then the
     * old identifier is replayed on its own: it must reach no protected state,
     * which is the property that makes the change of name worth anything.
     */
    public function testLoginRekeysTheSessionAndTheOldIdentifierIsWorthless(): void
    {
        // A known anonymous session, exactly as an attacker who planted one
        // would have.
        $this->openLoginForm();

        $anonymous = $this->sessionId();

        self::assertIsString($anonymous, 'The form must establish a session the token is tied to');
        self::assertSame(303, $this->signIn(self::ADMIN)['status']);

        $authenticated = $this->sessionId();

        self::assertIsString($authenticated);
        self::assertNotSame($anonymous, $authenticated, 'The identifier must change at login');

        // The new one works.
        self::assertSame(200, $this->request('GET', '/admin')['status'], 'The signed-in identifier reaches /admin');

        // The old one does not, and is not merely a different session that
        // happens to be empty — it reaches nothing protected at all.
        $this->cookies = [PhpSession::COOKIE_NAME => $anonymous];

        foreach (['/admin', '/admin/messages', '/client'] as $path) {
            $replayed = $this->request('GET', $path);

            self::assertSame(303, $replayed['status'], $path);
            self::assertSame('/login', self::headerValue('Location', $replayed['headers']), $path);
        }
    }

    /**
     * A valid sign-in reaches the area the stored role names.
     */
    public function testAValidSignInLandsOnTheAreaTheStoredRoleNames(): void
    {
        foreach ([[self::ADMIN, '/admin'], [self::CLIENT, '/client']] as [$email, $expected]) {
            $this->cookies = [];

            $posted = $this->signIn($email);

            self::assertSame(303, $posted['status'], $email);
            self::assertSame($expected, self::headerValue('Location', $posted['headers']), $email);

            // Following the redirect with the same cookie is permitted...
            self::assertSame(200, $this->request('GET', $expected)['status'], $email);

            // ...and the other role's area is not.
            $other = $expected === '/admin' ? '/client' : '/admin';
            self::assertSame(403, $this->request('GET', $other)['status'], $email . ' → ' . $other);
        }
    }

    public function testAdminInboxStatusAndLogoutJourneyRunsOverRealHttp(): void
    {
        self::assertSame(303, $this->signIn(self::ADMIN)['status']);
        self::assertInstanceOf(Database::class, self::$database);

        self::$database->execute(
            'INSERT INTO contact_messages (name, email, subject, message) '
                . 'VALUES (:name, :email, :subject, :message)',
            [
                'name' => '<script>hostile sender</script>',
                'email' => 'sender@example.com',
                'subject' => '<img src=x onerror=alert(1)>',
                'message' => '<script>alert(2)</script>',
            ]
        );
        $id = self::$database->lastInsertId();
        self::assertIsString($id);

        $detail = $this->request('GET', '/admin/messages?id=' . $id);
        self::assertSame(200, $detail['status']);
        self::assertStringContainsString('&lt;script&gt;alert(2)&lt;/script&gt;', $detail['body']);
        self::assertStringNotContainsString('<script>alert(2)</script>', $detail['body']);
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $detail['body']);

        $xpath = Dom::of(Dom::withoutScripts($detail['body']));
        $token = Dom::element(
            $xpath,
            '//form[@action="/admin/messages"]//input[@name="_token"]'
        )->getAttribute('value');

        $updated = $this->request('POST', '/admin/messages', [
            '_token' => $token,
            'id' => $id,
            'status' => 'archived',
        ]);
        self::assertSame(303, $updated['status']);
        self::assertSame('/admin/messages?id=' . $id, self::headerValue('Location', $updated['headers']));
        self::assertSame(
            'archived',
            self::$database->selectValue('SELECT status FROM contact_messages WHERE id = :id', ['id' => $id])
        );

        $refreshed = $this->request('GET', '/admin/messages?id=' . $id);
        self::assertSame(200, $refreshed['status']);
        self::assertSame(
            'archived',
            self::$database->selectValue('SELECT status FROM contact_messages WHERE id = :id', ['id' => $id])
        );

        $logoutToken = Dom::element(
            Dom::of(Dom::withoutScripts($refreshed['body'])),
            '//form[@action="/logout"]//input[@name="_token"]'
        )->getAttribute('value');
        self::assertSame(303, $this->request('POST', '/logout', ['_token' => $logoutToken])['status']);
        self::assertSame(303, $this->request('GET', '/admin')['status']);
    }

    public function testClientShellAndLogoutJourneyRunsOverRealHttp(): void
    {
        self::assertSame(303, $this->signIn(self::CLIENT)['status']);

        $page = $this->request('GET', '/client');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString(self::CLIENT, $page['body']);
        self::assertStringContainsString('No client feature has been delivered', $page['body']);
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $page['body']);

        $token = Dom::element(
            Dom::of(Dom::withoutScripts($page['body'])),
            '//form[@action="/logout"]//input[@name="_token"]'
        )->getAttribute('value');
        self::assertSame(303, $this->request('POST', '/logout', ['_token' => $token])['status']);
        self::assertSame(303, $this->request('GET', '/client')['status']);
    }

    public function testAdminMutationDatabaseFailureIsSafeOverRealHttp(): void
    {
        self::assertSame(303, $this->signIn(self::ADMIN)['status']);
        $page = $this->request('GET', '/admin');
        $token = Dom::element(
            Dom::of(Dom::withoutScripts($page['body'])),
            '//form[@action="/logout"]//input[@name="_token"]'
        )->getAttribute('value');

        self::assertInstanceOf(Database::class, self::$database);
        self::$database->executeTrusted('DROP TABLE contact_messages');

        $failed = $this->request('POST', '/admin/messages', [
            '_token' => $token,
            'id' => '1',
            'status' => 'read',
        ]);

        self::assertSame(500, $failed['status']);
        self::assertNull(self::headerValue('Location', $failed['headers']));
        foreach (['contact_messages', 'UPDATE', self::root(), 'SQLSTATE', 'DB_PASSWORD'] as $leak) {
            self::assertStringNotContainsString($leak, $failed['body']);
        }
    }

    /**
     * Wrong password, unknown address and disabled account, over the wire: the
     * same status, the same page, and no session in any of them.
     */
    public function testEveryRefusalIsIdenticalOverTheWireAndSignsNobodyIn(): void
    {
        $bodies = [];

        foreach ([
            'wrong password' => [self::ADMIN, 'not the password'],
            'unknown address' => ['nobody@example.com', self::PASSWORD],
            'disabled account' => [self::DISABLED, self::PASSWORD],
        ] as $label => [$email, $password]) {
            $this->cookies = [];

            $response = $this->signIn($email, $password);

            self::assertSame(422, $response['status'], $label);

            $bodies[$label] = Dom::textOf(Dom::element(
                Dom::of(Dom::withoutScripts($response['body'])),
                '//main//*[@id="login-notice"]'
            ));

            // And nothing was signed in: the protected areas are still shut.
            self::assertSame(303, $this->request('GET', '/admin')['status'], $label);
            self::assertSame(303, $this->request('GET', '/client')['status'], $label);
        }

        self::assertCount(1, array_unique(array_values($bodies)), 'Refusals must be indistinguishable');
    }

    /**
     * An account disabled between two requests loses access on the next one,
     * with nothing done to their session — the property that only exists
     * because the role and status are re-read rather than remembered.
     */
    public function testAnAccountDisabledMidSessionLosesAccessImmediately(): void
    {
        self::assertSame(303, $this->signIn(self::ADMIN)['status']);
        self::assertSame(200, $this->request('GET', '/admin')['status']);

        self::assertInstanceOf(Database::class, self::$database);
        self::$database->execute('UPDATE users SET status = :s WHERE email = :e', [
            's' => 'disabled',
            'e' => self::ADMIN,
        ]);

        $response = $this->request('GET', '/admin');

        self::assertSame(303, $response['status']);
        self::assertSame('/login', self::headerValue('Location', $response['headers']));
    }

    /**
     * And an account deleted behind a live session is nobody, not an error.
     */
    public function testAnAccountDeletedMidSessionLosesAccessImmediately(): void
    {
        self::assertSame(303, $this->signIn(self::CLIENT)['status']);
        self::assertSame(200, $this->request('GET', '/client')['status']);

        self::assertInstanceOf(Database::class, self::$database);
        self::$database->execute('DELETE FROM users WHERE email = :e', ['e' => self::CLIENT]);

        $response = $this->request('GET', '/client');

        self::assertSame(303, $response['status']);
        self::assertSame('/login', self::headerValue('Location', $response['headers']));
        self::assertStringNotContainsString('Exception', $response['body']);
    }

    /**
     * An authenticated visitor has no business at a sign-in form, and is sent
     * to their own area rather than shown one.
     */
    public function testAnAuthenticatedVisitorIsRedirectedAwayFromTheGuestForm(): void
    {
        self::assertSame(303, $this->signIn(self::CLIENT)['status']);

        $response = $this->request('GET', '/login');

        self::assertSame(303, $response['status']);
        self::assertSame('/client', self::headerValue('Location', $response['headers']));
    }

    // ----------------------------------------------------------- the token

    /**
     * A sign-in that cannot show it was composed on this site is refused before
     * any credential is considered — over the wire, from a client with no
     * session at all, and from one holding another session's token.
     */
    public function testCsrfIsEnforcedOverTheWireForLoginAndLogout(): void
    {
        // No session, no token.
        $bare = $this->request('POST', '/login', ['email' => self::ADMIN, 'password' => self::PASSWORD]);

        self::assertSame(403, $bare['status']);

        // A token from another browser.
        $form = $this->openLoginForm();
        $stolen = $form['token'];
        $this->cookies = [];

        $crossSession = $this->request('POST', '/login', [
            '_token' => $stolen,
            'email' => self::ADMIN,
            'password' => self::PASSWORD,
        ]);

        self::assertSame(403, $crossSession['status']);

        // And a logout without one leaves the session standing.
        $this->cookies = [];
        self::assertSame(303, $this->signIn(self::ADMIN)['status']);

        self::assertSame(403, $this->request('POST', '/logout', [])['status']);
        self::assertSame(200, $this->request('GET', '/admin')['status'], 'A refused logout must not log anyone out');
    }

    /**
     * The token accepted for a sign-in is rotated by it, so the exact bytes
     * that authorised one cannot authorise another.
     */
    public function testTheTokenIsRotatedByASuccessfulSignIn(): void
    {
        $form = $this->openLoginForm();

        self::assertSame(303, $this->request('POST', '/login', [
            '_token' => $form['token'],
            'email' => self::ADMIN,
            'password' => self::PASSWORD,
        ])['status']);

        // The spent token no longer authorises a private mutation.
        self::assertSame(403, $this->request('POST', '/logout', ['_token' => $form['token']])['status']);
        self::assertSame(200, $this->request('GET', '/admin')['status']);
    }

    // ---------------------------------------------------------- the logout

    /**
     * The full logout journey.
     *
     * The assertion that matters is deliberately about the *old identifier*
     * rather than about the browser's behaviour: a logout that only cleared a
     * key would leave the session live behind the same name, and replaying it
     * would still be authenticated.
     */
    public function testTheLogoutJourneyEndsTheSessionOnTheServer(): void
    {
        // A token minted while anonymous survives the sign-in's rotation only
        // as its replacement, so the logout token is read after signing in —
        // from the contact form, which every visitor can reach.
        self::assertSame(303, $this->signIn(self::ADMIN)['status']);

        $authenticated = $this->sessionId();
        self::assertIsString($authenticated);

        $token = Dom::element(
            Dom::of(Dom::withoutScripts($this->request('GET', '/contact')['body'])),
            '//main//form//input[@name="_token"]'
        )->getAttribute('value');

        $response = $this->request('POST', '/logout', ['_token' => $token]);

        self::assertSame(303, $response['status']);
        self::assertSame('/', self::headerValue('Location', $response['headers']));

        // The browser is told to drop the cookie.
        $cookie = self::sessionCookieHeader($response['headers']);
        self::assertIsString($cookie, 'A logout must expire the session cookie');
        self::assertMatchesRegularExpression(
            '/' . PhpSession::COOKIE_NAME . '=(deleted)?;/',
            $cookie,
            'The expiry cookie must carry no value'
        );

        // The jar is now empty, and the site is anonymous again.
        self::assertNull($this->sessionId());
        self::assertSame(303, $this->request('GET', '/admin')['status']);

        // And the identifier the browser was holding is worthless if replayed.
        $this->cookies = [PhpSession::COOKIE_NAME => $authenticated];

        $replayed = $this->request('GET', '/admin');

        self::assertSame(303, $replayed['status'], 'A destroyed session must not be resumable');
        self::assertSame('/login', self::headerValue('Location', $replayed['headers']));
    }

    /**
     * A GET cannot log anybody out — which is why the route is POST-only. An
     * image tag on any page on the internet can make a browser issue a GET.
     */
    public function testLogoutCannotBeTriggeredByAGet(): void
    {
        self::assertSame(303, $this->signIn(self::ADMIN)['status']);

        $response = $this->request('GET', '/logout');

        self::assertSame(405, $response['status']);
        self::assertSame('POST', self::headerValue('Allow', $response['headers']));
        self::assertSame(200, $this->request('GET', '/admin')['status'], 'Still signed in');
    }

    // ---------------------------------------------------------- the cookie

    /**
     * The session cookie's own flags, read off the wire after a sign-in.
     * `Secure` is correctly absent because this server really is plain HTTP,
     * and a cookie marked Secure here would simply be discarded.
     */
    public function testTheSessionCookieKeepsItsFlagsAcrossTheLogin(): void
    {
        $form = $this->openLoginForm();

        $anonymous = self::sessionCookieHeader($form['response']['headers']);
        self::assertIsString($anonymous);

        $posted = $this->signIn(self::ADMIN);
        $authenticated = self::sessionCookieHeader($posted['headers']);

        self::assertIsString($authenticated, 'Re-keying must re-issue the cookie');

        foreach (['anonymous' => $anonymous, 'authenticated' => $authenticated] as $why => $cookie) {
            self::assertStringContainsStringIgnoringCase('HttpOnly', $cookie, $why);
            self::assertStringContainsStringIgnoringCase('SameSite=Lax', $cookie, $why);
            self::assertStringContainsStringIgnoringCase('Path=/', $cookie, $why);
            self::assertStringNotContainsStringIgnoringCase(
                'Secure',
                $cookie,
                $why . ': over plain HTTP a Secure cookie is dropped'
            );
        }
    }

    // ------------------------------------------------------- disclosure

    /**
     * Every status this journey can produce, checked for disclosure. An
     * authentication system that defends itself and then prints a stack trace
     * has defended nothing.
     */
    public function testNoStatusOnThisJourneyDisclosesAnything(): void
    {
        $form = $this->openLoginForm();

        $responses = [
            'form' => $form['response'],
            'refused token' => $this->request('POST', '/login', ['email' => self::ADMIN]),
            'refused credentials' => $this->signIn(self::ADMIN, 'wrong'),
            'anonymous admin' => $this->request('GET', '/admin'),
            'accepted' => $this->signIn(self::ADMIN),
            'forbidden' => $this->request('GET', '/client'),
        ];

        foreach ($responses as $why => $response) {
            foreach ([
                self::root(),
                self::APP_KEY,
                self::PASSWORD,
                'facet_test',
                'DB_PASSWORD',
                'password_hash',
                '$2y$',
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
}
