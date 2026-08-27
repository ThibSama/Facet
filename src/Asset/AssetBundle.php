<?php

declare(strict_types=1);

namespace Facet\Asset;

/**
 * The exact set of asset URLs one rendered document may reference.
 *
 * Immutable and closed: a template can only emit what the resolver put here, so
 * "an unselected skin's assets never load" is a property of this object rather
 * than a convention templates are trusted to follow.
 */
final class AssetBundle
{
    /** @var list<string> */
    private array $scripts;

    /** @var list<string> */
    private array $styles;

    /** @var list<string> */
    private array $entrypoints;

    /** @var list<string> */
    private array $missing;

    /**
     * @param list<string> $scripts
     * @param list<string> $styles
     * @param list<string> $entrypoints
     * @param list<string> $missing
     */
    public function __construct(array $scripts, array $styles, array $entrypoints, array $missing = [])
    {
        $this->scripts = $scripts;
        $this->styles = $styles;
        $this->entrypoints = $entrypoints;
        $this->missing = $missing;
    }

    public static function empty(): self
    {
        return new self([], [], [], []);
    }

    /**
     * @return list<string>
     */
    public function scripts(): array
    {
        return $this->scripts;
    }

    /**
     * @return list<string>
     */
    public function styles(): array
    {
        return $this->styles;
    }

    /**
     * Manifest keys that were requested and resolved, in load order.
     *
     * @return list<string>
     */
    public function entrypoints(): array
    {
        return $this->entrypoints;
    }

    /**
     * Requested keys the manifest did not contain — a stale or partial build.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return $this->missing;
    }

    public function isEmpty(): bool
    {
        return $this->scripts === [] && $this->styles === [];
    }

    /**
     * @return list<string>
     */
    public function urls(): array
    {
        return array_values(array_merge($this->styles, $this->scripts));
    }

    public function references(string $url): bool
    {
        return in_array($url, $this->urls(), true);
    }
}
