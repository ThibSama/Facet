<?php

declare(strict_types=1);

namespace Facet\Asset;

/**
 * The build entrypoints every request loads, whichever skin was selected.
 *
 * Shared assets are the base layer: the progressive-enhancement runtime and the
 * base stylesheet. Naming them once here is what keeps entrypoint strings out
 * of the HTTP entrypoint and out of individual skins.
 */
final class SharedAssets
{
    /** @var non-empty-list<string> */
    private const ENTRYPOINTS = [
        'resources/js/app.ts',
    ];

    /**
     * @return non-empty-list<string>
     */
    public static function entrypoints(): array
    {
        return self::ENTRYPOINTS;
    }

    public static function isShared(string $entrypoint): bool
    {
        return in_array($entrypoint, self::ENTRYPOINTS, true);
    }
}
