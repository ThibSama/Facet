<?php

declare(strict_types=1);

namespace Facet\Http;

use Facet\Routing\HttpMethod;
use Facet\Routing\RouteDefinition;
use RuntimeException;

/**
 * The outcome of matching one request against the canonical route contract.
 *
 * Three outcomes, and they are deliberately distinct: an unknown path, a known
 * path reached with a method it does not accept, and a hit. Collapsing the
 * middle case into "unknown" is exactly the semantic loss this object exists to
 * prevent.
 */
final class RouteMatch
{
    private MatchOutcome $outcome;

    private ?RouteDefinition $route;

    /** @var array<string, string> */
    private array $parameters;

    /** @var list<HttpMethod> */
    private array $allowedMethods;

    /**
     * @param array<string, string> $parameters
     * @param list<HttpMethod>      $allowedMethods
     */
    private function __construct(
        MatchOutcome $outcome,
        ?RouteDefinition $route,
        array $parameters,
        array $allowedMethods
    ) {
        $this->outcome = $outcome;
        $this->route = $route;
        $this->parameters = $parameters;
        $this->allowedMethods = $allowedMethods;
    }

    /**
     * @param array<string, string> $parameters
     */
    public static function matched(RouteDefinition $route, array $parameters = []): self
    {
        return new self(MatchOutcome::Matched, $route, $parameters, $route->methods());
    }

    public static function notFound(): self
    {
        return new self(MatchOutcome::NotFound, null, [], []);
    }

    /**
     * @param list<HttpMethod> $allowed
     */
    public static function methodNotAllowed(RouteDefinition $route, array $allowed): self
    {
        return new self(MatchOutcome::MethodNotAllowed, $route, [], $allowed);
    }

    public function outcome(): MatchOutcome
    {
        return $this->outcome;
    }

    public function isMatch(): bool
    {
        return $this->outcome === MatchOutcome::Matched;
    }

    public function isNotFound(): bool
    {
        return $this->outcome === MatchOutcome::NotFound;
    }

    public function isMethodNotAllowed(): bool
    {
        return $this->outcome === MatchOutcome::MethodNotAllowed;
    }

    /**
     * @throws RuntimeException when nothing matched
     */
    public function route(): RouteDefinition
    {
        if ($this->route === null) {
            throw new RuntimeException('No route is associated with this match outcome.');
        }

        return $this->route;
    }

    /**
     * @return array<string, string>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    public function parameter(string $name): ?string
    {
        return $this->parameters[$name] ?? null;
    }

    /**
     * @return list<HttpMethod>
     */
    public function allowedMethods(): array
    {
        return $this->allowedMethods;
    }

    /**
     * The value of an `Allow` header, as RFC 9110 requires alongside a 405.
     */
    public function allowHeader(): string
    {
        return implode(', ', array_map(static fn (HttpMethod $m): string => $m->value, $this->allowedMethods));
    }
}
