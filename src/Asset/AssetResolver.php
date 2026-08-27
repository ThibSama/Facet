<?php

declare(strict_types=1);

namespace Facet\Asset;

use Facet\Skin\SkinDefinition;
use Facet\Support\ViteManifest;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves the shared + selected-skin entrypoints into concrete asset URLs.
 *
 * This is the single place a manifest key becomes a URL. The HTTP entrypoint
 * asks for "the assets for this skin" and never names a build artefact, which
 * is what makes criterion "only shared + selected skin assets are referenced"
 * enforceable instead of aspirational.
 */
final class AssetResolver
{
    private ?ViteManifest $manifest;

    private ?string $devServerOrigin;

    private bool $manifestRequired;

    /** @var list<string> */
    private array $sharedEntrypoints;

    /**
     * @param list<string> $sharedEntrypoints
     */
    private function __construct(
        ?ViteManifest $manifest,
        ?string $devServerOrigin,
        bool $manifestRequired,
        array $sharedEntrypoints
    )
    {
        $this->manifest = $manifest;
        $this->devServerOrigin = $devServerOrigin;
        $this->manifestRequired = $manifestRequired;
        $this->sharedEntrypoints = $sharedEntrypoints;
    }

    /**
     * @param list<string>|null $sharedEntrypoints
     */
    public static function usingManifest(ViteManifest $manifest, ?array $sharedEntrypoints = null): self
    {
        return new self($manifest, null, true, $sharedEntrypoints ?? SharedAssets::entrypoints());
    }

    /**
     * @param list<string>|null $sharedEntrypoints
     */
    public static function fromManifestFile(string $path, ?array $sharedEntrypoints = null): self
    {
        return self::usingManifest(ViteManifest::fromFile($path), $sharedEntrypoints);
    }

    /**
     * Development-only fallback for PHP work without a running or built Vite
     * process. Production never constructs this mode.
     *
     * @param list<string>|null $sharedEntrypoints
     */
    public static function fromOptionalManifestFile(string $path, ?array $sharedEntrypoints = null): self
    {
        $manifest = is_readable($path) ? ViteManifest::fromFile($path) : null;

        return new self($manifest, null, false, $sharedEntrypoints ?? SharedAssets::entrypoints());
    }

    /**
     * Creates URLs only; deliberately performs no network probe.
     *
     * @param list<string>|null $sharedEntrypoints
     */
    public static function usingDevServer(string $origin, ?array $sharedEntrypoints = null): self
    {
        $origin = self::normaliseDevServerOrigin($origin);

        return new self(null, $origin, false, $sharedEntrypoints ?? SharedAssets::entrypoints());
    }

    public function hasManifest(): bool
    {
        return $this->manifest !== null;
    }

    public function isDevelopmentServer(): bool
    {
        return $this->devServerOrigin !== null;
    }

    /**
     * The entrypoints a request loads: shared first, then the selected skin's,
     * de-duplicated and order-stable.
     *
     * @return list<string>
     */
    public function entrypointsFor(SkinDefinition $skin): array
    {
        $ordered = [];

        foreach ([...$this->sharedEntrypoints, ...$skin->assetEntrypoints()] as $entrypoint) {
            $ordered[$entrypoint] = true;
        }

        return array_keys($ordered);
    }

    public function resolve(SkinDefinition $skin): AssetBundle
    {
        return $this->resolveEntrypoints($this->entrypointsFor($skin));
    }

    /**
     * @param list<string> $entrypoints
     */
    private function resolveEntrypoints(array $entrypoints): AssetBundle
    {
        if ($this->devServerOrigin !== null) {
            $scripts = [$this->devServerOrigin . '/@vite/client' => true];

            foreach ($entrypoints as $entrypoint) {
                $scripts[$this->devServerOrigin . '/' . ltrim($entrypoint, '/')] = true;
            }

            return new AssetBundle(array_keys($scripts), [], $entrypoints);
        }

        if ($this->manifest === null) {
            return new AssetBundle([], [], [], $entrypoints);
        }

        $manifest = $this->manifest;

        $scripts = [];
        $styles = [];
        $resolved = [];
        $missing = [];

        foreach ($entrypoints as $entrypoint) {
            if (!$manifest->has($entrypoint)) {
                $missing[] = $entrypoint;

                continue;
            }

            $resolved[] = $entrypoint;
            $script = $manifest->script($entrypoint);
            $this->requireFingerprint($entrypoint, $script);
            $scripts[$script] = true;

            foreach ($manifest->styles($entrypoint) as $style) {
                $this->requireFingerprint($entrypoint, $style);
                $styles[$style] = true;
            }
        }

        if ($this->manifestRequired && $missing !== []) {
            throw new RuntimeException(sprintf(
                'Vite manifest is missing required entrypoint(s): %s.',
                implode(', ', $missing)
            ));
        }

        return new AssetBundle(array_keys($scripts), array_keys($styles), $resolved, $missing);
    }

    private function requireFingerprint(string $entrypoint, string $url): void
    {
        if ($this->manifestRequired && !AssetCachePolicy::isFingerprintedBuildAsset($url)) {
            throw new RuntimeException(sprintf(
                'Vite manifest entry "%s" resolved to non-fingerprinted asset "%s".',
                $entrypoint,
                $url
            ));
        }
    }

    private static function normaliseDevServerOrigin(string $origin): string
    {
        $origin = trim($origin);
        $parts = parse_url($origin);

        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
        ) {
            throw new InvalidArgumentException('VITE_DEV_SERVER_ORIGIN must be an HTTP(S) origin without a path.');
        }

        return rtrim($origin, '/');
    }
}
