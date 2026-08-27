<?php

declare(strict_types=1);

namespace Facet\Content;

use Facet\Support\Slug;

/**
 * One education, professional or volunteer entry.
 *
 * The kind is explicit so that a study programme can never be presented as
 * employment.
 */
final class Experience implements TextualEntry
{
    private Slug $slug;

    private ExperienceKind $kind;

    private string $title;

    private string $organisation;

    private string $location;

    private Period $period;

    private string $summary;

    /** @var list<string> */
    private array $highlights;

    /** @var list<Link> */
    private array $links;

    /**
     * @param list<string> $highlights
     * @param list<Link>   $links
     */
    private function __construct(
        Slug $slug,
        ExperienceKind $kind,
        string $title,
        string $organisation,
        string $location,
        Period $period,
        string $summary,
        array $highlights,
        array $links
    ) {
        $this->slug = $slug;
        $this->kind = $kind;
        $this->title = $title;
        $this->organisation = $organisation;
        $this->location = $location;
        $this->period = $period;
        $this->summary = $summary;
        $this->highlights = $highlights;
        $this->links = $links;
    }

    /**
     * @param list<string> $highlights
     * @param list<Link>   $links
     */
    public static function create(
        Slug $slug,
        ExperienceKind $kind,
        string $title,
        string $organisation,
        string $location,
        Period $period,
        string $summary,
        array $highlights = [],
        array $links = []
    ): self {
        return new self(
            $slug,
            $kind,
            $title,
            $organisation,
            $location,
            $period,
            $summary,
            $highlights,
            $links
        );
    }

    public function slug(): Slug
    {
        return $this->slug;
    }

    public function kind(): ExperienceKind
    {
        return $this->kind;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function organisation(): string
    {
        return $this->organisation;
    }

    public function location(): string
    {
        return $this->location;
    }

    public function period(): Period
    {
        return $this->period;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    /**
     * @return list<string>
     */
    public function highlights(): array
    {
        return $this->highlights;
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
        $fragments = [$this->title, $this->organisation, $this->location, $this->summary];

        foreach ($this->highlights as $highlight) {
            $fragments[] = $highlight;
        }

        foreach ($this->links as $link) {
            $fragments[] = $link->label();
        }

        return $fragments;
    }
}
