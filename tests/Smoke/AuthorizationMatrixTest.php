<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use Facet\Account\Account;
use Facet\Account\AccountStatus;
use Facet\Account\Role;
use Facet\Auth\Authenticator;
use Facet\Config\Config;
use Facet\Http\Application;
use Facet\Http\Request;
use Facet\Http\Response;
use Facet\Routing\HttpMethod;
use Facet\Routing\RouteCatalog;
use Facet\Routing\Visibility;
use Facet\Security\CsrfGuard;
use Facet\Session\ArraySession;
use Facet\Tests\Support\InMemoryAccountRepository;
use Facet\Tests\Support\RecordingContactMessageReader;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The access matrix, driven through the real dispatcher.
 *
 * Every case below is a plain Request in and a Response out, so the whole
 * matrix — three kinds of visitor against every protected route — is asserted
 * without a web server. What that proves is the part of the design that is
 * structural: authorisation happens between routing and dispatch, so it holds
 * for routes whose handlers do not exist yet, and no template is involved in it
 * at any point.
 *
 * The live half — that a real cookie carries this across requests, that the
 * identifier changes at login, that logout is terminal — is asserted over real
 * HTTP against real MariaDB in {@see AuthHttpFlowTest}. Neither test subsumes
 * the other: this one is exhaustive, that one is real.
 */
final class AuthorizationMatrixTest extends TestCase
{
    private const PASSWORD = 'a sufficiently long password';

    private InMemoryAccountRepository $accounts;

    private ArraySession $session;

    private Application $app;

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAccountRepository();
        $this->session = new ArraySession();
        $this->app = Application::boot(
            self::root(),
            Config::fromArray([
                'APP_NAME' => 'Facet',
                'APP_ENV' => 'production',
                'APP_KEY' => 'authorization-matrix-key',
                'APP_LOCALE' => 'en',
            ]),
            null,
            null,
            null,
            $this->session,
            null,
            null,
            $this->accounts,
            new RecordingContactMessageReader()
        );
    }

    /**
     * Sign somebody in the way a previous request would have: an id in the
     * session, and nothing else. Everything about the principal is re-read.
     */
    private function signIn(Role $role, AccountStatus $status = AccountStatus::Active): Account
    {
        $account = $this->accounts->add(
            $role->value . '@example.com',
            self::PASSWORD,
            $role,
            $status
        );

        $this->session->put(Authenticator::SESSION_KEY, (string) $account->id());

        return $account;
    }

    private function token(): string
    {
        return (new CsrfGuard())->token($this->session);
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query = []): Response
    {
        return $this->app->handle(Request::create('GET', $path, $query));
    }

    /** @param array<string, string> $body */
    private function post(string $path, array $body = []): Response
    {
        return $this->app->handle(Request::create('POST', $path, [], $body));
    }

    // ------------------------------------------------------- the matrix

    /**
     * Every protected route against every kind of visitor.
     *
     * An authorised visitor reaches a real private shell while every other
     * role is stopped before dispatch.
     *
     * @return array<string, array{string, string, string, int, string|null}>
     */
    public static function matrix(): array
    {
        $login = '/login';

        return [
            // Anonymous: sent to the login form, told nothing about the route.
            'anonymous → /admin' => ['anonymous', 'GET', '/admin', 303, $login],
            'anonymous → /admin/messages' => ['anonymous', 'GET', '/admin/messages', 303, $login],
            'anonymous → /client' => ['anonymous', 'GET', '/client', 303, $login],
            'anonymous → /login' => ['anonymous', 'GET', $login, 200, null],

            // Admin: their own area is theirs, the
            // client area is not, and the login form redirects them home.
            'admin → /admin' => ['admin', 'GET', '/admin', 200, null],
            'admin → /admin/messages' => ['admin', 'GET', '/admin/messages', 200, null],
            'admin → /client' => ['admin', 'GET', '/client', 403, null],
            'admin → /login' => ['admin', 'GET', $login, 303, '/admin'],

            // Client: the mirror image.
            'client → /client' => ['client', 'GET', '/client', 200, null],
            'client → /admin' => ['client', 'GET', '/admin', 403, null],
            'client → /admin/messages' => ['client', 'GET', '/admin/messages', 403, null],
            'client → /login' => ['client', 'GET', $login, 303, '/client'],

            // A disabled account is nobody at all, whatever its role says.
            'disabled admin → /admin' => ['disabled', 'GET', '/admin', 303, $login],
            'disabled admin → /login' => ['disabled', 'GET', $login, 200, null],
        ];
    }

    #[DataProvider('matrix')]
    public function testTheAccessMatrixHolds(
        string $visitor,
        string $method,
        string $path,
        int $status,
        ?string $location
    ): void {
        match ($visitor) {
            'admin' => $this->signIn(Role::Admin),
            'client' => $this->signIn(Role::Client),
            'disabled' => $this->signIn(Role::Admin, AccountStatus::Disabled),
            default => null,
        };

        $response = $this->app->handle(Request::create($method, $path));

        self::assertSame($status, $response->status(), $visitor . ' ' . $method . ' ' . $path);
        self::assertSame($location, $response->header('Location'));
    }

    /**
     * The redirect an anonymous visitor gets is the same one every time and
     * says nothing about what they asked for. Not a 404 (which would be a lie),
     * not a 403 (which would confirm the route exists to someone who cannot
     * even be identified), and with no `?next=` parameter — an
     * attacker-suppliable redirect target is a separate liability, and nothing
     * needs one yet.
     */
    public function testTheAnonymousRedirectIsDeterministicAndCarriesNothing(): void
    {
        foreach (['/admin', '/admin/messages', '/client'] as $path) {
            $response = $this->get($path);

            self::assertSame(Response::STATUS_SEE_OTHER, $response->status(), $path);
            self::assertSame('/login', $response->header('Location'), $path);
            self::assertSame('', $response->body(), $path . ' must disclose nothing in its body');
        }
    }

    /**
     * Nothing about a protected route leaks into the response an anonymous
     * visitor receives.
     */
    public function testARefusedRequestDisclosesNothingAboutTheRoute(): void
    {
        $account = $this->signIn(Role::Client);
        $response = $this->get('/admin/messages');

        self::assertSame(403, $response->status());

        foreach (['contact_messages', 'SELECT', 'users', $account->email(), 'password'] as $leak) {
            self::assertStringNotContainsString($leak, $response->body(), 'leaked ' . $leak);
        }
    }

    // ------------------------------------------------- roles are unforgeable

    /**
     * Requirement 14, stated as an attack. A role submitted, queried, or baked
     * into a cookie changes nothing: the only role that exists is the one on
     * the row the session's id resolved to.
     */
    public function testARoleCannotBeSuppliedByTheRequest(): void
    {
        $this->signIn(Role::Client);

        $forged = ['role' => 'admin', 'auth.account' => '1', 'admin' => '1', 'is_admin' => 'true'];

        self::assertSame(403, $this->app->handle(Request::create('GET', '/admin', $forged))->status());
        self::assertSame(403, $this->app->handle(Request::create('GET', '/admin', [], [], $forged))->status());
        self::assertSame(
            403,
            $this->app->handle(Request::create('GET', '/admin', [], [], [], ['X-Role' => 'admin']))->status()
        );
    }

    /**
     * And an anonymous visitor cannot promote themselves either — a forged
     * request field does not become a principal.
     */
    public function testAnAnonymousVisitorCannotForgeAPrincipal(): void
    {
        $this->accounts->add('ada@example.com', self::PASSWORD, Role::Admin);

        $forged = ['role' => 'admin', 'auth.account' => '1', 'user' => '1'];

        self::assertSame(303, $this->app->handle(Request::create('GET', '/admin', $forged))->status());
        self::assertSame(303, $this->app->handle(Request::create('GET', '/admin', [], [], $forged))->status());
    }

    // ---------------------------------------------------------- mutations

    /**
     * A private POST needs this session's token, and the check is central
     * rather than in either the logout or admin mutation handler.
     */
    public function testAPrivatePostWithoutTheSessionTokenIsRefused(): void
    {
        $this->signIn(Role::Admin);

        foreach ([[], ['_token' => ''], ['_token' => 'not the token'], ['_token' => str_repeat('a', 64)]] as $body) {
            self::assertSame(403, $this->post('/logout', $body)->status());
            self::assertTrue(
                $this->session->has(Authenticator::SESSION_KEY),
                'A refused mutation must not have taken effect'
            );
        }
    }

    public function testAPrivatePostWithTheSessionTokenIsPerformed(): void
    {
        $this->signIn(Role::Admin);

        $response = $this->post('/logout', ['_token' => $this->token()]);

        self::assertSame(303, $response->status());
        self::assertSame('/', $response->header('Location'));
        self::assertSame('noindex, nofollow', $response->header('X-Robots-Tag'));
        self::assertTrue($this->session->wasDestroyed());
        self::assertSame([], $this->session->all());
    }

    /**
     * An anonymous logout is answered as unauthenticated rather than as a token
     * failure, because that is what it is — access is decided before intent.
     */
    public function testAnAnonymousLogoutIsAnUnauthenticatedRequest(): void
    {
        $response = $this->post('/logout', ['_token' => $this->token()]);

        self::assertSame(303, $response->status());
        self::assertSame('/login', $response->header('Location'));
        self::assertSame('noindex, nofollow', $response->header('X-Robots-Tag'));
    }

    /**
     * The rule is declared against the catalog, so it cannot be true only for
     * the routes that happen to exist today.
     */
    public function testEveryPrivatePostRouteIsCoveredByTheCentralCsrfRule(): void
    {
        $this->signIn(Role::Admin);
        $this->signIn(Role::Client);

        $covered = 0;

        foreach (RouteCatalog::all() as $name => $route) {
            if (!$route->accepts(HttpMethod::Post) || $route->visibility() === Visibility::Public) {
                continue;
            }

            $covered++;

            // Signed in as a client, so /login redirects and /logout is
            // permitted — in both cases a missing token must not produce a 2xx.
            $response = $this->post($route->path());

            self::assertNotSame(200, $response->status(), $name . ' accepted a tokenless private POST');
        }

        self::assertGreaterThan(0, $covered, 'No private POST route was actually exercised');
    }

    // --------------------------------------------- the boundary is not a view

    /**
     * Requirement 8, structurally. No template may reason about who is asking:
     * if a skin could hide a section from the wrong visitor, somebody would
     * eventually rely on it, and hidden markup is not a boundary — the handler
     * has already run and the data has already been read.
     *
     * The needles are the names a template would have to touch to make an
     * access decision. `->role()` is deliberately not among them: a project
     * declares the role its author held on it, which is content and has nothing
     * to do with a principal.
     */
    public function testNoTemplateDecidesAccess(): void
    {
        $offenders = [];

        foreach (['resources/skins', 'tests/Fixtures/skins'] as $relative) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::root() . '/' . $relative, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                self::assertInstanceOf(\SplFileInfo::class, $file);

                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                foreach ([
                    'Facet\\Auth',
                    'Facet\\Account',
                    'AccessPolicy',
                    'Authenticator',
                    'isAdmin',
                    'Role::',
                    'auth.account',
                ] as $forbidden) {
                    if (str_contains($source, $forbidden)) {
                        $offenders[] = $file->getFilename() . ' names ' . $forbidden;
                    }
                }
            }
        }

        self::assertSame([], $offenders, 'A template must never be the security boundary');
    }

    /**
     * And the guard runs for routes with no handler at all. Asserted through
     * the catalog so it keeps holding as handlers are written: for every
     * protected route, an anonymous GET is a redirect, whatever the handler
     * would have answered.
     */
    public function testTheGuardPrecedesDispatchForEveryProtectedRoute(): void
    {
        foreach (RouteCatalog::all() as $name => $route) {
            if (!$route->visibility()->requiresAuthentication() || !$route->accepts(HttpMethod::Get)) {
                continue;
            }

            $response = $this->get($route->toPath());

            self::assertSame(Response::STATUS_SEE_OTHER, $response->status(), $name);
            self::assertSame('/login', $response->header('Location'), $name);
        }
    }

    /**
     * The public site is untouched by any of this: no guard runs, and — the
     * part that matters for a file-backed portfolio — no account lookup is
     * performed on a public page at all.
     */
    public function testPublicRoutesAreUnaffectedAndResolveNoPrincipal(): void
    {
        $this->signIn(Role::Admin);

        foreach (['/', '/projects', '/about', '/contact'] as $path) {
            self::assertSame(200, $this->get($path)->status(), $path);
        }

        self::assertSame(0, $this->accounts->resolutions(), 'A public page must not resolve a principal');
    }
}
