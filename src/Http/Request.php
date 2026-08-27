<?php

declare(strict_types=1);

namespace Facet\Http;

use Facet\Routing\HttpMethod;

/**
 * One inbound HTTP request, captured as immutable data.
 *
 * The class never reads a superglobal. {@see self::fromGlobals()} is handed the
 * arrays explicitly by the entrypoint, which is what makes the whole runtime
 * drivable from a test — a dispatcher that reaches for $_SERVER cannot be
 * exercised for a method or a path the CLI never sets.
 *
 * The request also owns path normalisation, because "which path was asked for"
 * must have exactly one answer before routing, escaping or redirecting can
 * reason about it.
 */
final class Request
{
    private string $method;

    /** The target exactly as received, query string included. */
    private string $target;

    /** Percent-decoded, slash-normalised path. */
    private string $path;

    /** @var list<string> decoded path segments, no empties */
    private array $segments;

    /** @var array<string, string> */
    private array $query;

    /** @var array<string, string> */
    private array $body;

    /** @var array<string, string> */
    private array $cookies;

    /** @var array<string, string> lowercase header names */
    private array $headers;

    /**
     * @param list<string>          $segments
     * @param array<string, string> $query
     * @param array<string, string> $body
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     */
    private function __construct(
        string $method,
        string $target,
        string $path,
        array $segments,
        array $query,
        array $body,
        array $cookies,
        array $headers
    ) {
        $this->method = $method;
        $this->target = $target;
        $this->path = $path;
        $this->segments = $segments;
        $this->query = $query;
        $this->body = $body;
        $this->cookies = $cookies;
        $this->headers = $headers;
    }

    /**
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $body
     * @param array<array-key, mixed> $cookies
     * @param array<array-key, mixed> $headers
     */
    public static function create(
        string $method = 'GET',
        string $target = '/',
        array $query = [],
        array $body = [],
        array $cookies = [],
        array $headers = []
    ): self {
        $target = $target === '' ? '/' : $target;
        [$path, $segments] = self::normalisePath(self::pathOf($target));

        return new self(
            self::normaliseMethod($method),
            $target,
            $path,
            $segments,
            self::stringMap($query),
            self::stringMap($body),
            self::stringMap($cookies),
            self::normaliseHeaders($headers)
        );
    }

    /**
     * Adapts PHP's request arrays. Every one of them is a parameter: nothing in
     * this class knows the names $_SERVER, $_GET, $_POST or $_COOKIE.
     *
     * Missing keys are the CLI case, and they degrade to a plain `GET /` rather
     * than to a notice-laden half request.
     *
     * @param array<array-key, mixed> $server
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $body
     * @param array<array-key, mixed> $cookies
     */
    public static function fromGlobals(array $server, array $query = [], array $body = [], array $cookies = []): self
    {
        $method = $server['REQUEST_METHOD'] ?? 'GET';
        $target = $server['REQUEST_URI'] ?? '/';

        return self::create(
            is_string($method) ? $method : 'GET',
            is_string($target) && $target !== '' ? $target : '/',
            $query,
            $body,
            $cookies,
            self::headersFromServer($server)
        );
    }

    /** The method exactly as received, upper-cased. */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * The method as a catalog-level method, or null when the client used one
     * the route contract does not model. Null is not an error here: it is what
     * lets the router answer 405 instead of pretending the request was a GET.
     */
    public function httpMethod(): ?HttpMethod
    {
        return HttpMethod::tryFrom($this->method);
    }

    public function isMethod(HttpMethod $method): bool
    {
        return $this->httpMethod() === $method;
    }

    /** The canonical path routing matches against. */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return list<string>
     */
    public function segments(): array
    {
        return $this->segments;
    }

    public function target(): string
    {
        return $this->target;
    }

    /**
     * True when the received path differs from the URL-encoded canonical path.
     * Percent-triplet casing is immaterial: changing only `%2f` to `%2F` would
     * still redirect to the same effective path. The caller decides whether to
     * redirect; the request only reports a genuine discrepancy.
     */
    public function needsCanonicalRedirect(): bool
    {
        $received = preg_replace_callback(
            '/%[0-9a-f]{2}/i',
            static fn (array $match): string => strtoupper($match[0]),
            self::pathOf($this->target)
        );

        return $received !== $this->canonicalPath();
    }

    /**
     * The canonical target this request should be redirected to, query string
     * preserved.
     */
    public function canonicalTarget(): string
    {
        $queryString = $this->queryString();

        return $queryString === '' ? $this->canonicalPath() : $this->canonicalPath() . '?' . $queryString;
    }

    public function queryString(): string
    {
        return http_build_query($this->query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array<string, string>
     */
    public function query(): array
    {
        return $this->query;
    }

    public function queryParam(string $name, ?string $default = null): ?string
    {
        return $this->query[$name] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function body(): array
    {
        return $this->body;
    }

    public function bodyParam(string $name, ?string $default = null): ?string
    {
        return $this->body[$name] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    private static function normaliseMethod(string $method): string
    {
        $method = strtoupper(trim($method));

        // Anything outside the token grammar is not a method we will echo back
        // anywhere; collapse it to a value the router will simply not accept.
        return preg_match('/^[A-Z]+$/', $method) === 1 ? $method : 'INVALID';
    }

    private static function pathOf(string $target): string
    {
        // Not strtok(): it would skip a leading separator and turn "?a=1"
        // into a path of "a=1".
        $path = explode('?', $target, 2)[0];

        return $path === '' ? '/' : $path;
    }

    /**
     * Decodes each segment on its own, so a percent-encoded separator stays a
     * literal character inside one segment instead of inventing a new one.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function normalisePath(string $path): array
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }

            $segments[] = rawurldecode($segment);
        }

        return [$segments === [] ? '/' : '/' . implode('/', $segments), $segments];
    }

    /** Encodes the decoded routing segments back into their canonical URL path. */
    private function canonicalPath(): string
    {
        return $this->segments === []
            ? '/'
            : '/' . implode('/', array_map(rawurlencode(...), $this->segments));
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, string>
     */
    private static function stringMap(array $values): array
    {
        $map = [];

        foreach ($values as $name => $value) {
            if (!is_string($name)) {
                continue;
            }

            // Arrays and files are out of the contract at this checkpoint; a
            // non-scalar is dropped rather than silently stringified.
            if (is_string($value)) {
                $map[$name] = $value;
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $map[$name] = (string) $value;
            }
        }

        return $map;
    }

    /**
     * @param array<array-key, mixed> $headers
     *
     * @return array<string, string>
     */
    private static function normaliseHeaders(array $headers): array
    {
        $normalised = [];

        foreach ($headers as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $normalised[strtolower($name)] = $value;
            }
        }

        return $normalised;
    }

    /**
     * @param array<array-key, mixed> $server
     *
     * @return array<string, string>
     */
    private static function headersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                continue;
            }

            if (str_starts_with($name, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
            } elseif ($name === 'CONTENT_TYPE' || $name === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $name))] = $value;
            }
        }

        return $headers;
    }
}
