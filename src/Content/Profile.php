<?php

declare(strict_types=1);

namespace Facet\Content;

/**
 * The single identity record behind the site.
 *
 * Contact details that belong to a private channel are deliberately absent:
 * the contact route owns message submission, and the corpus stays publishable
 * as-is.
 */
final class Profile implements TextualEntry
{
    private string $name;

    private string $headline;

    private string $location;

    private string $summary;

    /** @var list<string> */
    private array $focusAreas;

    /** @var list<Link> */
    private array $links;

    private Media $portrait;

    /**
     * @param list<string> $focusAreas
     * @param list<Link>   $links
     */
    private function __construct(
        string $name,
        string $headline,
        string $location,
        string $summary,
        array $focusAreas,
        array $links,
        Media $portrait
    ) {
        $this->name = $name;
        $this->headline = $headline;
        $this->location = $location;
        $this->summary = $summary;
        $this->focusAreas = $focusAreas;
        $this->links = $links;
        $this->portrait = $portrait;
    }

    /**
     * @param list<string> $focusAreas
     * @param list<Link>   $links
     */
    public static function create(
        string $name,
        string $headline,
        string $location,
        string $summary,
        array $focusAreas,
        array $links,
        Media $portrait
    ): self {
        return new self($name, $headline, $location, $summary, $focusAreas, $links, $portrait);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function headline(): string
    {
        return $this->headline;
    }

    public function location(): string
    {
        return $this->location;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    /**
     * @return list<string>
     */
    public function focusAreas(): array
    {
        return $this->focusAreas;
    }

    /**
     * @return list<Link>
     */
    public function links(): array
    {
        return $this->links;
    }

    public function portrait(): Media
    {
        return $this->portrait;
    }

    public function textFragments(): array
    {
        $fragments = [$this->name, $this->headline, $this->location, $this->summary];

        foreach ($this->focusAreas as $area) {
            $fragments[] = $area;
        }

        foreach ($this->links as $link) {
            $fragments[] = $link->label();
        }

        $fragments[] = $this->portrait->description();

        return $fragments;
    }
}
