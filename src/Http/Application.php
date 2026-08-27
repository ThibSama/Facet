<?php

declare(strict_types=1);

namespace Facet\Http;

use Facet\Asset\AssetResolver;
use Facet\Config\Config;
use Facet\Routing\RouteCatalog;
use Facet\Skin\Selection\DefaultSkinSelectionPolicy;
use Facet\Skin\Selection\SkinSelection;
use Facet\Skin\Selection\SkinSelectionContext;
use Facet\Skin\Selection\SkinSelectionPolicy;
use Facet\Skin\SkinRegistry;
use Facet\Skin\SkinRenderer;

/**
 * Wires the shared runtime to whichever skin the policy selects.
 *
 * Everything the HTTP entrypoint used to do inline lives here so it can be
 * driven with plain arrays in a test — the CLI never populates $_GET, and a
 * skin-override guarantee that cannot be exercised is not a guarantee.
 *
 * The class knows about routes, assets and skins, and about none of their
 * internals: it asks a route for a logical view, a policy for a skin, and the
 * asset layer for that skin's URLs.
 */
final class Application
{
    private string $basePath;

    private Config $config;

    private SkinRegistry $registry;

    private SkinSelectionPolicy $policy;

    private AssetResolver $assets;

    private SkinRenderer $renderer;

    private function __construct(
        string $basePath,
        Config $config,
        SkinRegistry $registry,
        SkinSelectionPolicy $policy,
        AssetResolver $assets,
        SkinRenderer $renderer
    ) {
        $this->basePath = $basePath;
        $this->config = $config;
        $this->registry = $registry;
        $this->policy = $policy;
        $this->assets = $assets;
        $this->renderer = $renderer;
    }

    public static function boot(
        string $basePath,
        ?Config $config = null,
        ?SkinRegistry $registry = null,
        ?SkinSelectionPolicy $policy = null
    ): self {
        $basePath = rtrim(str_replace('\\', '/', $basePath), '/');

        return new self(
            $basePath,
            $config ?? Config::fromEnvironment($basePath),
            $registry ?? SkinRegistry::default(),
            $policy ?? new DefaultSkinSelectionPolicy(),
            AssetResolver::fromManifestFile(self::manifestPath($basePath)),
            SkinRenderer::forBasePath($basePath)
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
     * Renders the home route with the selected skin.
     *
     * Only one route is wired at this checkpoint; dispatching the rest is a
     * later concern and does not change this seam, because the route already
     * supplies the logical view name.
     *
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $cookies
     */
    public function handle(array $query = [], array $cookies = []): string
    {
        $selection = $this->selectSkin($query, $cookies);
        $skin = $selection->skin();

        return $this->renderer->render($skin, RouteCatalog::get(RouteCatalog::HOME)->template(), [
            'assets' => $this->assets->resolve($skin),
            'skin' => $skin,
            'selection' => $selection,
            'appName' => $this->config->get('APP_NAME', 'Facet') ?? 'Facet',
            'locale' => $this->config->get('APP_LOCALE', 'en') ?? 'en',
            'environment' => $this->config->environment(),
        ]);
    }
}
