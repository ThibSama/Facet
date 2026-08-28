<?php

declare(strict_types=1);

namespace Facet\Seo;

use Facet\Config\Config;

/**
 * A validated, configuration-owned public site URL.
 *
 * Request headers are deliberately absent from this class. Canonical URLs are
 * deployment configuration, not something a client can choose through Host or
 * X-Forwarded-*.
 */
final class SiteUrl
{
    private string $base;

    private function __construct(string $base)
    {
        $this->base = $base;
    }

    public static function fromConfig(Config $config): ?self
    {
        $candidate = trim($config->get('APP_URL', '') ?? '');

        if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($candidate);

        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) !== []) {
            return null;
        }

        $host = strtolower($parts['host']);

        if ($config->isProduction() && self::isLocalHost($host)) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = self::normaliseBasePath($parts['path'] ?? '');

        if ($path === null) {
            return null;
        }

        return new self($scheme . '://' . $host . $port . $path);
    }

    public function absolute(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return $path === '/' ? $this->base . '/' : $this->base . $path;
    }

    private static function normaliseBasePath(string $path): ?string
    {
        if ($path === '' || $path === '/') {
            return '';
        }

        $segments = explode('/', trim($path, '/'));

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return '/' . implode('/', $segments);
    }

    private static function isLocalHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === '::1') {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            && filter_var($host, FILTER_VALIDATE_IP) !== false;
    }
}
