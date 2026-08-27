<?php

declare(strict_types=1);

namespace Facet\Routing;

use Facet\Support\Slug;

/**
 * A dynamic segment of a route path.
 *
 * The slug parameter reuses {@see Slug::PATTERN} rather than re-declaring a
 * grammar, so a URL that routing accepts is exactly a URL the corpus can
 * resolve.
 */
final class RouteParameter
{
    private string $name;

    private string $pattern;

    private function __construct(string $name, string $pattern)
    {
        $this->name = $name;
        $this->pattern = $pattern;
    }

    public static function slug(string $name = 'slug'): self
    {
        return new self($name, Slug::PATTERN);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function pattern(): string
    {
        return $this->pattern;
    }

    public function placeholder(): string
    {
        return '{' . $this->name . '}';
    }

    public function accepts(string $value): bool
    {
        return preg_match('/^' . $this->pattern . '$/', $value) === 1;
    }
}
