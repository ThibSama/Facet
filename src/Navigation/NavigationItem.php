<?php

declare(strict_types=1);

namespace Facet\Navigation;

/**
 * One entry of a rendered navigation list.
 *
 * It carries the three things a template needs and nothing more: where the
 * link points, what it is called, and whether the current request is inside
 * it. "Inside" rather than "equal to" is the whole point — a project detail
 * URL is still within Projects, and the shell has to say so.
 */
final class NavigationItem
{
    private string $routeName;

    private string $label;

    private string $href;

    private bool $current;

    private function __construct(string $routeName, string $label, string $href, bool $current)
    {
        $this->routeName = $routeName;
        $this->label = $label;
        $this->href = $href;
        $this->current = $current;
    }

    public static function create(string $routeName, string $label, string $href, bool $current): self
    {
        return new self($routeName, $label, $href, $current);
    }

    public function routeName(): string
    {
        return $this->routeName;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function href(): string
    {
        return $this->href;
    }

    /**
     * Whether the request being rendered belongs to this section.
     */
    public function isCurrent(): bool
    {
        return $this->current;
    }

    /**
     * The `aria-current` value a template should emit, or null when the item is
     * not the current section. Returning null is deliberate: {@see
     * \Facet\Html\ViewContext::attributes()} drops null attributes, so a
     * template never has to write the conditional itself.
     */
    public function ariaCurrent(): ?string
    {
        return $this->current ? 'page' : null;
    }
}
