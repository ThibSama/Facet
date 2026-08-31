<?php

declare(strict_types=1);

namespace Facet\Seo;

use Facet\I18n\Locale;

/** Skin-neutral metadata consumed by the one shared document layout. */
final class SeoMetadata
{
    /**
     * @param list<array<string, mixed>> $structuredData
     * @param array<string, string>      $alternates hreflang value => absolute URL
     */
    public function __construct(
        private string $title,
        private ?string $description,
        private ?string $canonicalUrl,
        private bool $indexable,
        private string $openGraphType = 'website',
        private array $structuredData = [],
        private Locale $locale = Locale::Fr,
        private array $alternates = []
    ) {
    }

    public function locale(): Locale
    {
        return $this->locale;
    }

    /**
     * The `hreflang` alternates of this page, keyed by their hreflang value.
     *
     * Includes `x-default`, which points at the French URL: French is the
     * language the corpus is written in and the language an unprefixed entry
     * route falls back to, so it is the deterministic answer to "this page,
     * language unspecified" rather than a duplicate of one of the two.
     *
     * Empty on any page that is not an indexable, paired public page — an error
     * document has no counterpart to advertise.
     *
     * @return array<string, string>
     */
    public function alternates(): array
    {
        return $this->alternates;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function canonicalUrl(): ?string
    {
        return $this->canonicalUrl;
    }

    public function isIndexable(): bool
    {
        return $this->indexable;
    }

    public function openGraphType(): string
    {
        return $this->openGraphType;
    }

    public function hasSocialGraph(): bool
    {
        return $this->indexable && $this->description !== null && $this->canonicalUrl !== null;
    }

    /** @return list<array<string, mixed>> */
    public function structuredData(): array
    {
        return $this->structuredData;
    }
}
