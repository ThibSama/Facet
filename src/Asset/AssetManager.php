<?php

declare(strict_types=1);

namespace Facet\Asset;

use Facet\Config\Config;
use Facet\Skin\SkinDefinition;

/**
 * Selects the one asset delivery mode used by an application process.
 *
 * Production is manifest-only and fails closed. Development can opt into a
 * Vite origin without contacting it; otherwise it uses an optional local build
 * so PHP-only work remains possible before Vite has been started.
 */
final class AssetManager
{
    private AssetResolver $resolver;

    private function __construct(AssetResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * @param list<string>|null $sharedEntrypoints
     */
    public static function fromConfig(
        Config $config,
        string $manifestPath,
        ?array $sharedEntrypoints = null
    ): self {
        if ($config->isProduction()) {
            return new self(AssetResolver::fromManifestFile($manifestPath, $sharedEntrypoints));
        }

        $devServerOrigin = $config->get('VITE_DEV_SERVER_ORIGIN');

        if ($devServerOrigin !== null && trim($devServerOrigin) !== '') {
            return new self(AssetResolver::usingDevServer($devServerOrigin, $sharedEntrypoints));
        }

        return new self(AssetResolver::fromOptionalManifestFile($manifestPath, $sharedEntrypoints));
    }

    public function resolve(SkinDefinition $skin): AssetBundle
    {
        return $this->resolver->resolve($skin);
    }

    public function isDevelopmentServer(): bool
    {
        return $this->resolver->isDevelopmentServer();
    }
}
