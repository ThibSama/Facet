<?php

declare(strict_types=1);

namespace Facet\Routing;

use InvalidArgumentException;

/**
 * One canonical route, declared as data.
 *
 * A route knows its identity, its accepted methods, who may reach it, where its
 * data comes from and which logical template renders it. It deliberately knows
 * nothing about HTML, controllers, middleware or any skin: the logical template
 * is an identifier a future presentation layer maps to a file of its choosing.
 */
final class RouteDefinition
{
    private string $name;

    private string $path;

    /** @var non-empty-list<HttpMethod> */
    private array $methods;

    private Visibility $visibility;

    private DataSource $dataSource;

    private string $template;

    /** @var list<RouteParameter> */
    private array $parameters;

    /**
     * @param non-empty-list<HttpMethod> $methods
     * @param list<RouteParameter>       $parameters
     */
    private function __construct(
        string $name,
        string $path,
        array $methods,
        Visibility $visibility,
        DataSource $dataSource,
        string $template,
        array $parameters
    ) {
        $this->name = $name;
        $this->path = $path;
        $this->methods = $methods;
        $this->visibility = $visibility;
        $this->dataSource = $dataSource;
        $this->template = $template;
        $this->parameters = $parameters;
    }

    /**
     * @param non-empty-list<HttpMethod> $methods
     * @param list<RouteParameter>       $parameters
     *
     * @throws InvalidArgumentException when the declaration is internally inconsistent
     */
    public static function define(
        string $name,
        string $path,
        array $methods,
        Visibility $visibility,
        DataSource $dataSource,
        string $template,
        array $parameters = []
    ): self {
        if ($name === '') {
            throw new InvalidArgumentException('A route name must not be empty.');
        }

        if (!str_starts_with($path, '/')) {
            throw new InvalidArgumentException(sprintf('Route "%s" path must start with "/".', $name));
        }

        if ($template === '') {
            throw new InvalidArgumentException(sprintf('Route "%s" must declare a logical template.', $name));
        }

        // Every declared parameter must actually appear in the path, and every
        // placeholder in the path must be declared. This is what makes the
        // contract testable rather than merely documented.
        $placeholders = [];
        if (preg_match_all('/\{([a-z][a-zA-Z0-9_]*)\}/', $path, $matches) > 0) {
            $placeholders = $matches[1];
        }

        $declared = array_map(static fn (RouteParameter $p): string => $p->name(), $parameters);

        sort($placeholders);
        sort($declared);

        if ($placeholders !== $declared) {
            throw new InvalidArgumentException(sprintf(
                'Route "%s" declares parameters [%s] but its path uses [%s].',
                $name,
                implode(', ', $declared),
                implode(', ', $placeholders)
            ));
        }

        return new self($name, $path, $methods, $visibility, $dataSource, $template, $parameters);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return non-empty-list<HttpMethod>
     */
    public function methods(): array
    {
        return $this->methods;
    }

    /**
     * @return non-empty-list<string>
     */
    public function methodNames(): array
    {
        /** @var non-empty-list<string> $names */
        $names = array_map(static fn (HttpMethod $m): string => $m->value, $this->methods);

        return $names;
    }

    public function accepts(HttpMethod $method): bool
    {
        return in_array($method, $this->methods, true);
    }

    public function visibility(): Visibility
    {
        return $this->visibility;
    }

    public function dataSource(): DataSource
    {
        return $this->dataSource;
    }

    public function template(): string
    {
        return $this->template;
    }

    /**
     * @return list<RouteParameter>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    public function isDynamic(): bool
    {
        return $this->parameters !== [];
    }

    /**
     * Builds a concrete URL path from parameter values.
     *
     * @param array<string, string> $values
     *
     * @throws InvalidArgumentException when a value is missing or malformed
     */
    public function toPath(array $values = []): string
    {
        $path = $this->path;

        foreach ($this->parameters as $parameter) {
            $name = $parameter->name();

            if (!isset($values[$name])) {
                throw new InvalidArgumentException(sprintf(
                    'Route "%s" requires parameter "%s".',
                    $this->name,
                    $name
                ));
            }

            if (!$parameter->accepts($values[$name])) {
                throw new InvalidArgumentException(sprintf(
                    'Value "%s" is not acceptable for parameter "%s" of route "%s".',
                    $values[$name],
                    $name,
                    $this->name
                ));
            }

            $path = str_replace($parameter->placeholder(), $values[$name], $path);
        }

        return $path;
    }
}
