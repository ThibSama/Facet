<?php

declare(strict_types=1);

namespace Facet\Seo;

/** Skin-neutral metadata consumed by the one shared document layout. */
final class SeoMetadata
{
    public function __construct(
        private string $title,
        private ?string $description,
        private ?string $canonicalUrl,
        private bool $indexable,
        private string $openGraphType = 'website',
        /** @var list<array<string, mixed>> */
        private array $structuredData = []
    ) {
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
