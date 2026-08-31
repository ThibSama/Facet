<?php

declare(strict_types=1);

namespace Facet\Routing;

use Facet\I18n\Locale;
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

    /**
     * The public language segment of every canonical public URL.
     *
     * It reuses {@see Locale} as its contract for the same reason the slug
     * parameter reuses {@see Slug}: the set of segments the router accepts and
     * the set of locales the site can render must be one set. That is what
     * makes `/de/projects` a 404 rather than a French page served under a
     * German-looking URL — the segment is refused at routing, so nothing
     * downstream ever has to decide what `de` might have meant.
     */
    public static function locale(string $name = 'locale'): self
    {
        return new self($name, '[a-z]{2}', static fn (string $value): bool => Locale::fromSegment($value) !== null);
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
