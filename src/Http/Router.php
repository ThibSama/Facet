<?php

declare(strict_types=1);

namespace Facet\Http;

use Facet\Routing\HttpMethod;
use Facet\Routing\RouteCatalog;
use Facet\Routing\RouteDefinition;
use Facet\Routing\RouteParameter;

/**
 * Matches a request against the canonical route catalog.
 *
 * The router owns no route definitions of its own: it reads {@see RouteCatalog}
 * so the dispatcher and the contract can never drift. Matching is segment-wise
 * rather than regex-over-the-whole-path, and every dynamic segment is validated
 * by the {@see RouteParameter} the catalog declared — which is how a malformed
 * slug becomes a miss instead of reaching a handler.
 */
final class Router
{
    /** @var list<RouteDefinition> */
    private array $routes;

    /**
     * @param list<RouteDefinition> $routes
     */
    public function __construct(array $routes)
    {
        $this->routes = $routes;
    }

    public static function fromCatalog(): self
    {
        return new self(array_values(RouteCatalog::all()));
    }

    /**
     * @return list<RouteDefinition>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    public function match(Request $request): RouteMatch
    {
        $method = $request->httpMethod();
        $segments = $request->segments();

        /** @var list<RouteDefinition> $pathMatches */
        $pathMatches = [];

        foreach ($this->routes as $route) {
            $extracted = self::extract($route, $segments);

            if ($extracted === null) {
                continue;
            }

            $pathMatches[] = $route;

            if ($method !== null && $route->accepts($method)) {
                return RouteMatch::matched($route, $extracted);
            }
        }

        if ($pathMatches === []) {
            return RouteMatch::notFound();
        }

        // The path exists; the method does not. Advertise every method any
        // route on this path accepts, which is what a client needs to retry.
        $allowed = [];

        foreach ($pathMatches as $route) {
            foreach ($route->methods() as $candidate) {
                $allowed[$candidate->value] = $candidate;
            }
        }

        /** @var list<HttpMethod> $allowedList */
        $allowedList = array_values($allowed);

        return RouteMatch::methodNotAllowed($pathMatches[0], $allowedList);
    }

    /**
     * Returns the decoded parameters when the path matches, or null when it
     * does not — including when a declared parameter rejects its value.
     *
     * @param list<string> $segments
     *
     * @return array<string, string>|null
     */
    private static function extract(RouteDefinition $route, array $segments): ?array
    {
        $pattern = self::patternSegments($route);

        if (count($pattern) !== count($segments)) {
            return null;
        }

        /** @var array<string, RouteParameter> $declared */
        $declared = [];
        foreach ($route->parameters() as $parameter) {
            $declared[$parameter->name()] = $parameter;
        }

        $values = [];

        foreach ($pattern as $index => $expected) {
            $actual = $segments[$index];

            if (preg_match('/^\{([a-z][a-zA-Z0-9_]*)\}$/', $expected, $matches) !== 1) {
                if ($expected !== $actual) {
                    return null;
                }

                continue;
            }

            $name = $matches[1];
            $parameter = $declared[$name] ?? null;

            // The catalog guarantees a placeholder is declared; an undeclared
            // one would be a contract bug, and it must not become a wildcard.
            if ($parameter === null || !$parameter->accepts($actual)) {
                return null;
            }

            $values[$name] = $actual;
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private static function patternSegments(RouteDefinition $route): array
    {
        $segments = [];

        foreach (explode('/', $route->path()) as $segment) {
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        return $segments;
    }
}
