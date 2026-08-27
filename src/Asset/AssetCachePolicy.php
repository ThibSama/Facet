<?php

declare(strict_types=1);

namespace Facet\Asset;

/**
 * Cache metadata for files served from the public document root.
 *
 * The production web server must apply this policy when it serves static
 * files; PHP does not proxy Vite artefacts. Keeping the classifier here makes
 * the safety rule executable when deployment-specific server configuration is
 * unavailable in this repository.
 */
final class AssetCachePolicy
{
    public const IMMUTABLE = 'public, max-age=31536000, immutable';
    public const REVALIDATE = 'no-cache';

    /** @return array{Cache-Control: string} */
    public static function headersForPublicPath(string $path): array
    {
        return [
            'Cache-Control' => self::isFingerprintedBuildAsset($path)
                ? self::IMMUTABLE
                : self::REVALIDATE,
        ];
    }

    public static function isFingerprintedBuildAsset(string $path): bool
    {
        $path = explode('?', $path, 2)[0];

        return preg_match('~^/build/assets/.+-[A-Za-z0-9_-]{8,}\.[A-Za-z0-9]+$~', $path) === 1;
    }
}
