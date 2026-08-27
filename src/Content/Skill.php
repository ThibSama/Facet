<?php

declare(strict_types=1);

namespace Facet\Content;

use Facet\Support\Slug;

/**
 * One skill, technology or certification.
 *
 * Presentation-neutral: no logo path, no colour, no badge. A skin decides how
 * to depict a skill from its category and name alone.
 */
final class Skill implements TextualEntry
{
    private Slug $slug;

    private string $name;

    private SkillCategory $category;

    private string $summary;

    /** @var list<Link> */
    private array $links;

    /**
     * @param list<Link> $links
     */
    private function __construct(
        Slug $slug,
        string $name,
        SkillCategory $category,
        string $summary,
        array $links
    ) {
        $this->slug = $slug;
        $this->name = $name;
        $this->category = $category;
        $this->summary = $summary;
        $this->links = $links;
    }

    /**
     * @param list<Link> $links
     */
    public static function create(
        Slug $slug,
        string $name,
        SkillCategory $category,
        string $summary,
        array $links = []
    ): self {
        return new self($slug, $name, $category, $summary, $links);
    }

    public function slug(): Slug
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function category(): SkillCategory
    {
        return $this->category;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    /**
     * @return list<Link>
     */
    public function links(): array
    {
        return $this->links;
    }

    public function textFragments(): array
    {
        $fragments = [$this->name, $this->summary];

        foreach ($this->links as $link) {
            $fragments[] = $link->label();
        }

        return $fragments;
    }
}
