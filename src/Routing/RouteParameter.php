<?php

declare(strict_types=1);

namespace Facet\Routing;

use Facet\Support\Slug;

/**
 * A dynamic segment of a route path.
 *
 * The slug parameter reuses the {@see Slug} contract rather than re-declaring a
 * grammar, so a URL that routing accepts is exactly a URL the corpus can
 * resolve. The pattern alone is not that contract — length bounds live in
 * {@see Slug::isValid()} — so a parameter carries an optional validator
 * alongside its pattern and a caller checks both through {@see self::accepts()}.
 */
final class RouteParameter
{
    private string $name;

    private string $pattern;

    /** @var (callable(string): bool)|null */
    private $validator;

    /**
     * @param (callable(string): bool)|null $validator
     */
    private function __construct(string $name, string $pattern, ?callable $validator = null)
    {
        $this->name = $name;
        $this->pattern = $pattern;
        $this->validator = $validator;
    }

    public static function slug(string $name = 'slug'): self
    {
        return new self($name, Slug::PATTERN, Slug::isValid(...));
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

    /**
     * A value is acceptable only when it satisfies the shape *and* the full
     * contract behind it. Checking the pattern alone would let a URL route that
     * the canonical value object would then refuse to construct.
     */
    public function accepts(string $value): bool
    {
        if (preg_match('/^' . $this->pattern . '$/', $value) !== 1) {
            return false;
        }

        return $this->validator === null || ($this->validator)($value);
    }
}
