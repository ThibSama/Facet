<?php

declare(strict_types=1);

namespace Facet\Content;

use Facet\Support\Slug;

/**
 * One portfolio project.
 *
 * Carries the full editorial payload a project page needs: what it is
 * (summary), why it exists (context), what the author did (role), what it is
 * built with (technologies), what ideas it demonstrates (concepts), where it
 * stands (status and outcomes), where to see it (links) and an optional
 * illustration.
 *
 * `technologies` and `concepts` are separate on purpose: "PostgreSQL" is a
 * tool, "event sourcing" is an idea, and conflating them makes both unusable
 * for filtering.
 *
 * Nothing here is skin-specific — no ordering weight tied to a layout, no
 * colour, no template name. `featured` is editorial priority, which every
 * presentation is free to interpret or ignore.
 */
final class Project implements TextualEntry
{
    private Slug $slug;

    private string $name;

    private string $summary;

    private string $context;

    private string $role;

    /** @var list<string> */
    private array $technologies;

    /** @var list<string> */
    private array $concepts;

    private ProjectStatus $status;

    /** @var list<string> */
    private array $outcomes;

    private ?Period $period;

    /** @var list<Link> */
    private array $links;

    private Media $media;

    private bool $featured;

    /**
     * @param list<string> $technologies
     * @param list<string> $concepts
     * @param list<string> $outcomes
     * @param list<Link>   $links
     */
    private function __construct(
        Slug $slug,
        string $name,
        string $summary,
        string $context,
        string $role,
        array $technologies,
        array $concepts,
        ProjectStatus $status,
        array $outcomes,
        ?Period $period,
        array $links,
        Media $media,
        bool $featured
    ) {
        $this->slug = $slug;
        $this->name = $name;
        $this->summary = $summary;
        $this->context = $context;
        $this->role = $role;
        $this->technologies = $technologies;
        $this->concepts = $concepts;
        $this->status = $status;
        $this->outcomes = $outcomes;
        $this->period = $period;
        $this->links = $links;
        $this->media = $media;
        $this->featured = $featured;
    }

    /**
     * @param list<string> $technologies
     * @param list<string> $concepts
     * @param list<string> $outcomes
     * @param list<Link>   $links
     */
    public static function create(
        Slug $slug,
        string $name,
        string $summary,
        string $context,
        string $role,
        array $technologies,
        array $concepts,
        ProjectStatus $status,
        array $outcomes,
        ?Period $period,
        array $links,
        Media $media,
        bool $featured
    ): self {
        return new self(
            $slug,
            $name,
            $summary,
            $context,
            $role,
            $technologies,
            $concepts,
            $status,
            $outcomes,
            $period,
            $links,
            $media,
            $featured
        );
    }

    public function slug(): Slug
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function context(): string
    {
        return $this->context;
    }

    public function role(): string
    {
        return $this->role;
    }

    /**
     * Concrete tools, languages and services.
     *
     * @return list<string>
     */
    public function technologies(): array
    {
        return $this->technologies;
    }

    /**
     * Architectural or domain ideas the project demonstrates.
     *
     * @return list<string>
     */
    public function concepts(): array
    {
        return $this->concepts;
    }

    public function status(): ProjectStatus
    {
        return $this->status;
    }

    /**
     * Factual results — never metrics that cannot be substantiated.
     *
     * @return list<string>
     */
    public function outcomes(): array
    {
        return $this->outcomes;
    }

    /**
     * Null when no date can be substantiated — several archived study projects
     * survive only as titles in the previous portfolio, and inventing a period
     * for them would be inventing a fact.
     */
    public function period(): ?Period
    {
        return $this->period;
    }

    /**
     * @return list<Link>
     */
    public function links(): array
    {
        return $this->links;
    }

    public function media(): Media
    {
        return $this->media;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function textFragments(): array
    {
        $fragments = [$this->name, $this->summary, $this->context, $this->role];

        foreach ($this->technologies as $technology) {
            $fragments[] = $technology;
        }

        foreach ($this->concepts as $concept) {
            $fragments[] = $concept;
        }

        foreach ($this->outcomes as $outcome) {
            $fragments[] = $outcome;
        }

        foreach ($this->links as $link) {
            $fragments[] = $link->label();
        }

        $fragments[] = $this->media->description();

        return $fragments;
    }
}
