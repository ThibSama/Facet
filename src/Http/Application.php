<?php

declare(strict_types=1);

namespace Facet\Http;

use Facet\Asset\AssetBundle;
use Facet\Asset\AssetManager;
use Facet\Config\Config;
use Facet\Content\Corpus;
use Facet\Content\CorpusLoader;
use Facet\Content\Project;
use Facet\Navigation\Navigation;
use Facet\Routing\HttpMethod;
use Facet\Routing\RouteCatalog;
use Facet\Routing\RouteDefinition;
use Facet\Skin\Selection\DefaultSkinSelectionPolicy;
use Facet\Skin\Selection\SkinSelection;
use Facet\Skin\Selection\SkinSelectionContext;
use Facet\Skin\Selection\SkinSelectionPolicy;
use Facet\Skin\SkinDefinition;
use Facet\Skin\SkinRegistry;
use Facet\Skin\SkinRenderer;
use Facet\Support\Slug;
use Throwable;

/**
 * The HTTP application: a Request goes in, a Response comes out.
 *
 * It is a pure function of its input. No superglobal is read, no header is
 * sent, nothing is echoed — which is what allows every dispatch rule below,
 * including the error paths, to be exercised in a plain unit test.
 *
 * The class knows about routes, assets, skins and content, and about none of
 * their internals: it asks the router which route a request reached, a route
 * for its logical view, a policy for a skin, and the asset layer for that
 * skin's URLs. It never names a template file.
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

    private ?Corpus $corpus = null;

    private function __construct(
        string $basePath,
        Config $config,
        SkinRegistry $registry,
        SkinSelectionPolicy $policy,
        AssetManager $assets,
        SkinRenderer $renderer,
        Router $router,
        ErrorPresenter $errors
    ) {
        $this->basePath = $basePath;
        $this->config = $config;
        $this->registry = $registry;
        $this->policy = $policy;
        $this->assets = $assets;
        $this->renderer = $renderer;
        $this->router = $router;
        $this->errors = $errors;
    }

    public static function boot(
        string $basePath,
        ?Config $config = null,
        ?SkinRegistry $registry = null,
        ?SkinSelectionPolicy $policy = null,
        ?Router $router = null
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
            new ErrorPresenter($renderer, $config->isDebug())
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

            return $this->dispatch($match->route(), $match->parameters(), $request, $selection, $assets);
        } catch (HttpException $error) {
            return $this->errors->present(
                $error,
                $error->statusCode(),
                $skin,
                $this->sharedData($request, $skin, null, $assets),
                $request
            );
        } catch (Throwable $error) {
            return $this->errors->present(
                $error,
                Response::STATUS_INTERNAL_SERVER_ERROR,
                $skin,
                $this->sharedData($request, $skin, null, $assets),
                $request
            );
        }
    }

    /**
     * Routes that have a handler at this checkpoint. Everything else in the
     * catalog is declared but not yet built, and says so with 501 rather than
     * pretending it does not exist.
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
            default => throw HttpException::notImplemented(sprintf(
                'Route "%s" is declared but has no handler yet.',
                $route->name()
            )),
        };
    }

    /**
     * The contact form renders on GET. Submission handling needs a message
     * store, which is a later checkpoint — but the method distinction is real
     * today, so POST is answered explicitly instead of falling through to the
     * GET rendering.
     *
     * @param array<string, mixed> $shared
     */
    private function contact(
        RouteDefinition $route,
        Request $request,
        SkinSelection $selection,
        array $shared
    ): Response {
        if ($request->isMethod(HttpMethod::Post)) {
            throw HttpException::notImplemented('Contact submissions are not stored yet.');
        }

        return $this->page($route, $selection, $shared);
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
