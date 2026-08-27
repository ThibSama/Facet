<?php

declare(strict_types=1);

namespace Facet\Http;

/**
 * Decides whether PHP's built-in server may answer a request from disk.
 *
 * Only `php -S` needs this: a real web server resolves static files before PHP
 * is involved. The built-in server instead hands *every* request to the router
 * script, so the entrypoint has to say "serve this one yourself" for build
 * output — and, far more importantly, has to say nothing at all for anything
 * that is not plainly a file inside the document root.
 *
 * The class is a pure function of two strings so the decision is testable
 * without a server, and so the entrypoint keeps no filesystem logic of its own.
 * It is deliberately conservative: a request it cannot vouch for is not
 * repaired, rewritten or rejected here — it falls through to the application,
 * which already has one deterministic answer for every path it does not route.
 */
final class StaticFile
{
    /**
     * A ceiling well above any fingerprinted asset URL, so a pathological
     * request never reaches the filesystem at all.
     */
    private const MAX_PATH_LENGTH = 2048;

    /**
     * The absolute path the built-in server may serve for this request, or
     * null when the application must handle it instead.
     *
     * @param string $documentRoot absolute, existing directory
     * @param string $requestUri   the raw REQUEST_URI, query string included
     * @param string $routerScript absolute path of the entrypoint itself
     */
    public static function resolve(string $documentRoot, string $requestUri, string $routerScript): ?string
    {
        $path = self::safePath($requestUri);

        if ($path === null) {
            return null;
        }

        $root = realpath($documentRoot);

        if ($root === false) {
            return null;
        }

        $candidate = realpath($root . '/' . $path);

        // realpath() has already collapsed `..`, symlinks and duplicate
        // separators, so this prefix test is what actually confines the
        // result to the document root — encoded traversal included.
        if ($candidate === false
            || !str_starts_with($candidate, $root . DIRECTORY_SEPARATOR)
            || !is_file($candidate)
            || $candidate === $routerScript
        ) {
            return null;
        }

        return $candidate;
    }

    /**
     * The decoded, root-relative path of a request that is safe to hand to a
     * filesystem call — or null, which means "not our business".
     *
     * Nothing here sanitises: a path carrying a byte a filesystem call would
     * reject is refused outright rather than cleaned up and reinterpreted as
     * some other file. That distinction is the whole point. `%00` decodes to a
     * NUL, which makes realpath() throw a ValueError and turns a request that
     * must deterministically 404 into a fatal error; truncating at the NUL
     * would instead silently answer for a path the client never asked for.
     */
    private static function safePath(string $requestUri): ?string
    {
        if (strlen($requestUri) > self::MAX_PATH_LENGTH) {
            return null;
        }

        $requested = parse_url($requestUri, PHP_URL_PATH);

        if (!is_string($requested) || $requested === '' || $requested === '/') {
            return null;
        }

        $decoded = rawurldecode($requested);

        // A NUL anywhere — encoded or raw — disqualifies the request from
        // every filesystem call, not just the first one.
        if (str_contains($decoded, "\0")) {
            return null;
        }

        $relative = ltrim($decoded, '/');

        return $relative === '' ? null : $relative;
    }
}
