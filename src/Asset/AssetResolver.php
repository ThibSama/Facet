<?php

declare(strict_types=1);

namespace Facet\Asset;

use Facet\Skin\SkinDefinition;
use Facet\Support\ViteManifest;

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

    /** @var list<string> */
    private array $sharedEntrypoints;

    /**
     * @param list<string> $sharedEntrypoints
     */
    private function __construct(?ViteManifest $manifest, array $sharedEntrypoints)
    {
        $this->manifest = $manifest;
        $this->sharedEntrypoints = $sharedEntrypoints;
    }

    /**
     * @param list<string>|null $sharedEntrypoints
     */
    public static function usingManifest(ViteManifest $manifest, ?array $sharedEntrypoints = null): self
    {
        return new self($manifest, $sharedEntrypoints ?? SharedAssets::entrypoints());
    }

    /**
     * Tolerates a missing manifest: a checkout without a build still renders
     * server-side HTML, just without enhancement. Progressive enhancement is
     * the whole point — assets are an upgrade, not a dependency.
     *
     * @param list<string>|null $sharedEntrypoints
     */
    public static function fromManifestFile(string $path, ?array $sharedEntrypoints = null): self
    {
        $manifest = is_readable($path) ? ViteManifest::fromFile($path) : null;

        return new self($manifest, $sharedEntrypoints ?? SharedAssets::entrypoints());
    }

    public function hasManifest(): bool
    {
        return $this->manifest !== null;
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
        if ($this->manifest === null) {
            return new AssetBundle([], [], [], $entrypoints);
        }

        $scripts = [];
        $styles = [];
        $resolved = [];
        $missing = [];

        foreach ($entrypoints as $entrypoint) {
            if (!$this->manifest->has($entrypoint)) {
                $missing[] = $entrypoint;

                continue;
            }

            $resolved[] = $entrypoint;
            $scripts[$this->manifest->script($entrypoint)] = true;

            foreach ($this->manifest->styles($entrypoint) as $style) {
                $styles[$style] = true;
            }
        }

        return new AssetBundle(array_keys($scripts), array_keys($styles), $resolved, $missing);
    }
}
