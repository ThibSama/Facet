<?php

declare(strict_types=1);

namespace Facet\Http;

use Facet\Account\AccountRepository;
use Facet\Account\AccountRepositoryFactory;
use Facet\Asset\AssetBundle;
use Facet\Asset\AssetManager;
use Facet\Config\Config;
use Facet\Contact\ContactMessageStore;
use Facet\Contact\ContactMessageStoreFactory;
use Facet\Contact\ContactInboxException;
use Facet\Contact\ContactMessageReader;
use Facet\Contact\ContactMessageReaderFactory;
use Facet\Contact\ContactMessageMutationException;
use Facet\Contact\ContactMessageStatus;
use Facet\Contact\ContactMessageStatusUpdater;
use Facet\Contact\ContactMessageStatusUpdaterFactory;
use Facet\Contact\ContactStoreException;
use Facet\Contact\ContactValidator;
use Facet\Content\Corpus;
use Facet\Content\CorpusLoader;
use Facet\Content\Project;
use Facet\Auth\AccessPolicy;
use Facet\Auth\AuthService;
use Facet\Auth\Authenticator;
use Facet\Navigation\Navigation;
use Facet\Routing\HttpMethod;
use Facet\Routing\RouteCatalog;
use Facet\Routing\RouteDefinition;
use Facet\Security\CsrfGuard;
use Facet\Security\RateLimiter;
use Facet\Session\ArraySession;
use Facet\Session\Session;
use Facet\Skin\Selection\DefaultSkinSelectionPolicy;
use Facet\Skin\Selection\SkinSelection;
use Facet\Skin\Selection\SkinSelectionContext;
use Facet\Skin\Selection\SkinSelectionPolicy;
use Facet\Skin\SkinDefinition;
use Facet\Skin\SkinRegistry;
use Facet\Skin\SkinRenderer;
use Facet\Support\Clock;
use Facet\Support\Slug;
use Facet\Support\SystemClock;
use Throwable;

/**
 * The HTTP application: a Request goes in, a Response comes out.
 *
 * It reads no superglobal, sends no header of its own and echoes nothing —
 * which is what allows every dispatch rule below, including the error paths, to
 * be exercised in a plain unit test. Its one effect on the world outside the
 * Response is the session, and that reaches the SAPI only through the
 * {@see Session} seam: an application booted with an {@see ArraySession} — as
 * every in-process test boots one — performs no side effect at all.
 *
 * The class knows about routes, assets, skins and content, and about none of
 * their internals: it asks the router which route a request reached, a route
 * for its logical view, a policy for a skin, and the asset layer for that
 * skin's URLs. It never names a template file.
 *
 * Since PORT-93 it also asks {@see AccessGuard} whether a request may proceed,
 * once, between routing and dispatch. No handler below re-checks who is asking
 * and no template ever decides: authorisation is a property of the route
 * contract, applied in one place.
 */
final class Application
{
    private string $basePath;

    private Config $config;

    private SkinRegistry $registry;

    private SkinSelectionPolicy $policy;

    private AssetManager $assets;

    private SkinRenderer $renderer;

    private Router $router;

    private ErrorPresenter $errors;

    private Session $session;

    private ContactMessageStore $messages;

    private ContactMessageReader $messageReader;

    private ContactMessageStatusUpdater $messageUpdater;

    private ContactValidator $validator;

    private CsrfGuard $csrf;

    private RateLimiter $limiter;

    private Authenticator $authenticator;

    private AuthService $auth;

    private AccessGuard $guard;

    private ?Corpus $corpus = null;

    private function __construct(
        string $basePath,
        Config $config,
        SkinRegistry $registry,
        SkinSelectionPolicy $policy,
        AssetManager $assets,
        SkinRenderer $renderer,
        Router $router,
        ErrorPresenter $errors,
        Session $session,
        ContactMessageStore $messages,
        RateLimiter $limiter,
        AccountRepository $accounts,
        ContactMessageReader $messageReader,
        ContactMessageStatusUpdater $messageUpdater
    ) {
        $this->basePath = $basePath;
        $this->config = $config;
        $this->registry = $registry;
        $this->policy = $policy;
        $this->assets = $assets;
        $this->renderer = $renderer;
        $this->router = $router;
        $this->errors = $errors;
        $this->session = $session;
        $this->messages = $messages;
        $this->messageReader = $messageReader;
        $this->messageUpdater = $messageUpdater;
        $this->limiter = $limiter;
        $this->validator = new ContactValidator();
        $this->csrf = new CsrfGuard();
        $this->authenticator = new Authenticator($accounts, $session, $this->csrf);
        $this->auth = new AuthService($accounts);
        $this->guard = new AccessGuard($this->authenticator, $session, $this->csrf);
    }

    /**
     * The session, the message store and the clock are parameters for the same
     * reason the Request's arrays are: a component that reaches for a global —
     * `$_SESSION`, a PDO connection, `time()` — cannot be exercised for the
     * cases that matter. Every default is the safe one, so an entrypoint that
     * passes none of them gets an application that renders every public page
     * and refuses every submission rather than one that accepts submissions on
     * an absent guard.
     */
    public static function boot(
        string $basePath,
        ?Config $config = null,
        ?SkinRegistry $registry = null,
        ?SkinSelectionPolicy $policy = null,
        ?Router $router = null,
        ?Session $session = null,
        ?ContactMessageStore $messages = null,
        ?Clock $clock = null,
        ?AccountRepository $accounts = null,
        ?ContactMessageReader $messageReader = null,
        ?ContactMessageStatusUpdater $messageUpdater = null
    ): self {
        $basePath = rtrim(str_replace('\\', '/', $basePath), '/');
        $config ??= Config::fromEnvironment($basePath);
        $registry ??= SkinRegistry::default();
        $renderer = SkinRenderer::forBasePath($basePath);

        return new self(
            $basePath,
            $config,
            $registry,
            $policy ?? new DefaultSkinSelectionPolicy(),
            AssetManager::fromConfig($config, self::manifestPath($basePath)),
            $renderer,
            $router ?? Router::fromCatalog(),
            new ErrorPresenter($renderer, $config->isDebug()),
            // A request-scoped session that persists nowhere is the fail-closed
            // default: tokens minted for it are never seen twice, so the CSRF
            // check refuses rather than waves a POST through.
            $session ?? new ArraySession(),
            $messages ?? ContactMessageStoreFactory::fromConfig($config),
            new RateLimiter($clock ?? new SystemClock()),
            // With no database configured there are no accounts, so every login
            // fails generically and every session id resolves to nobody. An
            // unconfigured deployment is a public site, not an open one.
            $accounts ?? AccountRepositoryFactory::fromConfig($config),
            $messageReader ?? ContactMessageReaderFactory::fromConfig($config),
            $messageUpdater
                ?? ($messageReader instanceof ContactMessageStatusUpdater
                    ? $messageReader
                    : ContactMessageStatusUpdaterFactory::fromConfig($config))
        );
    }

    public static function manifestPath(string $basePath): string
    {
        return rtrim(str_replace('\\', '/', $basePath), '/') . '/public/build/manifest.json';
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function router(): Router
    {
        return $this->router;
    }

    /**
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $cookies
     */
    public function selectSkin(array $query, array $cookies = []): SkinSelection
    {
        return $this->policy->select(
            $this->registry,
            SkinSelectionContext::fromRequest($query, $cookies, $this->config)
        );
    }

    /**
     * Dispatches one request.
     *
     * Every failure below this line — a missing route, a wrong method, a broken
     * handler, a template that throws — leaves through {@see ErrorPresenter},
     * so no code path can return a response whose disclosure was not decided in
     * one place.
     */
    public function handle(Request $request): Response
    {
        $skin = null;
        $assets = AssetBundle::empty();
        $privateResponse = false;

        try {
            $selection = $this->selectSkin($request->query(), $request->cookies());
            $skin = $selection->skin();

            if ($request->needsCanonicalRedirect()) {
                // One URL per page: the non-canonical spelling is redirected
                // rather than served, so links and caches agree.
                return Response::redirect($request->canonicalTarget(), Response::STATUS_MOVED_PERMANENTLY);
            }

            $assets = $this->assets->resolve($skin);

            $match = $this->router->match($request);

            if ($match->isNotFound()) {
                throw HttpException::notFound(sprintf('No route matches "%s".', $request->path()));
            }

            if ($match->isMethodNotAllowed()) {
                throw HttpException::methodNotAllowed(
                    $match->allowHeader(),
                    sprintf('Method %s is not accepted by "%s".', $request->method(), $request->path())
                );
            }

            $route = $match->route();
            $privateResponse = $route->visibility()->requiresAuthentication();

            // Authorisation happens here — after routing, before dispatch, and
            // in one place. No handler below this line re-checks who is asking,
            // and no template is ever the boundary: a route that is not
            // permitted never reaches the code that would render it.
            $guarded = $this->guard->guard($route, $request);

            if ($guarded !== null) {
                return $guarded;
            }

            return $this->dispatch($route, $match->parameters(), $request, $selection, $assets);
        } catch (HttpException $error) {
            return $this->errors->present(
                $error,
                $error->statusCode(),
                $skin,
                $this->sharedData($request, $skin, null, $assets)
                    + ($privateResponse ? ['noIndex' => true] : []),
                $request
            );
        } catch (Throwable $error) {
            return $this->errors->present(
                $error,
                Response::STATUS_INTERNAL_SERVER_ERROR,
                $skin,
                $this->sharedData($request, $skin, null, $assets)
                    + ($privateResponse ? ['noIndex' => true] : []),
                $request
            );
        }
    }

    /**
     * Routes that have a handler at this checkpoint. A future declared route
     * without one still fails honestly with 501 rather than pretending it does
     * not exist.
     *
     * @param array<string, string> $parameters
     */
    private function dispatch(
        RouteDefinition $route,
        array $parameters,
        Request $request,
        SkinSelection $selection,
        AssetBundle $assets
    ): Response {
        $shared = $this->sharedData($request, $selection->skin(), $selection, $assets);

        return match ($route->name()) {
            RouteCatalog::HOME => $this->page($route, $selection, $shared + [
                'profile' => $this->corpus()->profile(),
                'projects' => $this->corpus()->featuredProjects(),
                'skills' => $this->corpus()->skills(),
                'experiences' => $this->corpus()->experiences(),
            ]),
            RouteCatalog::PROJECTS_INDEX => $this->page($route, $selection, $shared + [
                'projects' => $this->corpus()->projects(),
            ]),
            RouteCatalog::PROJECTS_SHOW => $this->page($route, $selection, $shared + [
                'project' => $this->requireProject($parameters['slug'] ?? ''),
            ]),
            RouteCatalog::ABOUT => $this->page($route, $selection, $shared + [
                'profile' => $this->corpus()->profile(),
                'skills' => $this->corpus()->skills(),
                'experiences' => $this->corpus()->experiences(),
            ]),
            RouteCatalog::CONTACT => $this->contact($route, $request, $selection, $shared),
            RouteCatalog::LOGIN => $this->login($route, $request, $selection, $shared),
            RouteCatalog::LOGOUT => $this->logout(),
            RouteCatalog::ADMIN_DASHBOARD => $this->privatePage($route, $selection, $shared),
            RouteCatalog::ADMIN_MESSAGES => $this->adminMessages($route, $request, $selection, $shared),
            RouteCatalog::CLIENT_AREA => $this->privatePage($route, $selection, $shared),
            default => throw HttpException::notImplemented(sprintf(
                'Route "%s" is declared but has no handler yet.',
                $route->name()
            )),
        };
    }

    /** The flash key a successful submission leaves for the redirected GET. */
    private const CONTACT_FLASH = 'contact.flash';

    /** Its only value. A flash is a signal, not a place to keep a message. */
    private const CONTACT_FLASH_SENT = 'sent';

    /** The throttle bucket contact submissions are counted in. */
    private const CONTACT_THROTTLE = 'contact';

    /**
     * The field a person never fills in and an indiscriminate bot always does.
     *
     * Named for something a form plausibly asks for rather than `honeypot`,
     * because the whole mechanism is that the submitter cannot tell it apart
     * from a real control.
     */
    private const CONTACT_HONEYPOT = 'website';

    /**
     * GET renders the form; POST decides what to do with a submission.
     *
     * The order of the checks below is the design, not an accident. Proof of
     * intent comes first, because a request that cannot show it should not
     * reach anything that costs work or leaves a trace. Rate limiting comes
     * next, so a client that keeps trying is bounded whatever it is sending.
     * The honeypot follows, because a bot deserves the cheapest possible exit.
     * Validation is fourth, and storage — the only step with an effect that
     * outlives the request — is last, so nothing before it can leave a row
     * behind.
     *
     * @param array<string, mixed> $shared
     */
    private function contact(
        RouteDefinition $route,
        Request $request,
        SkinSelection $selection,
        array $shared
    ): Response {
        if (!$request->isMethod(HttpMethod::Post)) {
            // A one-shot flash: read here and gone, so a second GET of the same
            // URL shows the form rather than re-announcing a message that was
            // already confirmed.
            $sent = $this->session->pull(self::CONTACT_FLASH) === self::CONTACT_FLASH_SENT;

            return $this->contactPage($route, $selection, $shared, $sent ? [
                'notice' => [
                    'kind' => 'success',
                    // Receipt and storage, and not a word beyond it. Nothing
                    // here emails, forwards or acknowledges anything, so a
                    // promise to reply would be a promise no code keeps.
                    'text' => 'Thank you — your message has been received and stored on this site.',
                ],
            ] : []);
        }

        if (!$this->csrf->isValid($this->session, $request->bodyParam(CsrfGuard::FIELD))) {
            // Deterministic and total: a missing token, a stale token, a token
            // from another session and a token that is merely wrong all end
            // here, before validation, before throttling, before any write.
            throw HttpException::forbidden('The contact submission carried no valid CSRF token.');
        }

        $decision = $this->limiter->attempt($this->session, self::CONTACT_THROTTLE);

        // Whatever the outcome below, the submitted values are normalised once
        // so a form that comes back carries what the server actually read.
        $validation = $this->validator->validate($request->body());

        if (!$decision->isAllowed()) {
            return $this->contactPage($route, $selection, $shared, [
                'values' => $validation->values(),
                'notice' => [
                    'kind' => 'error',
                    'text' => sprintf(
                        'That is several messages in a short time. Please try again in about %d minutes.',
                        max(1, (int) ceil($decision->retryAfterSeconds() / 60))
                    ),
                ],
            ], Response::STATUS_TOO_MANY_REQUESTS);
        }

        if (trim($request->bodyParam(self::CONTACT_HONEYPOT, '') ?? '') !== '') {
            // Answered exactly as a real submission is — same status, same
            // redirect, same flash. A bot that could tell the difference would
            // simply stop filling the field in.
            return $this->acceptedContactRedirect($route);
        }

        if (!$validation->isValid()) {
            return $this->contactPage($route, $selection, $shared, [
                'values' => $validation->values(),
                'errors' => $validation->errors(),
                'notice' => [
                    'kind' => 'error',
                    'text' => 'Your message was not sent. Please correct the fields marked below.',
                ],
            ], Response::STATUS_UNPROCESSABLE_CONTENT);
        }

        $submission = $validation->submission();

        // Unreachable while isValid() and submission() agree; asserted rather
        // than assumed, because "valid but absent" must never become an insert.
        if ($submission === null) {
            throw HttpException::internal('A valid contact submission carried no message.');
        }

        try {
            $this->messages->store($submission);
        } catch (ContactStoreException $error) {
            // The honest failure. No flash is set and no redirect is issued, so
            // the visitor keeps what they typed and is told plainly that it was
            // not received — the one thing worse than losing a message is
            // claiming to have kept it. The cause never reaches the page.
            return $this->contactPage($route, $selection, $shared, [
                'values' => $validation->values(),
                'notice' => [
                    'kind' => 'error',
                    'text' => 'Your message could not be stored, so it has not been received. '
                        . 'Please try again shortly, or use one of the links below.',
                ],
            ], Response::STATUS_INTERNAL_SERVER_ERROR);
        }

        return $this->acceptedContactRedirect($route);
    }

    /**
     * The Post/Redirect/Get half of a successful submission.
     *
     * 303 rather than 302 so the redirected request is a GET by specification
     * and not by browser convention, which is what makes a refresh of the
     * landing page a re-GET instead of a re-POST.
     *
     * The token is rotated here, so the exact secret that authorised this
     * insert cannot authorise another. The confirmation travels as a session
     * flash rather than as `?sent=1`: a query flag is something anyone can
     * type, link to, or screenshot into a false confirmation, and it would
     * announce a stored message where none exists.
     */
    private function acceptedContactRedirect(RouteDefinition $route): Response
    {
        $this->csrf->rotate($this->session);
        $this->session->put(self::CONTACT_FLASH, self::CONTACT_FLASH_SENT);

        return Response::redirect($route->path(), Response::STATUS_SEE_OTHER);
    }

    /**
     * Renders the contact form in whatever state the handler decided.
     *
     * Every state goes through here, so the page cannot acquire a token in one
     * branch and lose it in another: a form re-rendered after a failure is as
     * submittable as the one that was first served.
     *
     * @param array<string, mixed>  $shared
     * @param array<string, mixed>  $state  overrides for this rendering
     */
    private function contactPage(
        RouteDefinition $route,
        SkinSelection $selection,
        array $shared,
        array $state = [],
        int $status = Response::STATUS_OK
    ): Response {
        $defaults = [
            // The canonical profile is the page's only source of an
            // alternative way to reach the author: the view must never write
            // an address of its own, so it is handed the links rather than
            // left to invent them.
            'profile' => $this->corpus()->profile(),
            'csrfField' => CsrfGuard::FIELD,
            'csrfToken' => $this->csrf->token($this->session),
            'honeypotField' => self::CONTACT_HONEYPOT,
            'values' => array_fill_keys(ContactValidator::FIELDS, ''),
            'errors' => [],
            'notice' => null,
        ];

        return Response::html(
            $this->renderer->render($selection->skin(), $route->template(), $state + $defaults + $shared),
            $status
        );
    }

    // ------------------------------------------------------------ auth

    /** The form field the address is submitted in. */
    private const LOGIN_EMAIL = 'email';

    /** The form field the password is submitted in — and never echoed back. */
    private const LOGIN_PASSWORD = 'password';

    /**
     * The only thing a failed sign-in is ever told.
     *
     * One sentence for an unknown address, a wrong password and a disabled
     * account alike. Distinguishing them would turn this form into a way of
     * asking whether an address has an account here, and telling somebody that
     * their account is disabled tells whoever stole their password the same.
     */
    private const LOGIN_FAILED = 'Those details did not match an account that can sign in.';

    /**
     * GET renders the form; POST decides whether it identifies anyone.
     *
     * Two of this handler's rules are enforced before it runs, by
     * {@see AccessGuard}: an already-authenticated visitor never reaches it —
     * they are redirected to their own area — and a POST without this session's
     * CSRF token is refused with 403. What remains here is the credential
     * decision itself, and it is deliberately the shortest path in the file.
     *
     * @param array<string, mixed> $shared
     */
    private function login(
        RouteDefinition $route,
        Request $request,
        SkinSelection $selection,
        array $shared
    ): Response {
        if (!$request->isMethod(HttpMethod::Post)) {
            return $this->loginPage($route, $selection, $shared);
        }

        $email = trim($request->bodyParam(self::LOGIN_EMAIL, '') ?? '');
        $account = $this->auth->attempt($email, $request->bodyParam(self::LOGIN_PASSWORD, '') ?? '');

        if ($account === null) {
            // The address comes back so a typo can be corrected; the password
            // does not, and there is no branch here in which it could. A
            // rejected form that redisplays a password writes a credential into
            // markup, into the browser's cache and into anything that logs a
            // response body.
            return $this->loginPage($route, $selection, $shared, [
                'values' => [self::LOGIN_EMAIL => $email],
                'notice' => ['kind' => 'error', 'text' => self::LOGIN_FAILED],
            ], Response::STATUS_UNPROCESSABLE_CONTENT);
        }

        // Re-keys the session before a single authenticated byte is written to
        // it, then rotates the CSRF token. Both live in the authenticator, so
        // no handler can perform half of a sign-in.
        $this->authenticator->login($account);

        // 303, so the browser follows with a GET and a refresh of the landing
        // page cannot re-post credentials. Where it lands is decided by the
        // role on the row that was just read — never by anything submitted.
        return Response::redirect(
            AccessPolicy::homePathFor($account->role()),
            Response::STATUS_SEE_OTHER
        );
    }

    /**
     * Renders the sign-in form in whatever state the handler decided.
     *
     * Every state goes through here, so a form re-rendered after a refusal is
     * as submittable as the one first served — including the token, which is
     * read (and minted if absent) exactly once per rendering.
     *
     * @param array<string, mixed> $shared
     * @param array<string, mixed> $state
     */
    private function loginPage(
        RouteDefinition $route,
        SkinSelection $selection,
        array $shared,
        array $state = [],
        int $status = Response::STATUS_OK
    ): Response {
        $defaults = [
            'csrfField' => CsrfGuard::FIELD,
            'csrfToken' => $this->csrf->token($this->session),
            'emailField' => self::LOGIN_EMAIL,
            'passwordField' => self::LOGIN_PASSWORD,
            'values' => [self::LOGIN_EMAIL => ''],
            'notice' => null,
        ];

        return Response::html(
            $this->renderer->render($selection->skin(), $route->template(), $state + $defaults + $shared),
            $status
        );
    }

    /**
     * Ends the session and sends the visitor to the public site.
     *
     * It renders nothing — the route declares a template it never reaches,
     * because a logout that returns a page is a page served to somebody who no
     * longer has a session to render it against. Both of its preconditions are
     * already settled by the guard: the request is a POST from an authenticated
     * visitor, carrying this session's CSRF token.
     */
    private function logout(): Response
    {
        $this->authenticator->logout();

        return Response::redirect(
            RouteCatalog::get(RouteCatalog::HOME)->path(),
            Response::STATUS_SEE_OTHER
        );
    }

    /** @param array<string, mixed> $shared */
    private function privatePage(RouteDefinition $route, SkinSelection $selection, array $shared): Response
    {
        $account = $this->authenticator->current();

        if ($account === null) {
            throw HttpException::internal('A private handler had no resolved account.');
        }

        return $this->page($route, $selection, $shared + [
            'accountEmail' => $account->email(),
            'csrfField' => CsrfGuard::FIELD,
            'csrfToken' => $this->csrf->token($this->session),
            'noIndex' => true,
        ]);
    }

    /** @param array<string, mixed> $shared */
    private function adminMessages(
        RouteDefinition $route,
        Request $request,
        SkinSelection $selection,
        array $shared
    ): Response {
        if ($request->isMethod(HttpMethod::Post)) {
            return $this->updateAdminMessage($request);
        }

        $rawId = $request->queryParam('id');
        $selected = null;

        if ($rawId !== null) {
            if (!ctype_digit($rawId) || $rawId === '0' || strlen($rawId) > 10 || (int) $rawId > 2147483647) {
                throw HttpException::badRequest('The message id is malformed.');
            }

            $selected = $this->messageReader->find((int) $rawId);

            if ($selected === null) {
                throw HttpException::notFound('No contact message has that id.');
            }
        }

        try {
            $messages = $this->messageReader->newest(50);
        } catch (ContactInboxException $error) {
            throw HttpException::internal('The admin inbox could not be read.', $error);
        }

        return $this->privatePage($route, $selection, $shared + [
            'messages' => $messages,
            'selectedMessage' => $selected,
        ]);
    }

    private function updateAdminMessage(Request $request): Response
    {
        $rawId = $request->bodyParam('id');

        if ($rawId === null || !ctype_digit($rawId) || $rawId === '0'
            || strlen($rawId) > 10 || (int) $rawId > 2147483647) {
            throw HttpException::badRequest('The message id is malformed.');
        }

        $status = ContactMessageStatus::tryFrom($request->bodyParam('status', '') ?? '');

        if ($status === null) {
            throw HttpException::unprocessable('The message status is invalid.');
        }

        try {
            $exists = $this->messageUpdater->updateStatus((int) $rawId, $status);
        } catch (ContactMessageMutationException $error) {
            throw HttpException::internal('The message status could not be updated.', $error);
        }

        if (!$exists) {
            throw HttpException::notFound('No contact message has that id.');
        }

        $this->csrf->rotate($this->session);

        return Response::redirect(
            RouteCatalog::get(RouteCatalog::ADMIN_MESSAGES)->path() . '?id=' . $rawId,
            Response::STATUS_SEE_OTHER
        );
    }

    /**
     * Renders a route's declared logical view with the selected skin.
     *
     * The route supplies the view identifier and the skin supplies the file:
     * no path is ever composed here, which is what keeps shared HTTP code from
     * knowing a single skin template location.
     *
     * @param array<string, mixed> $data
     */
    private function page(RouteDefinition $route, SkinSelection $selection, array $data): Response
    {
        return Response::html($this->renderer->render($selection->skin(), $route->template(), $data));
    }

    /**
     * Validates the URL parameter through the canonical slug contract, then
     * resolves it. A malformed slug never reaches the corpus, and an unknown
     * one is a 404 rather than an exception surfacing to the user.
     */
    private function requireProject(string $slug): Project
    {
        if (!Slug::isValid($slug)) {
            throw HttpException::notFound(sprintf('"%s" is not a valid slug.', $slug));
        }

        $project = $this->corpus()->findProject(Slug::fromString($slug));

        if ($project === null) {
            throw HttpException::notFound(sprintf('No project has the slug "%s".', $slug));
        }

        return $project;
    }

    /**
     * Data every view of a request receives, whatever it renders.
     *
     * @return array<string, mixed>
     */
    private function sharedData(
        Request $request,
        ?SkinDefinition $skin,
        ?SkinSelection $selection = null,
        ?AssetBundle $assets = null
    ): array
    {
        $data = [
            'appName' => $this->config->get('APP_NAME', 'Facet') ?? 'Facet',
            'locale' => $this->config->get('APP_LOCALE', 'en') ?? 'en',
            'environment' => $this->config->environment(),
            'path' => $request->path(),
            'assets' => $assets ?? AssetBundle::empty(),
            // The shell is rendered by every view, including error views, so
            // the navigation model is shared data rather than page data — a
            // 404 gets the same working header as a 200.
            'navigation' => Navigation::primary($request->path()),
        ];

        if ($skin !== null) {
            $data['skin'] = $skin;
        }

        if ($selection !== null) {
            $data['selection'] = $selection;
        }

        return $data;
    }

    /**
     * The canonical corpus, loaded once per request.
     */
    private function corpus(): Corpus
    {
        return $this->corpus ??= CorpusLoader::default($this->basePath)->load();
    }
}
